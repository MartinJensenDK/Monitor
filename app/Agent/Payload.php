<?php

declare(strict_types=1);

namespace App\Agent;

use App\Domain\Devices;

/**
 * Turns whatever a machine posted into something this application is willing
 * to believe.
 *
 * Every field below arrives from a program running somewhere else, on hardware
 * this site does not control, and is therefore treated exactly like a form
 * submitted by a stranger. A leaked device token buys the ability to post here
 * and nothing more: strings are forced to valid UTF-8, stripped of control
 * characters and cut to the width of the column they land in; numbers are
 * clamped to ranges that make physical sense; and every list has a ceiling, so
 * a machine claiming two million packages fills a page rather than a disk.
 *
 * Nothing here decides anything. It only produces clean values for Ingest to
 * store, which keeps the "is this safe" question in one readable file.
 */
final class Payload
{
    public const MAX_DISKS = 64;
    public const MAX_UPDATES = 1000;
    public const MAX_PACKAGES = 6000;
    public const MAX_SERVICES = 600;
    public const MAX_PORTS = 600;

    /** Lines accepted in one batch. A talkative command still gets through, in pieces. */
    public const MAX_LOG_LINES = 500;

    /** Bytes of JSON we are willing to read from one report. */
    public const MAX_BODY = 4194304;

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalise(array $raw): array
    {
        $system = self::table($raw['system'] ?? null);
        $metrics = self::table($raw['metrics'] ?? null);
        $updates = self::table($raw['updates'] ?? null);

        return [
            'system' => [
                'hostname' => self::text($system['hostname'] ?? null, 190) ?? 'unnamed',
                'fqdn' => self::text($system['fqdn'] ?? null, 255),
                'kind' => self::kind($system['kind'] ?? null),
                'os_family' => self::osFamily($system['os_family'] ?? null),
                'os_name' => self::text($system['os_name'] ?? null, 120),
                'os_version' => self::text($system['os_version'] ?? null, 80),
                'kernel' => self::text($system['kernel'] ?? null, 120),
                'arch' => self::text($system['arch'] ?? null, 32),
                'manufacturer' => self::text($system['manufacturer'] ?? null, 120),
                'model' => self::text($system['model'] ?? null, 120),
                'serial_number' => self::text($system['serial'] ?? null, 120),
                'cpu_model' => self::text($system['cpu_model'] ?? null, 190),
                'cpu_cores' => self::int($system['cpu_cores'] ?? null, 0, 4096),
                'memory_bytes' => self::int($system['memory_bytes'] ?? null, 0, PHP_INT_MAX),
                'virtualisation' => self::text($system['virtualisation'] ?? null, 40),
                'primary_ip' => self::ip($system['primary_ip'] ?? null),
                'agent_version' => self::text($system['agent_version'] ?? null, 32),
                'uptime_seconds' => self::int($system['uptime_seconds'] ?? null, 0, 3155760000),
            ],
            'metrics' => [
                // A percentage above 100 is a bug in somebody's arithmetic, not
                // news, so it is clamped rather than rejected.
                'cpu_percent' => self::float($metrics['cpu_percent'] ?? null, 0, 100),
                'memory_used_bytes' => self::int($metrics['memory_used_bytes'] ?? null, 0, PHP_INT_MAX),
                'memory_total_bytes' => self::int($metrics['memory_total_bytes'] ?? null, 0, PHP_INT_MAX),
                'swap_used_bytes' => self::int($metrics['swap_used_bytes'] ?? null, 0, PHP_INT_MAX),
                'load1' => self::float($metrics['load1'] ?? null, 0, 99999),
                'load5' => self::float($metrics['load5'] ?? null, 0, 99999),
                'load15' => self::float($metrics['load15'] ?? null, 0, 99999),
                'process_count' => self::int($metrics['process_count'] ?? null, 0, 16777215),
            ],
            'updates' => [
                'reboot_required' => self::bool($updates['reboot_required'] ?? null),
                'security_count' => self::int($updates['security_count'] ?? null, 0, 65535),
                'items' => self::updateList($updates['items'] ?? null),
            ],
            'disks' => self::diskList($raw['disks'] ?? null),
            'packages' => self::packageList($raw['packages'] ?? null),
            'services' => self::serviceList($raw['services'] ?? null),
            'ports' => self::portList($raw['ports'] ?? null),
            'results' => self::resultList($raw['results'] ?? null),
            'logs' => self::logLines($raw['logs'] ?? null),

            // Whether the machine consents to the agent being replaced from
            // here. Decided on the machine at install time, like the two
            // commands that change it.
            'self_update' => isset($raw['self_update']) ? self::bool($raw['self_update']) : null,

            // The rest of what this machine consents to, from the same place
            // and for the same reason: MONITOR_ALLOW, decided by whoever
            // installed it. Null means the agent said nothing -- an older one
            // that has no opinion to give -- and that is not the same as
            // refusing, so it is kept distinct all the way to the interface.
            'allow' => self::allowList($raw['allow'] ?? null),

            // What the agent was installed to do. It seeds the interval at
            // enrolment and is ignored on every report after that -- the site
            // owns the schedule from then on. Clamped either way, so a broken
            // loop cannot turn a fleet into a flood.
            'interval_seconds' => self::int($raw['interval_seconds'] ?? null, Devices::MIN_INTERVAL, Devices::MAX_INTERVAL),

            // How often the agent was installed to check in for orders. Like
            // the interval above, it seeds the value at enrolment and is
            // ignored afterwards. Zero is allowed and means the machine was
            // installed with the live channel switched off.
            'poll_seconds' => self::pollSeconds($raw['poll_seconds'] ?? null),

            // Which of the optional lists this agent actually gathered. A list
            // that was not gathered must not read as "there are none of these".
            'collected' => self::collected($raw['collected'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $normalised
     * @return array<int,array<string,mixed>>
     */
    private static function diskList(mixed $value): array
    {
        $out = [];
        $seen = [];
        foreach (self::rows($value, self::MAX_DISKS) as $row) {
            $mount = self::text($row['mount'] ?? null, 190);
            if ($mount === null || isset($seen[$mount])) {
                continue;
            }
            $seen[$mount] = true;

            $total = self::int($row['total_bytes'] ?? null, 0, PHP_INT_MAX) ?? 0;
            $used = self::int($row['used_bytes'] ?? null, 0, PHP_INT_MAX) ?? 0;

            $out[] = [
                'mount' => $mount,
                'source' => self::text($row['source'] ?? null, 190),
                'filesystem' => self::text($row['filesystem'] ?? null, 40),
                'total_bytes' => $total,
                // Used above total is meaningless; take the smaller so the bar
                // in the interface cannot run off its own track.
                'used_bytes' => min($used, $total),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function updateList(mixed $value): array
    {
        $out = [];
        foreach (self::rows($value, self::MAX_UPDATES) as $row) {
            $name = self::text($row['name'] ?? null, 190);
            if ($name === null) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'current_version' => self::text($row['current_version'] ?? null, 120),
                'available_version' => self::text($row['available_version'] ?? null, 120),
                'source' => self::text($row['source'] ?? null, 80),
                'is_security' => self::bool($row['is_security'] ?? null) ? 1 : 0,
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function packageList(mixed $value): array
    {
        $out = [];
        foreach (self::rows($value, self::MAX_PACKAGES) as $row) {
            $name = self::text($row['name'] ?? null, 190);
            if ($name === null) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'version' => self::text($row['version'] ?? null, 120),
                'publisher' => self::text($row['publisher'] ?? null, 190),
                'source' => self::text($row['source'] ?? null, 40),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function serviceList(mixed $value): array
    {
        $out = [];
        foreach (self::rows($value, self::MAX_SERVICES) as $row) {
            $name = self::text($row['name'] ?? null, 190);
            if ($name === null) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'display_name' => self::text($row['display_name'] ?? null, 190),
                'state' => self::text($row['state'] ?? null, 24),
                'startup' => self::text($row['startup'] ?? null, 24),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function portList(mixed $value): array
    {
        $out = [];
        foreach (self::rows($value, self::MAX_PORTS) as $row) {
            // Dropped rather than clamped: a port is read as data, and
            // squeezing 999999 into 65535 would invent a listener that is not
            // there. A number outside the range is a broken reading.
            $port = self::int($row['port'] ?? null, 0, 4294967295);
            if ($port === null || $port < 1 || $port > 65535) {
                continue;
            }
            $protocol = strtolower((string) (self::text($row['protocol'] ?? null, 8) ?? 'tcp'));
            $out[] = [
                'protocol' => in_array($protocol, ['tcp', 'udp', 'tcp6', 'udp6'], true) ? $protocol : 'tcp',
                'address' => self::text($row['address'] ?? null, 45),
                'port' => $port,
                'process' => self::text($row['process'] ?? null, 190),
            ];
        }

        return $out;
    }

    /**
     * Results of commands the agent collected last time.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function resultList(mixed $value): array
    {
        $out = [];
        foreach (self::rows($value, 20) as $row) {
            $id = self::text($row['id'] ?? null, 36);
            if ($id === null || preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'ok' => self::bool($row['ok'] ?? null),
                'exit_code' => self::int($row['exit_code'] ?? null, -32768, 32767) ?? 0,
                'output' => self::text($row['output'] ?? null, 8000, true) ?? '',
                'error' => self::text($row['error'] ?? null, 500) ?? '',
            ];
        }

        return $out;
    }

    /**
     * What the agent said it was doing.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function logLines(mixed $value): array
    {
        $levels = ['debug', 'info', 'warn', 'error'];
        $out = [];

        foreach (self::rows($value, self::MAX_LOG_LINES) as $row) {
            $message = self::text($row['message'] ?? null, 1000);
            if ($message === null) {
                continue;
            }

            $level = is_string($row['level'] ?? null) ? strtolower($row['level']) : 'info';
            $command = self::text($row['command'] ?? null, 36);

            $out[] = [
                'level' => in_array($level, $levels, true) ? $level : 'info',
                'message' => $message,
                // The machine's clock, believed only far enough to store it.
                // Ordering is done by the id this server assigns.
                'at' => self::timestamp($row['at'] ?? null),
                'command' => $command !== null && preg_match('/^[0-9a-f-]{36}$/i', $command) === 1 ? $command : '',
            ];
        }

        return $out;
    }

    /** An ISO-ish instant, or null. Anything implausible is simply dropped. */
    private static function timestamp(mixed $value): ?string
    {
        $text = self::text($value, 40);
        if ($text === null) {
            return null;
        }

        $at = strtotime($text);
        if ($at === false) {
            return null;
        }

        // A clock more than a year out is not a clock worth recording.
        if (abs($at - time()) > 31536000) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $at);
    }

    /** @return array<int,string> */
    /**
     * What the machine says it may be asked to do.
     *
     * @return array<string,bool>|null null when the agent did not say
     */
    private static function allowList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $said = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $said[] = strtolower(trim($item));
            }
        }

        return [
            'updates' => in_array('updates', $said, true),
            'reboot' => in_array('reboot', $said, true),
        ];
    }

    private static function collected(mixed $value): array
    {
        $allowed = ['disks', 'updates', 'packages', 'services', 'ports'];
        if (!is_array($value)) {
            // An agent that says nothing is taken at its word for everything it
            // did send, which is how the first version behaved.
            return $allowed;
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && in_array($item, $allowed, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function table(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rows(mixed $value, int $max): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $row) {
            if (count($out) >= $max) {
                break;
            }
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * A string this application is willing to store: valid UTF-8, no control
     * characters, trimmed, and no longer than the column it goes in.
     */
    public static function text(mixed $value, int $max, bool $keepNewlines = false): ?string
    {
        if (is_bool($value) || is_array($value) || $value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            return null;
        }

        $string = (string) $value;

        // Anything that is not valid UTF-8 would break json_encode later and
        // can hide a byte sequence that renders as something else entirely.
        if (!mb_check_encoding($string, 'UTF-8')) {
            $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        }

        $pattern = $keepNewlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u' : '/[\x00-\x1F\x7F]+/u';
        $string = (string) preg_replace($pattern, $keepNewlines ? '' : ' ', $string);
        $string = trim($string);

        if ($string === '') {
            return null;
        }

        return mb_substr($string, 0, $max);
    }

    public static function int(mixed $value, int $min, int $max): ?int
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return max($min, min($max, $number));
    }

    public static function float(mixed $value, float $min, float $max): ?float
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }

        return round(max($min, min($max, (float) $value)), 2);
    }

    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(is_scalar($value) ? strtolower((string) $value) : '', ['1', 'true', 'yes', 'on'], true);
    }

    private static function kind(mixed $value): string
    {
        $kind = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($kind, ['server', 'client'], true) ? $kind : 'unknown';
    }

    private static function osFamily(mixed $value): string
    {
        $family = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($family, ['linux', 'windows', 'macos'], true) ? $family : 'other';
    }

    /** Zero passes through as "off"; anything else is clamped into the allowed band. */
    private static function pollSeconds(mixed $value): ?int
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }
        if ((int) $value <= 0) {
            return 0;
        }

        return self::int($value, Devices::MIN_POLL, Devices::MAX_POLL);
    }

    /** Only a real address is stored, so nothing arbitrary reaches a page that shows one. */
    private static function ip(mixed $value): ?string
    {
        $text = self::text($value, 45);
        if ($text === null) {
            return null;
        }

        return filter_var($text, FILTER_VALIDATE_IP) === false ? null : $text;
    }
}
