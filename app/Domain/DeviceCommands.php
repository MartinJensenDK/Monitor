<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;
use App\Core\Db;
use App\Support\Str;

/**
 * The short list of things this site may ask a machine to do.
 *
 * A command is a name, never a command line. The agent holds the same four
 * names in a hard-coded branch and refuses anything else, so the worst that a
 * compromised server -- or a tampered database row -- can ask for is one of
 * the four below. That is the whole point of the design: the queue is a menu,
 * not a shell.
 *
 * The two that change a machine are additionally opt-in on the machine itself.
 * Installing the agent without --allow-updates means this site can ask all it
 * likes and the answer is a refusal, decided locally.
 */
final class DeviceCommands
{
    /**
     * @return array<string,array{label:string,hint:string,changes:bool,icon:string}>
     */
    public static function catalogue(): array
    {
        return [
            'report_now' => [
                'label' => 'Report now',
                'hint' => 'Sends a fresh report at the next check-in instead of waiting for the interval.',
                'changes' => false,
                'icon' => 'refresh',
            ],
            'refresh_updates' => [
                'label' => 'Check for updates',
                'hint' => 'Goes out to the machine\'s update sources for a fresh list — apt-get update, softwareupdate, or a Windows Update scan — and says what is waiting. Installs nothing.',
                'changes' => false,
                'icon' => 'history',
            ],
            'update_agent' => [
                'label' => 'Update the agent',
                'hint' => 'Replaces the agent with the version this server holds. The machine must have been installed without --no-self-update.',
                'changes' => true,
                'icon' => 'download',
            ],
            'install_updates' => [
                'label' => 'Install updates',
                'hint' => 'Applies the pending updates. The machine must have been installed with --allow-updates.',
                'changes' => true,
                'icon' => 'check',
            ],
            'reboot' => [
                'label' => 'Restart',
                'hint' => 'Restarts the machine. Requires --allow-reboot on the machine itself.',
                'changes' => true,
                'icon' => 'play',
            ],
        ];
    }

    /**
     * Which commands this machine would refuse, and the flag that would change
     * its mind.
     *
     * The three commands that change a machine need consent given on the
     * machine, at install time, and it cannot be granted from here. Until the
     * agent started saying what it had consented to, this side could not know,
     * so the only way to find out was to ask for something and be turned down.
     *
     * A machine that has not said is left alone. An agent too old to send its
     * consent has not refused anything, and showing "will refuse" on a guess
     * would be worse than the silence it replaced.
     *
     * @param array<string,mixed> $device
     * @return array<string,string> command name => the flag it was installed without
     */
    public static function withheld(array $device): array
    {
        $windows = (string) ($device['os_family'] ?? '') === 'windows';
        $withheld = [];

        $refuses = static fn (string $column): bool =>
            array_key_exists($column, $device)
            && $device[$column] !== null
            && (int) $device[$column] !== 1;

        if ($refuses('allow_updates')) {
            $withheld['install_updates'] = $windows ? '-AllowUpdates' : '--allow-updates';
        }
        if ($refuses('allow_reboot')) {
            $withheld['reboot'] = $windows ? '-AllowReboot' : '--allow-reboot';
        }
        // The odd one out: this one is refused by a flag having been given
        // rather than withheld, so it names the flag that is in the way.
        if ($refuses('self_update')) {
            $withheld['update_agent'] = $windows ? '-NoSelfUpdate' : '--no-self-update';
        }

        return $withheld;
    }

    public static function exists(string $command): bool
    {
        return isset(self::catalogue()[$command]);
    }

    /** A command that changes the machine, rather than just asking it something. */
    public static function changesThings(string $command): bool
    {
        return (bool) (self::catalogue()[$command]['changes'] ?? true);
    }

    public static function label(string $command): string
    {
        return (string) (self::catalogue()[$command]['label'] ?? $command);
    }

    /**
     * Put a command in a machine's queue.
     *
     * Commands expire, because one nobody collected must not be waiting a
     * month later: a laptop that comes back from a drawer should not start
     * rebooting on the strength of a decision made in another world.
     */
    public static function queue(int $deviceId, string $command, int $expiresInMinutes = 60): int
    {
        return Db::insert('device_commands', [
            'uuid' => Str::uuid4(),
            'device_id' => $deviceId,
            'command' => $command,
            'status' => 'queued',
            'requested_by' => Auth::id() > 0 ? Auth::id() : null,
            'requested_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + max(5, $expiresInMinutes) * 60),
        ]);
    }

    /**
     * Hand the agent whatever is waiting for it, and mark it collected.
     *
     * One shot: a command moves to 'claimed' the moment it is handed over, so
     * an agent that crashes half way through does not get the same reboot
     * again on its next check-in.
     *
     * @return array<int,array{id:string,command:string}>
     */
    public static function claim(int $deviceId, int $limit = 5): array
    {
        $rows = Db::select(
            'SELECT `id`, `uuid`, `command` FROM {{device_commands}}
             WHERE `device_id` = ? AND `status` = \'queued\' AND `expires_at` > UTC_TIMESTAMP()
             ORDER BY `id` LIMIT ' . max(1, min(20, $limit)),
            [$deviceId]
        );

        $claimed = [];
        foreach ($rows as $row) {
            $updated = Db::execute(
                'UPDATE {{device_commands}} SET `status` = \'claimed\', `claimed_at` = UTC_TIMESTAMP()
                 WHERE `id` = ? AND `status` = \'queued\'',
                [(int) $row['id']]
            );
            if ($updated === 1) {
                $claimed[] = ['id' => (string) $row['uuid'], 'command' => (string) $row['command']];
            }
        }

        return $claimed;
    }

    /**
     * Record what came back. Scoped to the device so one agent cannot write a
     * result onto another machine's command.
     */
    public static function complete(int $deviceId, string $uuid, bool $ok, int $exitCode, string $output, string $error): bool
    {
        return Db::execute(
            'UPDATE {{device_commands}}
             SET `status` = ?, `finished_at` = UTC_TIMESTAMP(), `exit_code` = ?, `output` = ?, `error` = ?
             WHERE `uuid` = ? AND `device_id` = ? AND `status` = \'claimed\'',
            [
                $ok ? 'done' : 'failed',
                $exitCode,
                mb_substr($output, 0, 8000),
                mb_substr($error, 0, 500),
                $uuid,
                $deviceId,
            ]
        ) === 1;
    }

    /**
     * Command uuid => id, for the handful an agent might tag log lines with.
     *
     * Scoped to the device, so one machine cannot attach its output to
     * another machine's command.
     *
     * @param array<int,string> $uuids
     * @return array<string,int>
     */
    public static function idsByUuid(int $deviceId, array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter($uuids)));
        if ($uuids === []) {
            return [];
        }

        $uuids = array_slice($uuids, 0, 20);
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));

        $rows = Db::select(
            'SELECT `id`, `uuid` FROM {{device_commands}}
             WHERE `device_id` = ? AND `uuid` IN (' . $placeholders . ')',
            array_merge([$deviceId], $uuids)
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['uuid']] = (int) $row['id'];
        }

        return $map;
    }

    /** Something is being carried out right now, so the log view should keep looking. */
    public static function isBusy(int $deviceId): bool
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM {{device_commands}}
             WHERE `device_id` = ? AND `status` IN (\'queued\',\'claimed\') AND `expires_at` > UTC_TIMESTAMP()',
            [$deviceId]
        ) > 0;
    }

    public static function cancel(int $deviceId, int $id): bool
    {
        return Db::execute(
            'UPDATE {{device_commands}} SET `status` = \'cancelled\', `finished_at` = UTC_TIMESTAMP()
             WHERE `id` = ? AND `device_id` = ? AND `status` = \'queued\'',
            [$id, $deviceId]
        ) === 1;
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $deviceId, int $limit = 15): array
    {
        return Db::select(
            'SELECT c.*, u.`name` AS `requested_by_name`
             FROM {{device_commands}} c
             LEFT JOIN {{users}} u ON u.`id` = c.`requested_by`
             WHERE c.`device_id` = ?
             ORDER BY c.`id` DESC LIMIT ' . max(1, min(100, $limit)),
            [$deviceId]
        );
    }

    public static function queuedCount(int $deviceId): int
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM {{device_commands}}
             WHERE `device_id` = ? AND `status` IN (\'queued\',\'claimed\') AND `expires_at` > UTC_TIMESTAMP()',
            [$deviceId]
        );
    }

    /** Called from the scheduler: anything nobody collected in time is closed off. */
    public static function expireStale(): int
    {
        if (!Devices::isReady()) {
            return 0;
        }

        return Db::execute(
            'UPDATE {{device_commands}} SET `status` = \'expired\', `finished_at` = UTC_TIMESTAMP()
             WHERE `status` IN (\'queued\',\'claimed\') AND `expires_at` <= UTC_TIMESTAMP()'
        );
    }
}
