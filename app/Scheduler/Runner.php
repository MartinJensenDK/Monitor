<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Checks\BatchableChecker;
use App\Checks\CheckerFactory;
use App\Checks\CheckResult;
use App\Checks\PingChecker;
use App\Checks\PingTransport;
use App\Checks\PortChecker;
use App\Checks\TargetGuard;
use App\Checks\TcpProbe;
use App\Core\Db;
use App\Domain\Incidents;
use App\Domain\Settings;
use App\Notifications\Dispatcher;
use CurlHandle;
use Throwable;

/**
 * Runs the checks that are due. Started once a minute by cron; inside that
 * minute it ticks every few seconds so intervals shorter than a minute still
 * work without a long-running daemon.
 */
final class Runner
{
    public const LOCK = 'monitor:scheduler';

    private int $checked = 0;

    public function __construct(
        private readonly bool $verbose = false,
        private readonly bool $dryRun = false
    ) {
    }

    /** Run ticks until the deadline, then hand the minute back to cron. */
    public function loop(int $seconds = 55, int $everySeconds = 5): int
    {
        $deadline = time() + $seconds;

        do {
            $started = microtime(true);
            $this->tick();
            $elapsed = microtime(true) - $started;

            $sleep = $everySeconds - $elapsed;
            if ($sleep > 0 && time() + $sleep < $deadline) {
                usleep((int) ($sleep * 1_000_000));
            }
        } while (time() < $deadline);

        return $this->checked;
    }

    /** One pass over everything that is due right now. */
    public function tick(?int $onlyMonitorId = null): int
    {
        $monitors = $this->claimDue($onlyMonitorId);
        if ($monitors === []) {
            return 0;
        }

        $this->log(sprintf('Running %d check(s)', count($monitors)));

        $results = $this->runWithRetries($monitors);

        foreach ($monitors as $monitor) {
            $id = (int) $monitor['id'];
            if (!isset($results[$id])) {
                continue;
            }
            [$result, $attempts] = $results[$id];

            if ($this->dryRun) {
                $this->log(sprintf(
                    '  [dry run] %-28s %-8s %s',
                    $monitor['name'],
                    strtoupper($result->status),
                    $result->summary()
                ));
                continue;
            }

            try {
                $this->persist($monitor, $result, $attempts);
            } catch (Throwable $e) {
                $this->log('  ! failed to store result for ' . $monitor['name'] . ': ' . $e->getMessage());
            }

            $this->checked++;
            $this->log(sprintf(
                '  %-28s %-8s %6s  %s',
                mb_strimwidth((string) $monitor['name'], 0, 28, '…'),
                strtoupper($result->status),
                $result->responseMs === null ? '—' : $result->responseMs . 'ms',
                $result->status === 'up' ? '' : $result->summary()
            ));
        }

        return count($monitors);
    }

    /**
     * Take the due monitors and immediately push their next run forward, so a
     * second scheduler process (or a slow tick) cannot pick up the same work.
     *
     * @return array<int,array<string,mixed>>
     */
    private function claimDue(?int $onlyMonitorId): array
    {
        $limit = max(1, min(200, Settings::int('check_concurrency', 20) * 3));

        $sql = 'SELECT m.*, s.`status` AS current_status, s.`consecutive_failures`, s.`current_incident_id`
                FROM {{monitors}} m
                JOIN {{monitor_status}} s ON s.`monitor_id` = m.`id`
                WHERE m.`enabled` = 1 ';
        $params = [];

        if ($onlyMonitorId !== null) {
            $sql .= 'AND m.`id` = :id ';
            $params['id'] = $onlyMonitorId;
        } else {
            $sql .= 'AND (s.`next_check_at` IS NULL OR s.`next_check_at` <= UTC_TIMESTAMP()) ';
        }

        $sql .= 'ORDER BY s.`next_check_at` IS NULL DESC, s.`next_check_at` LIMIT ' . $limit;

        $monitors = Db::select($sql, $params);
        if ($monitors === [] || $this->dryRun) {
            return $monitors;
        }

        $ids = implode(',', array_map(static fn (array $m): int => (int) $m['id'], $monitors));
        Db::execute(
            'UPDATE {{monitor_status}} s
             JOIN {{monitors}} m ON m.`id` = s.`monitor_id`
             SET s.`next_check_at` = DATE_ADD(UTC_TIMESTAMP(), INTERVAL m.`interval_seconds` SECOND)
             WHERE s.`monitor_id` IN (' . $ids . ')'
        );

        return $monitors;
    }

    /**
     * A failing check is repeated immediately, up to the monitor's retry count.
     * Only the last attempt is stored, so a blip never opens an incident.
     *
     * @param array<int,array<string,mixed>> $monitors
     * @return array<int,array{0:CheckResult,1:int}>
     */
    private function runWithRetries(array $monitors): array
    {
        $results = [];
        $pending = $monitors;
        $attempt = 1;

        while ($pending !== []) {
            $batch = $this->runBatch($pending);
            $retry = [];

            foreach ($pending as $monitor) {
                $id = (int) $monitor['id'];
                $result = $batch[$id] ?? CheckResult::down('internal', 'The check did not run.');
                $results[$id] = [$result, $attempt];

                if ($result->status === 'down' && $attempt <= (int) $monitor['retries']) {
                    $retry[] = $monitor;
                }
            }

            $pending = $retry;
            $attempt++;

            if ($pending !== []) {
                usleep(500_000);
            }
        }

        return $results;
    }

    /**
     * Split a tick's work by how it is best run: website and endpoint checks go
     * out together through curl_multi, port and TCP-ping checks through one
     * parallel connect, and anything left over runs one at a time.
     *
     * @param array<int,array<string,mixed>> $monitors
     * @return array<int,CheckResult>
     */
    private function runBatch(array $monitors): array
    {
        $results = [];
        $curl = [];
        $tcp = [];
        $sequential = [];
        $pingTransport = null;

        foreach ($monitors as $monitor) {
            $id = (int) $monitor['id'];
            $type = (string) $monitor['type'];

            if (!CheckerFactory::supports($type)) {
                $results[$id] = CheckResult::down('internal', 'No checker is registered for monitor type "' . $type . '".');
                continue;
            }

            $checker = CheckerFactory::for($type);

            if ($checker instanceof BatchableChecker) {
                $curl[$id] = ['monitor' => $monitor, 'checker' => $checker];
                continue;
            }

            if ($type === 'ping') {
                $pingTransport ??= PingTransport::detect();
                if ($pingTransport !== PingTransport::TCP) {
                    $sequential[$id] = ['monitor' => $monitor, 'checker' => $checker];
                    continue;
                }
            }

            if ($type === 'port' || $type === 'ping') {
                $host = $type === 'port' ? PortChecker::host($monitor) : PingChecker::host($monitor);
                $guard = TargetGuard::check($host);

                if (!$guard['allowed']) {
                    $results[$id] = CheckResult::down('blocked_target', $guard['reason']);
                    continue;
                }

                $tcp[$id] = [
                    'monitor' => $monitor,
                    'type' => $type,
                    'host' => $host,
                    'port' => $type === 'port' ? PortChecker::port($monitor) : PingChecker::fallbackPort($monitor),
                    'timeout' => (float) max(1, (int) $monitor['timeout_seconds']),
                ];
                continue;
            }

            $sequential[$id] = ['monitor' => $monitor, 'checker' => $checker];
        }

        foreach ($this->runCurlBatch($curl) as $id => $result) {
            $results[$id] = $result;
        }

        foreach ($this->runTcpBatch($tcp, $pingTransport ?? PingTransport::TCP) as $id => $result) {
            $results[$id] = $result;
        }

        foreach ($sequential as $id => $entry) {
            try {
                $results[$id] = $entry['checker']->run($entry['monitor']);
            } catch (Throwable $e) {
                $results[$id] = CheckResult::down('internal', $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Every website and endpoint check in one curl_multi run, so 50 monitors
     * take as long as the slowest one rather than the sum of all of them.
     *
     * @param array<int,array{monitor:array<string,mixed>,checker:BatchableChecker}> $entries
     * @return array<int,CheckResult>
     */
    private function runCurlBatch(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $results = [];
        $concurrency = max(1, Settings::int('check_concurrency', 20));
        $batch = array_slice($entries, 0, $concurrency, true);
        $overflow = array_slice($entries, $concurrency, null, true);

        $multi = curl_multi_init();
        /** @var array<int,array{monitor:array<string,mixed>,checker:BatchableChecker,handle:CurlHandle}> $handles */
        $handles = [];

        foreach ($batch as $id => $entry) {
            $prepared = $entry['checker']->prepare($entry['monitor']);
            if ($prepared instanceof CheckResult) {
                $results[$id] = $prepared;
                continue;
            }
            $handles[$id] = $entry + ['handle' => $prepared];
            curl_multi_add_handle($multi, $prepared);
        }

        if ($handles !== []) {
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 0.5);
                }
            } while ($running > 0 && $status === CURLM_OK);

            // Each handle's transfer result only comes back through the info
            // queue; without it a DNS failure would look like a 0 status code.
            $errors = [];
            while (($message = curl_multi_info_read($multi)) !== false) {
                $errors[spl_object_id($message['handle'])] = (int) $message['result'];
            }

            foreach ($handles as $id => $entry) {
                $body = (string) curl_multi_getcontent($entry['handle']);
                $errno = $errors[spl_object_id($entry['handle'])] ?? curl_errno($entry['handle']);
                $results[$id] = $entry['checker']->finish($entry['monitor'], $entry['handle'], $body, $errno);
                curl_multi_remove_handle($multi, $entry['handle']);
                curl_close($entry['handle']);
            }
        }

        curl_multi_close($multi);

        // Anything past the concurrency limit runs on its own.
        foreach ($overflow as $id => $entry) {
            try {
                $results[$id] = $entry['checker']->run($entry['monitor']);
            } catch (Throwable $e) {
                $results[$id] = CheckResult::down('internal', $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Port checks, and ping checks on a server that cannot send ICMP, share one
     * parallel connect round.
     *
     * @param array<int,array{monitor:array<string,mixed>,type:string,host:string,port:int,timeout:float}> $entries
     * @return array<int,CheckResult>
     */
    private function runTcpBatch(array $entries, string $pingTransport): array
    {
        if ($entries === []) {
            return [];
        }

        $targets = [];
        foreach ($entries as $id => $entry) {
            $targets[$id] = ['host' => $entry['host'], 'port' => $entry['port'], 'timeout' => $entry['timeout']];
        }

        $results = [];
        foreach (TcpProbe::connectMany($targets) as $id => $outcome) {
            $entry = $entries[$id];
            $results[$id] = $entry['type'] === 'port'
                ? PortChecker::toCheckResult($entry['monitor'], $outcome)
                : PingChecker::toCheckResult($entry['monitor'], $outcome, $pingTransport);
        }

        return $results;
    }

    /** @param array<string,mixed> $monitor */
    private function persist(array $monitor, CheckResult $result, int $attempts): void
    {
        $monitorId = (int) $monitor['id'];
        $now = gmdate('Y-m-d H:i:s');
        $previous = (string) ($monitor['current_status'] ?? 'pending');

        Db::insert('checks', [
            'monitor_id' => $monitorId,
            'checked_at' => $now,
            'status' => $result->status,
            'response_ms' => $result->responseMs,
            'connect_ms' => $result->connectMs,
            'http_code' => $result->httpCode,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage === null ? null : mb_substr($result->errorMessage, 0, 500),
            'attempt' => $attempts,
            'meta' => $result->meta === [] ? null : json_encode($result->meta, JSON_UNESCAPED_SLASHES),
        ]);

        Rollup::minute($monitorId, $now);

        $incidentId = $monitor['current_incident_id'] === null ? null : (int) $monitor['current_incident_id'];

        $failures = (int) $monitor['consecutive_failures'] + 1;

        if ($result->status === 'down') {
            if ($previous !== 'down' || $incidentId === null) {
                $incidentId = Incidents::open($monitorId, (string) $result->errorCode, (string) $result->errorMessage);
                Dispatcher::monitorWentDown($monitor, $result, $incidentId, $failures);
            } else {
                Incidents::recordFailure($incidentId, (string) $result->errorMessage);

                // Below the threshold at the time it opened, or past the resend
                // interval — either way the channel may still be owed an email.
                $incident = Incidents::find($incidentId);
                if ($incident !== null) {
                    Dispatcher::monitorWentDown($monitor, $result, $incidentId, $failures);
                    Dispatcher::incidentStillOpen($monitor, $incident);
                }
            }
        } else {
            if ($previous === 'down') {
                $open = Incidents::openFor($monitorId);
                $downtime = $open === null
                    ? 0
                    : max(0, time() - (strtotime((string) $open['started_at'] . ' UTC') ?: time()));

                Incidents::resolveOpen($monitorId);
                Dispatcher::monitorRecovered($monitor, $result, $open === null ? $incidentId : (int) $open['id'], $downtime);
                $incidentId = null;
            }
            if ($result->status === 'degraded' && $previous !== 'degraded') {
                Dispatcher::monitorDegraded($monitor, $result);
            }
        }

        $statusChanged = $previous !== $result->status;

        $fields = [
            'status' => $result->status,
            'last_check_at' => $now,
            'next_check_at' => gmdate('Y-m-d H:i:s', time() + (int) $monitor['interval_seconds']),
            'last_response_ms' => $result->responseMs,
            'last_http_code' => $result->httpCode,
            'last_error' => $result->errorMessage === null ? null : mb_substr($result->errorMessage, 0, 500),
            'consecutive_failures' => $result->status === 'down' ? ((int) $monitor['consecutive_failures'] + 1) : 0,
            'consecutive_successes' => $result->status === 'down' ? 0 : null,
            'current_incident_id' => $incidentId,
            'updated_at' => $now,
        ];

        if ($fields['consecutive_successes'] === null) {
            unset($fields['consecutive_successes']);
            Db::execute(
                'UPDATE {{monitor_status}} SET `consecutive_successes` = `consecutive_successes` + 1 WHERE `monitor_id` = ?',
                [$monitorId]
            );
        }

        if ($statusChanged) {
            $fields['status_since'] = $now;
        }

        if (isset($result->meta['cert_expires_at'])) {
            $fields['cert_expires_at'] = (string) $result->meta['cert_expires_at'];
            $fields['cert_issuer'] = (string) ($result->meta['cert_issuer'] ?? '');
        }

        Db::update('monitor_status', $fields, ['monitor_id' => $monitorId]);

        Rollup::refreshUptime($monitorId, (int) gmdate('i') % 15 === 0 || $statusChanged);
    }

    private function log(string $line): void
    {
        if ($this->verbose) {
            fwrite(STDOUT, gmdate('H:i:s') . ' ' . $line . PHP_EOL);
        }
    }

    public function checkedCount(): int
    {
        return $this->checked;
    }
}
