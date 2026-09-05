<?php

declare(strict_types=1);

namespace App\Agent;

use App\Core\Db;
use App\Domain\Devices;

/**
 * Stores one report.
 *
 * The shape is deliberately boring: a row of latest values on the device
 * itself for every list and card to read, one row appended to device_metrics
 * for the charts, and the five lists replaced wholesale. Replacing rather than
 * merging is the only honest option -- a package that was uninstalled has to
 * disappear, and working out which of six thousand rows changed costs more
 * than writing them again.
 *
 * The whole thing runs in one transaction, so a machine is never half updated:
 * either the report landed or it did not.
 */
final class Ingest
{
    /** Rows per INSERT when replacing a list. Keeps a six-thousand-package machine to thirty statements. */
    private const CHUNK = 200;

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed> $payload already through Payload::normalise()
     */
    public static function report(array $device, array $payload, string $ip): void
    {
        $deviceId = (int) $device['id'];
        $now = gmdate('Y-m-d H:i:s');
        $system = $payload['system'];
        $metrics = $payload['metrics'];
        $updates = $payload['updates'];
        $collected = $payload['collected'];

        $diskTotal = null;
        $diskUsed = null;
        if (in_array('disks', $collected, true)) {
            $diskTotal = 0;
            $diskUsed = 0;
            foreach ($payload['disks'] as $disk) {
                $diskTotal += (int) $disk['total_bytes'];
                $diskUsed += (int) $disk['used_bytes'];
            }
        }

        $bootAt = $system['uptime_seconds'] === null
            ? ($device['boot_at'] === null ? null : (string) $device['boot_at'])
            : gmdate('Y-m-d H:i:s', time() - $system['uptime_seconds']);

        $columns = [
            'hostname' => $system['hostname'],
            'fqdn' => $system['fqdn'],
            'agent_kind' => $system['kind'],
            'os_family' => $system['os_family'],
            'os_name' => $system['os_name'],
            'os_version' => $system['os_version'],
            'kernel' => $system['kernel'],
            'arch' => $system['arch'],
            'manufacturer' => $system['manufacturer'],
            'model' => $system['model'],
            'serial_number' => $system['serial_number'],
            'cpu_model' => $system['cpu_model'],
            'cpu_cores' => $system['cpu_cores'],
            'memory_bytes' => $system['memory_bytes'],
            'virtualisation' => $system['virtualisation'],
            'agent_version' => $system['agent_version'],
            'primary_ip' => $system['primary_ip'],
            'report_ip' => $ip,
            'cpu_percent' => $metrics['cpu_percent'],
            'memory_used_bytes' => $metrics['memory_used_bytes'],
            'swap_used_bytes' => $metrics['swap_used_bytes'],
            'load1' => $metrics['load1'],
            'uptime_seconds' => $system['uptime_seconds'],
            'boot_at' => $bootAt,
            'last_seen_at' => $now,
            'last_report_at' => $now,
            'status' => 'online',
            'updated_at' => $now,
        ];

        // Consent to being updated from here belongs to the machine, so it is
        // taken from the report rather than settable on this side. The same
        // goes for the other two, which is why an agent that says nothing
        // leaves them as they were rather than clearing them: silence is not
        // a withdrawal.
        if ($payload['self_update'] !== null) {
            $columns['self_update'] = $payload['self_update'] ? 1 : 0;
        }
        if ($payload['allow'] !== null) {
            $columns['allow_updates'] = $payload['allow']['updates'] ? 1 : 0;
            $columns['allow_reboot'] = $payload['allow']['reboot'] ? 1 : 0;
        }

        // A new version means the agent replaced itself, or somebody
        // reinstalled it. Either way it is worth knowing when.
        $before = (string) ($device['agent_version'] ?? '');
        if ($before !== '' && $system['agent_version'] !== null && $before !== $system['agent_version']) {
            $columns['agent_updated_at'] = $now;
        }

        // A machine that has not been renamed by hand follows its own hostname.
        if ((string) $device['name'] === (string) $device['hostname']) {
            $columns['name'] = $system['hostname'];
        }

        // The agent's guess only counts while nobody has overruled it.
        if ((int) $device['kind_locked'] === 0 && $system['kind'] !== 'unknown') {
            $columns['kind'] = $system['kind'];
        }

        if ($diskTotal !== null) {
            $columns['disk_total_bytes'] = $diskTotal;
            $columns['disk_used_bytes'] = $diskUsed;
        }

        if (in_array('updates', $collected, true)) {
            $columns['updates_total'] = min(65535, count($payload['updates']['items']));
            $columns['updates_security'] = min(65535, self::countSecurity($updates));
            $columns['reboot_required'] = $updates['reboot_required'] ? 1 : 0;
        }

        if (in_array('packages', $collected, true)) {
            $columns['package_count'] = count($payload['packages']);
        }
        if (in_array('services', $collected, true)) {
            $columns['service_count'] = min(65535, count($payload['services']));
        }
        if (in_array('ports', $collected, true)) {
            $columns['port_count'] = min(65535, count($payload['ports']));
        }

        if ((string) $device['status'] !== 'online') {
            $columns['status_since'] = $now;
        }

        // The reporting interval is deliberately not taken from the report.
        // The installer's --interval seeds it at enrolment and the field on
        // this site owns it from then on; letting each report re-assert the
        // machine's own number would quietly undo whatever an administrator
        // had just typed, which is exactly what happened the first time this
        // was written.

        $events = self::changes($device, $columns, $diskTotal, $diskUsed);

        Db::transaction(static function () use ($deviceId, $columns, $now, $metrics, $system, $payload, $collected, $diskTotal, $diskUsed, $events): void {
            Db::update('devices', $columns, ['id' => $deviceId]);

            Db::insert('device_metrics', [
                'device_id' => $deviceId,
                'captured_at' => $now,
                'cpu_percent' => $metrics['cpu_percent'],
                'memory_used_bytes' => $metrics['memory_used_bytes'],
                'memory_total_bytes' => $metrics['memory_total_bytes'] ?? $system['memory_bytes'],
                'swap_used_bytes' => $metrics['swap_used_bytes'],
                'disk_used_bytes' => $diskUsed,
                'disk_total_bytes' => $diskTotal,
                'load1' => $metrics['load1'],
                'load5' => $metrics['load5'],
                'load15' => $metrics['load15'],
                'process_count' => $metrics['process_count'],
                'uptime_seconds' => $system['uptime_seconds'],
            ]);

            if (in_array('disks', $collected, true)) {
                self::replace('device_disks', $deviceId, $payload['disks'], ['mount', 'source', 'filesystem', 'total_bytes', 'used_bytes']);
            }
            if (in_array('updates', $collected, true)) {
                self::replace('device_updates', $deviceId, $payload['updates']['items'], ['name', 'current_version', 'available_version', 'source', 'is_security']);
            }
            if (in_array('packages', $collected, true)) {
                self::replace('device_packages', $deviceId, $payload['packages'], ['name', 'version', 'publisher', 'source']);
            }
            if (in_array('services', $collected, true)) {
                self::replace('device_services', $deviceId, $payload['services'], ['name', 'display_name', 'state', 'startup']);
            }
            if (in_array('ports', $collected, true)) {
                self::replace('device_ports', $deviceId, $payload['ports'], ['protocol', 'address', 'port', 'process']);
            }

            foreach ($events as $event) {
                Devices::recordEvent($deviceId, $event[0], $event[1], $event[2]);
            }
        });
    }

    /**
     * @param array<string,mixed> $updates
     */
    private static function countSecurity(array $updates): int
    {
        $flagged = 0;
        foreach ($updates['items'] as $item) {
            $flagged += (int) $item['is_security'];
        }

        // Some package managers know the count without knowing which ones, so
        // an explicit number is allowed to stand in when the flags say nothing.
        return $flagged > 0 ? $flagged : (int) ($updates['security_count'] ?? 0);
    }

    /**
     * What changed since last time, in words worth putting on the timeline.
     *
     * Only crossings are recorded, never states: "needs a reboot" once, not on
     * every report for the week until somebody gets round to it.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<int,array{0:string,1:string,2:string}>
     */
    private static function changes(array $before, array $after, ?int $diskTotal, ?int $diskUsed): array
    {
        $events = [];
        $name = (string) ($after['name'] ?? $before['name']);

        if (in_array((string) $before['status'], ['stale', 'offline'], true)) {
            $events[] = ['back', $name . ' is reporting again.', 'info'];
        } elseif ((string) $before['status'] === 'pending') {
            $events[] = ['first_report', $name . ' sent its first report.', 'info'];
        }

        if (isset($after['reboot_required'])) {
            if ((int) $before['reboot_required'] === 0 && (int) $after['reboot_required'] === 1) {
                $events[] = ['reboot_required', $name . ' is waiting for a restart to finish an update.', 'warning'];
            } elseif ((int) $before['reboot_required'] === 1 && (int) $after['reboot_required'] === 0) {
                $events[] = ['rebooted', $name . ' no longer needs a restart.', 'info'];
            }
        }

        if (isset($after['updates_security'])) {
            $wasSecure = (int) $before['updates_security'];
            $isSecure = (int) $after['updates_security'];
            if ($wasSecure === 0 && $isSecure > 0) {
                $events[] = [
                    'security_updates',
                    sprintf('%s has %d security %s waiting.', $name, $isSecure, $isSecure === 1 ? 'update' : 'updates'),
                    'warning',
                ];
            } elseif ($wasSecure > 0 && $isSecure === 0) {
                $events[] = ['security_clear', $name . ' has no security updates outstanding.', 'info'];
            }
        }

        $beforeVersion = (string) ($before['agent_version'] ?? '');
        $afterVersion = (string) ($after['agent_version'] ?? '');
        if ($beforeVersion !== '' && $afterVersion !== '' && $beforeVersion !== $afterVersion) {
            $events[] = ['agent_updated', sprintf('Agent went from %s to %s.', $beforeVersion, $afterVersion), 'info'];
        }

        $beforeOs = trim((string) ($before['os_name'] ?? '') . ' ' . (string) ($before['os_version'] ?? ''));
        $afterOs = trim((string) ($after['os_name'] ?? '') . ' ' . (string) ($after['os_version'] ?? ''));
        if ($beforeOs !== '' && $afterOs !== '' && $beforeOs !== $afterOs) {
            $events[] = ['os_changed', sprintf('Operating system went from %s to %s.', $beforeOs, $afterOs), 'info'];
        }

        // Disk pressure is worth one line when it crosses, and silence after.
        if ($diskTotal !== null && $diskTotal > 0 && $diskUsed !== null) {
            $wasTotal = (int) ($before['disk_total_bytes'] ?? 0);
            $wasUsed = (int) ($before['disk_used_bytes'] ?? 0);
            $wasFull = $wasTotal > 0 && $wasUsed / $wasTotal >= 0.9;
            $isFull = $diskUsed / $diskTotal >= 0.9;

            if (!$wasFull && $isFull) {
                $events[] = [
                    'disk_full',
                    sprintf('Storage is %d%% used.', (int) round($diskUsed / $diskTotal * 100)),
                    'warning',
                ];
            } elseif ($wasFull && !$isFull) {
                $events[] = ['disk_ok', 'Storage is back under 90% used.', 'info'];
            }
        }

        return $events;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $columns
     */
    private static function replace(string $table, int $deviceId, array $rows, array $columns): void
    {
        Db::execute('DELETE FROM {{' . $table . '}} WHERE `device_id` = ?', [$deviceId]);

        if ($rows === []) {
            return;
        }

        $names = array_merge(['device_id'], $columns);
        $columnSql = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $names));
        $placeholder = '(' . implode(', ', array_fill(0, count($names), '?')) . ')';

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = $placeholder;
                $params[] = $deviceId;
                foreach ($columns as $column) {
                    $params[] = $row[$column] ?? null;
                }
            }

            Db::execute(
                'INSERT INTO {{' . $table . '}} (' . $columnSql . ') VALUES ' . implode(', ', $values),
                $params
            );
        }
    }
}
