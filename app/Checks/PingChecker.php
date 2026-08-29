<?php

declare(strict_types=1);

namespace App\Checks;

/**
 * Ping check: is the host answering, and how fast.
 *
 * Uses real ICMP where the server allows it and falls back to timing a TCP
 * connect where it does not. Every result records which transport was used, so
 * a number in the interface can always be traced to how it was measured.
 */
final class PingChecker implements CheckerInterface
{
    public const DEFAULT_FALLBACK_PORT = 443;

    public static function type(): string
    {
        return 'ping';
    }

    /** @param array<string,mixed> $monitor */
    public function run(array $monitor): CheckResult
    {
        $host = self::host($monitor);
        $timeout = max(1, (int) $monitor['timeout_seconds']);

        $guard = TargetGuard::check($host);
        if (!$guard['allowed']) {
            return CheckResult::down('blocked_target', $guard['reason']);
        }

        $transport = PingTransport::detect();

        $result = match ($transport) {
            PingTransport::ICMP => self::viaSocket($host, $timeout),
            PingTransport::BINARY => self::viaBinary($host, $timeout),
            default => self::viaTcp($monitor, $host, $timeout),
        };

        return self::toCheckResult($monitor, $result, $transport);
    }

    /** @param array<string,mixed> $monitor */
    public static function host(array $monitor): string
    {
        $target = trim((string) $monitor['target']);

        // Accept a pasted URL as well as a bare host name.
        if (str_contains($target, '://')) {
            return (string) (parse_url($target, PHP_URL_HOST) ?: $target);
        }

        return $target;
    }

    /**
     * Unprivileged ICMP: a datagram socket on protocol 1. The kernel fills in
     * the identifier, so only the sequence number has to match on the way back.
     *
     * @return array{ok:bool,ms:int,code:string,error:string}
     */
    private static function viaSocket(string $host, int $timeout): array
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);
        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return ['ok' => false, 'ms' => 0, 'code' => 'dns', 'error' => sprintf('The name %s did not resolve.', $host)];
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, getprotobyname('icmp') ?: 1);
        if ($socket === false) {
            return ['ok' => false, 'ms' => 0, 'code' => 'icmp_unavailable', 'error' => 'This server may not open an ICMP socket.'];
        }

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $timeout, 'usec' => 0]);

        $sequence = random_int(1, 65535);
        $payload = 'monitor-' . str_repeat('x', 24);
        $packet = self::echoRequest($sequence, $payload);

        $sentAt = microtime(true);
        if (@socket_sendto($socket, $packet, strlen($packet), 0, $ip, 0) === false) {
            $error = socket_strerror(socket_last_error($socket));
            socket_close($socket);

            return ['ok' => false, 'ms' => 0, 'code' => 'icmp_send', 'error' => 'Could not send the echo request: ' . $error];
        }

        $deadline = $sentAt + $timeout;
        while (microtime(true) < $deadline) {
            $buffer = '';
            $from = '';
            $port = 0;

            if (@socket_recvfrom($socket, $buffer, 1500, 0, $from, $port) === false) {
                break;
            }

            $ms = (int) round((microtime(true) - $sentAt) * 1000);

            // Echo replies come back type 0; anything else is a different
            // conversation on the same socket.
            if (strlen($buffer) >= 8 && ord($buffer[0]) === 0) {
                socket_close($socket);

                return ['ok' => true, 'ms' => max(1, $ms), 'code' => '', 'error' => ''];
            }
        }

        socket_close($socket);

        return [
            'ok' => false,
            'ms' => 0,
            'code' => 'timeout',
            'error' => sprintf('%s did not answer the ping within %d seconds.', $host, $timeout),
        ];
    }

    /** ICMP echo request with a correct checksum. */
    private static function echoRequest(int $sequence, string $payload): string
    {
        $header = pack('C2n3', 8, 0, 0, 0, $sequence);
        $checksum = self::checksum($header . $payload);

        return pack('C2n3', 8, 0, $checksum, 0, $sequence) . $payload;
    }

    private static function checksum(string $data): int
    {
        if (strlen($data) % 2 !== 0) {
            $data .= "\x00";
        }

        $sum = 0;
        foreach (unpack('n*', $data) ?: [] as $word) {
            $sum += $word;
        }

        while ($sum >> 16) {
            $sum = ($sum & 0xffff) + ($sum >> 16);
        }

        return ~$sum & 0xffff;
    }

    /** @return array{ok:bool,ms:int,code:string,error:string} */
    private static function viaBinary(string $host, int $timeout): array
    {
        $binary = PingTransport::findBinary();
        if ($binary === null) {
            return ['ok' => false, 'ms' => 0, 'code' => 'icmp_unavailable', 'error' => 'No ping command is available on this server.'];
        }

        $command = sprintf('%s -n -c 1 -W %d %s 2>&1', escapeshellcmd($binary), $timeout, escapeshellarg($host));
        $output = [];
        $status = 0;
        @exec($command, $output, $status);
        $text = implode("\n", $output);

        if ($status === 0 && preg_match('/time[=<]\s*([0-9.]+)\s*ms/i', $text, $m) === 1) {
            return ['ok' => true, 'ms' => max(1, (int) round((float) $m[1])), 'code' => '', 'error' => ''];
        }

        if (stripos($text, 'unknown host') !== false || stripos($text, 'Name or service not known') !== false) {
            return ['ok' => false, 'ms' => 0, 'code' => 'dns', 'error' => sprintf('The name %s did not resolve.', $host)];
        }

        return [
            'ok' => false,
            'ms' => 0,
            'code' => 'timeout',
            'error' => sprintf('%s did not answer the ping within %d seconds.', $host, $timeout),
        ];
    }

    /**
     * @param array<string,mixed> $monitor
     * @return array{ok:bool,ms:int,code:string,error:string}
     */
    private static function viaTcp(array $monitor, string $host, int $timeout): array
    {
        return TcpProbe::connect($host, self::fallbackPort($monitor), (float) $timeout);
    }

    /** @param array<string,mixed> $monitor */
    public static function fallbackPort(array $monitor): int
    {
        $config = json_decode((string) ($monitor['config'] ?? '{}'), true);
        $port = (int) (is_array($config) ? ($config['fallback_port'] ?? 0) : 0);

        return $port > 0 && $port < 65536 ? $port : self::DEFAULT_FALLBACK_PORT;
    }

    /**
     * @param array<string,mixed> $monitor
     * @param array{ok:bool,ms:int,code:string,error:string} $result
     */
    public static function toCheckResult(array $monitor, array $result, string $transport): CheckResult
    {
        $meta = ['transport' => $transport];
        if ($transport === PingTransport::TCP) {
            $meta['fallback_port'] = self::fallbackPort($monitor);
        }

        if (!$result['ok']) {
            $error = $result['error'];
            if ($transport === PingTransport::TCP && in_array($result['code'], ['timeout', 'connection_refused'], true)) {
                $error .= ' (measured as a TCP connect, because this server cannot send ICMP.)';
            }

            return CheckResult::down($result['code'], $error, null, null, $meta);
        }

        $degradedMs = (int) ($monitor['degraded_ms'] ?? 0);
        if ($degradedMs > 0 && $result['ms'] > $degradedMs) {
            return CheckResult::degraded(
                $result['ms'],
                sprintf('Answered in %d ms, over the %d ms threshold.', $result['ms'], $degradedMs),
                $result['ms'],
                null,
                $meta
            );
        }

        return CheckResult::up($result['ms'], $result['ms'], null, $meta);
    }
}
