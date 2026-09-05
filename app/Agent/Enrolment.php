<?php

declare(strict_types=1);

namespace App\Agent;

use App\Core\Db;
use App\Domain\AgentPolicy;
use App\Domain\DeviceCommands;
use App\Domain\Devices;
use App\Domain\EnrollmentKeys;
use App\Support\Str;

/**
 * A machine's first contact: it spends an enrolment key and is given a token
 * of its own.
 *
 * Separating the two is what makes the shared key safe to hand out. The key is
 * a coupon -- it can be revoked, capped and dated, and spending it creates a
 * row somebody can look at. The token is the identity, unique to the machine,
 * and is the only thing that works from then on.
 */
final class Enrolment
{
    /**
     * @param array<string,mixed> $key the enrolment key row, already redeemed
     * @param array<string,mixed> $payload a normalised report
     * @return array{device:array<string,mixed>,token:string}|null
     */
    public static function create(array $key, array $payload, string $ip): ?array
    {
        // Spend the use first. If two machines race for the last slot on a
        // capped key, exactly one of them gets it.
        if (!EnrollmentKeys::spend((int) $key['id'])) {
            return null;
        }

        $system = $payload['system'];
        $token = AgentAuth::newToken();
        $now = gmdate('Y-m-d H:i:s');

        $kind = (string) $key['kind'] === 'auto'
            ? ($system['kind'] === 'unknown' ? Devices::KIND_SERVER : $system['kind'])
            : (string) $key['kind'];

        $id = Db::insert('devices', [
            'uuid' => Str::uuid4(),
            'name' => $system['hostname'],
            'hostname' => $system['hostname'],
            'fqdn' => $system['fqdn'],
            'kind' => $kind,
            'agent_kind' => $system['kind'],
            // A key that names the kind has already made the decision, so the
            // agent's guess must not undo it on the next report.
            'kind_locked' => (string) $key['kind'] === 'auto' ? 0 : 1,
            'token_hash' => AgentAuth::hash($token),
            'token_hint' => AgentAuth::hint($token),
            'token_issued_at' => $now,
            'enrollment_key_id' => (int) $key['id'],
            'location_id' => $key['location_id'] === null ? null : (int) $key['location_id'],
            'os_family' => $system['os_family'],
            'agent_version' => $system['agent_version'],
            'report_ip' => $ip,
            'status' => 'pending',
            'status_since' => $now,
            // The installer's own flags win where the machine gave one, since
            // somebody typing --interval meant it. Where it said nothing, the
            // site's defaults fill in -- and for the log level and whether
            // commands are allowed the machine never has anything to say, so
            // those are the site's alone.
            'interval_seconds' => $payload['interval_seconds'] ?? AgentPolicy::interval(),
            'poll_seconds' => $payload['poll_seconds'] ?? AgentPolicy::poll(),
            'log_level' => AgentPolicy::level(),
            'commands_enabled' => AgentPolicy::commands() ? 1 : 0,
            'self_update' => ($payload['self_update'] ?? true) ? 1 : 0,
            'enrolled_at' => $now,
            'created_by' => $key['created_by'] === null ? null : (int) $key['created_by'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($key['group_id'] !== null) {
            Devices::share($id, (int) $key['group_id'], 'view');
        }

        Devices::recordEvent(
            $id,
            'enrolled',
            sprintf('%s enrolled from %s using key "%s".', $system['hostname'], $ip, $key['name']),
            'info'
        );

        $device = Devices::find($id);
        if ($device === null) {
            return null;
        }

        // The facts arrived with the enrolment request, so store them straight
        // away rather than leaving the machine blank until its next report.
        Ingest::report($device, $payload, $ip);
        DeviceCommands::expireStale();

        $device = Devices::find($id);

        return $device === null ? null : ['device' => $device, 'token' => $token];
    }
}
