<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Agent\AgentAuth;
use App\Agent\Enrolment;
use App\Agent\Ingest;
use App\Agent\Payload;
use App\Agent\Scripts;
use App\Core\App;
use App\Core\Config;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Domain\AgentPolicy;
use App\Domain\AuditLog;
use App\Domain\DeviceCommands;
use App\Domain\DeviceLogs;
use App\Domain\Devices;
use App\Domain\EnrollmentKeys;

/**
 * The two endpoints the agent talks to.
 *
 * This is the only part of the application a machine outside can reach without
 * a person behind it, so it is written to be dull. There is no session and no
 * CSRF token -- authentication is a bearer token, and a token is not something
 * a browser can be tricked into attaching to a cross-site request. Everything
 * that arrives goes through Payload before it is looked at. Every refusal says
 * the same thing regardless of why, so probing here tells you nothing you did
 * not already know.
 */
final class AgentApiController extends Controller
{
    /**
     * How many enrolment attempts one address gets in the window. The key
     * itself is 192 random bits, so this is not what stops a guess -- it stops
     * somebody using this endpoint as a way to make the database work.
     */
    private const ENROL_ATTEMPTS = 30;
    private const ENROL_WINDOW_MINUTES = 15;

    /** How much of a body that could not be read is kept, so one machine cannot fill a disk. */
    private const KEEP_UNREADABLE = 1048576;

    /**
     * POST /api/agent/enroll
     *
     * Spends an enrolment key and returns a token for this machine alone.
     */
    public function enroll(Request $request): Response
    {
        if (!Devices::isReady()) {
            return $this->fail(503, 'agent_support_missing');
        }

        $guard = $this->guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $ip = $request->ip();
        if ($this->throttled($ip)) {
            return $this->fail(429, 'slow_down');
        }

        $key = EnrollmentKeys::redeem(AgentAuth::presented($request));
        if ($key === null) {
            $this->recordAttempt($ip, false);

            // Deliberately the same answer for a key that never existed, one
            // that expired, one that was revoked and one that is used up. The
            // machine cannot act on the difference and an attacker should not
            // learn it.
            return $this->fail(401, 'enrollment_refused');
        }

        $payload = Payload::normalise($request->all());
        $result = Enrolment::create($key, $payload, $ip);

        if ($result === null) {
            $this->recordAttempt($ip, false);

            return $this->fail(401, 'enrollment_refused');
        }

        $this->recordAttempt($ip, true);

        AuditLog::record(
            'device.enrolled',
            'device',
            (int) $result['device']['id'],
            sprintf('%s enrolled from %s on key "%s"', $result['device']['name'], $ip, $key['name'])
        );

        return Response::json([
            'ok' => true,
            'device_id' => $result['device']['uuid'],
            // The one time this is ever sent. It is not recoverable from the
            // site afterwards; a machine that loses it has to enrol again.
            'token' => $result['token'],
            'name' => $result['device']['name'],
            'kind' => $result['device']['kind'],
            'interval' => (int) $result['device']['interval_seconds'],
        ], 201);
    }

    /**
     * POST /api/agent/report
     *
     * A machine says how it is, collects whatever was queued for it, and hands
     * back the results of what it collected last time.
     */
    public function report(Request $request): Response
    {
        if (!Devices::isReady()) {
            return $this->fail(503, 'agent_support_missing');
        }

        $guard = $this->guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $device = AgentAuth::device(AgentAuth::presented($request));
        if ($device === null) {
            return $this->fail(401, 'unknown_device');
        }

        $payload = Payload::normalise($request->all());

        // Switched off here. The agent is told so plainly, and stops on its
        // own rather than being cut off without explanation.
        if ((string) $device['status'] === 'disabled') {
            Db::update('devices', ['last_seen_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $device['id']]);

            return Response::json(['ok' => true, 'status' => 'disabled', 'commands' => []]);
        }

        $this->recordResults((int) $device['id'], $payload['results']);
        $this->recordLogs((int) $device['id'], $payload['logs']);

        Ingest::report($device, $payload, $request->ip());

        $commands = $this->mayCommand($device)
            ? DeviceCommands::claim((int) $device['id'])
            : [];

        $fresh = Devices::find((int) $device['id']);

        return Response::json(array_filter([
            'ok' => true,
            'status' => 'online',
            'interval' => (int) ($fresh['interval_seconds'] ?? $device['interval_seconds']),
            'poll' => (int) ($fresh['poll_seconds'] ?? $device['poll_seconds']),
            'level' => (string) ($fresh['log_level'] ?? $device['log_level']),
            'report' => false,
            'agent' => $this->manifest((string) ($fresh['os_family'] ?? $device['os_family'])),
            'commands' => $commands,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * POST /api/agent/poll
     *
     * The live channel. A machine knocks every few seconds to ask whether
     * anything is waiting for it, and this answers in about the length of a
     * sentence.
     *
     * It is deliberately the smallest thing in the application. No report is
     * read, no list is written, and the machine's row is only touched when it
     * is actually stale -- because this runs thousands of times a day per
     * machine, and anything careless here is multiplied by the whole fleet.
     */
    public function poll(Request $request): Response
    {
        if (!Devices::isReady()) {
            return $this->fail(503, 'agent_support_missing');
        }

        $guard = $this->guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $device = AgentAuth::device(AgentAuth::presented($request));
        if ($device === null) {
            return $this->fail(401, 'unknown_device');
        }

        // Switched off here: told plainly, so the agent stops on its own
        // rather than being cut off without explanation.
        if ((string) $device['status'] === 'disabled') {
            return Response::json(['ok' => true, 'status' => 'disabled', 'poll' => 0, 'commands' => []]);
        }

        Devices::touch($device);

        $commands = $this->mayCommand($device)
            ? DeviceCommands::claim((int) $device['id'])
            : [];

        return Response::json(array_filter([
            'ok' => true,
            'status' => 'online',
            // Both cadences come back every time, so a change made in the
            // interface reaches the machine on its next knock rather than
            // waiting out the old schedule.
            'poll' => (int) $device['poll_seconds'],
            'interval' => (int) $device['interval_seconds'],
            'level' => (string) $device['log_level'],
            'report' => Devices::reportDue($device),
            // What the agent should be running. It compares this with its own
            // version and replaces itself if they differ -- which is why the
            // check costs nothing extra: it rides along with a request the
            // agent was making anyway.
            'agent' => $this->manifest((string) $device['os_family']),
            'commands' => $commands,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * POST /api/agent/log
     *
     * Lines the agent wants on the record, sent as they happen rather than
     * held until the next report. This is what makes watching a command run
     * possible: the package manager's output arrives while it is still
     * talking, not once it has finished.
     */
    public function log(Request $request): Response
    {
        if (!Devices::isReady()) {
            return $this->fail(503, 'agent_support_missing');
        }

        $guard = $this->guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $device = AgentAuth::device(AgentAuth::presented($request));
        if ($device === null) {
            return $this->fail(401, 'unknown_device');
        }

        $lines = Payload::logLines($request->raw('logs'));
        $stored = $this->recordLogs((int) $device['id'], $lines);

        // Touched here as well, because a machine part way through a long
        // command is very much alive and should not drift towards "silent"
        // while it works.
        Devices::touch($device);

        return Response::json(['ok' => true, 'stored' => $stored]);
    }

    /**
     * Both locks on running commands, in the order they were fitted: the site
     * switch covers the whole fleet, the machine's own setting covers one.
     * Either one being off is enough.
     *
     * @param array<string,mixed> $device
     */
    private function mayCommand(array $device): bool
    {
        return AgentPolicy::mayRunCommands() && (int) $device['commands_enabled'] === 1;
    }

    /**
     * What the agent should be running, or nothing at all.
     *
     * Withholding the manifest is how updates are switched off site-wide. It
     * is left out of the answer rather than emptied, because every agent
     * already treats an absent offer as "nothing on offer" -- so an install
     * that turns this off does not need its machines updated first.
     *
     * @return array<string,string>|null
     */
    private function manifest(string $osFamily): ?array
    {
        return AgentPolicy::mayOfferUpdates() ? Scripts::manifest($osFamily) : null;
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     */
    private function recordLogs(int $deviceId, array $lines): int
    {
        if ($lines === []) {
            return 0;
        }

        $uuids = [];
        foreach ($lines as $line) {
            if ($line['command'] !== '') {
                $uuids[] = $line['command'];
            }
        }

        return DeviceLogs::record($deviceId, $lines, DeviceCommands::idsByUuid($deviceId, $uuids));
    }

    /**
     * Results the agent carried back for commands it collected earlier.
     *
     * @param array<int,array<string,mixed>> $results
     */
    private function recordResults(int $deviceId, array $results): void
    {
        foreach ($results as $result) {
            $done = DeviceCommands::complete(
                $deviceId,
                (string) $result['id'],
                (bool) $result['ok'],
                (int) $result['exit_code'],
                (string) $result['output'],
                (string) $result['error']
            );

            if ($done && !$result['ok']) {
                Devices::recordEvent(
                    $deviceId,
                    'command_failed',
                    'A queued command came back with an error.',
                    'warning'
                );
            }
        }
    }

    /**
     * The checks both endpoints share: a body of a sane size, and a connection
     * that is encrypted whenever this install is set up to use encryption at
     * all. A device token crossing a plain HTTP hop is a token somebody else
     * now has, so it is refused rather than accepted and regretted.
     */
    private function guard(Request $request): ?Response
    {
        if ($request->contentLength() > Payload::MAX_BODY) {
            return $this->fail(413, 'report_too_large');
        }

        // Said plainly rather than accepted and quietly dropped. A report that
        // cannot be decoded used to arrive here as an empty array, and an empty
        // array is indistinguishable from a machine that reported nothing about
        // itself -- so a single bad byte in one package name would overwrite a
        // machine's hostname, operating system and every list with null.
        if ($request->jsonUnreadable()) {
            $this->recordUnreadable($request);

            return Response::json([
                'ok' => false,
                'error' => 'unreadable_body',
                // Handed back rather than kept here. The machine that sent it
                // is the one that can be fixed, and it is usually not one
                // anybody is sitting at -- so the agent puts this in its own
                // log, where it can be read from the interface.
                'reason' => $request->jsonError(),
            ], 400);
        }

        if (!$request->isSecure() && str_starts_with(Config::string('app.url'), 'https://')) {
            return $this->fail(400, 'https_required');
        }

        return null;
    }

    /**
     * A note in the log, bounded, about a body that could not be read.
     *
     * The machine sending it cannot be asked what it sent -- that is rather the
     * problem -- so enough of it is written down here to tell a truncated
     * document from a mis-encoded one without keeping the whole thing.
     */
    private function recordUnreadable(Request $request): void
    {
        $body = $request->body();

        // The last one is kept whole, overwritten each time and capped, because
        // the ends of a document are exactly where this kind of fault is not.
        // A machine sending 300 KB of inventory that breaks somewhere in the
        // middle cannot be debugged from an excerpt, and it is usually not a
        // machine anybody is sitting at.
        $kept = App::basePath('storage/logs/unreadable-body.json');
        @file_put_contents($kept, substr($body, 0, self::KEEP_UNREADABLE));

        App::logNote(sprintf(
            'agent: unreadable body from %s (%s, %d bytes), kept in storage/logs/unreadable-body.json. Head: %s ... tail: %s',
            $request->ip(),
            $request->jsonError(),
            strlen($body),
            self::readable(substr($body, 0, 200)),
            self::readable(substr($body, -200))
        ));
    }

    /** Printable ASCII kept, everything else shown as the byte it is. */
    private static function readable(string $text): string
    {
        return (string) preg_replace_callback(
            '/[^\x20-\x7e]/',
            static fn (array $m): string => sprintf('\x%02x', ord($m[0])),
            $text
        );
    }

    private function fail(int $status, string $reason): Response
    {
        return Response::json(['ok' => false, 'error' => $reason], $status);
    }

    /**
     * Enrolment attempts are counted in the same table as sign-in attempts,
     * under an identifier no email address can collide with.
     */
    private function throttled(string $ip): bool
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM {{login_attempts}}
             WHERE `identifier` = ? AND `ip` = ? AND `succeeded` = 0
               AND `attempted_at` > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)',
            ['agent:enroll', $ip, self::ENROL_WINDOW_MINUTES]
        ) >= self::ENROL_ATTEMPTS;
    }

    private function recordAttempt(string $ip, bool $succeeded): void
    {
        Db::insert('login_attempts', [
            'identifier' => 'agent:enroll',
            'ip' => $ip,
            'succeeded' => $succeeded ? 1 : 0,
            'attempted_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
