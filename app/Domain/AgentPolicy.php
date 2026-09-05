<?php

declare(strict_types=1);

namespace App\Domain;

use App\Agent\Scripts;
use App\Core\Db;

/**
 * The agent settings that belong to the site rather than to one machine.
 *
 * Two different kinds of thing live here, and the difference matters.
 *
 * The defaults are a starting point. They fill in a machine's own settings the
 * moment it enrols and are never consulted again, because from then on the
 * machine's row is the truth and can be edited on its own page. Pushing a
 * changed default onto machines that already exist is a separate, deliberate
 * act -- applyToAll() -- rather than something that happens quietly when
 * somebody saves this page.
 *
 * The switches are refusals. They are read on every request an agent makes, so
 * turning one off takes effect on the next knock, for the whole fleet at once.
 * They can only ever withhold something: no switch here can make a machine
 * accept an update or run a command it was not already willing to. Consent to
 * being updated is the machine's to give -- this side's part is only whether
 * it is offered anything at all.
 */
final class AgentPolicy
{
    /** @var array<int,string> */
    public const LOG_LEVELS = ['error', 'warn', 'info', 'debug'];

    public static function interval(): int
    {
        return max(
            Devices::MIN_INTERVAL,
            min(Devices::MAX_INTERVAL, Settings::int('agent_default_interval', 300))
        );
    }

    /**
     * Zero means the live channel is off by default: a new machine reports on
     * its interval and is not reachable in between.
     */
    public static function poll(): int
    {
        $poll = Settings::int('agent_default_poll', Devices::DEFAULT_POLL);
        if ($poll <= 0) {
            return 0;
        }

        return max(Devices::MIN_POLL, min(Devices::MAX_POLL, $poll));
    }

    public static function level(): string
    {
        $level = Settings::get('agent_default_log_level', 'info');

        return in_array($level, self::LOG_LEVELS, true) ? $level : 'info';
    }

    public static function commands(): bool
    {
        return Settings::bool('agent_default_commands');
    }

    /**
     * Whether this server offers anyone the agent it holds. Off means the
     * manifest is simply left out of the answer, which every agent reads as
     * "nothing on offer" and no agent reads as an error.
     */
    public static function mayOfferUpdates(): bool
    {
        return Settings::bool('agent_updates_enabled');
    }

    /**
     * Whether queued commands are handed out at all. A machine that also has
     * commands switched off on its own page stays off either way -- this is
     * the outer of the two locks, not a replacement for it.
     */
    public static function mayRunCommands(): bool
    {
        return Settings::bool('agent_commands_enabled');
    }

    /**
     * Write the current defaults onto every machine that already exists.
     *
     * The one thing on this page that reaches out and changes machines, which
     * is why it is a button of its own rather than part of saving. Log level
     * and the two cadences reach each machine on its next check-in; whether
     * commands are allowed takes effect here immediately.
     *
     * @return int how many machines were changed
     */
    public static function applyToAll(): int
    {
        if (!Devices::isReady()) {
            return 0;
        }

        return Db::execute(
            'UPDATE {{devices}} SET
                `interval_seconds` = :interval,
                `poll_seconds` = :poll,
                `log_level` = :level,
                `commands_enabled` = :commands,
                `updated_at` = UTC_TIMESTAMP()
             WHERE `interval_seconds` <> :interval2
                OR `poll_seconds` <> :poll2
                OR `log_level` <> :level2
                OR `commands_enabled` <> :commands2',
            [
                'interval' => self::interval(),
                'poll' => self::poll(),
                'level' => self::level(),
                'commands' => self::commands() ? 1 : 0,
                'interval2' => self::interval(),
                'poll2' => self::poll(),
                'level2' => self::level(),
                'commands2' => self::commands() ? 1 : 0,
            ]
        );
    }

    /**
     * What the page shows above the settings: whether the fleet is actually on
     * the agent this server holds, and how many machines are not.
     *
     * @return array<string,mixed>
     */
    public static function fleet(): array
    {
        $versions = [
            'linux' => Scripts::version('linux'),
            'windows' => Scripts::version('windows'),
        ];

        if (!Devices::isReady()) {
            return ['ready' => false, 'versions' => $versions, 'total' => 0, 'behind' => 0, 'pinned' => 0, 'unknown' => 0];
        }

        $rows = Db::select(
            'SELECT `os_family`, `agent_version`, `self_update` FROM {{devices}}'
        );

        $behind = 0;
        $pinned = 0;
        $unknown = 0;
        foreach ($rows as $row) {
            $running = (string) ($row['agent_version'] ?? '');
            $offered = $versions[(string) $row['os_family']] ?? '0.0.0';

            if ((int) $row['self_update'] !== 1) {
                $pinned++;
            }
            if ($running === '' || $offered === '0.0.0') {
                $unknown++;
                continue;
            }
            if (version_compare($running, $offered) < 0) {
                $behind++;
            }
        }

        return [
            'ready' => true,
            'versions' => $versions,
            'total' => count($rows),
            'behind' => $behind,
            'pinned' => $pinned,
            'unknown' => $unknown,
        ];
    }
}
