<?php

declare(strict_types=1);

namespace App\Checks;

/**
 * TCP connect timing. Used by the port check, and by the ping check when ICMP
 * is not available to us.
 *
 * connectMany() opens every socket at once and waits on all of them together,
 * so thirty port monitors take as long as the slowest one rather than the sum.
 */
final class TcpProbe
{
    /** @return array{ok:bool,ms:int,code:string,error:string} */
    public static function connect(string $host, int $port, float $timeout = 5.0): array
    {
        $results = self::connectMany([0 => ['host' => $host, 'port' => $port, 'timeout' => $timeout]]);

        return $results[0];
    }

    /**
     * @param array<int|string,array{host:string,port:int,timeout:float}> $targets
     * @return array<int|string,array{ok:bool,ms:int,code:string,error:string}>
     */
    public static function connectMany(array $targets): array
    {
        $results = [];
        $pending = [];

        foreach ($targets as $key => $target) {
            $host = trim($target['host']);
            $port = (int) $target['port'];

            if ($host === '' || $port < 1 || $port > 65535) {
                $results[$key] = self::fail('config', 'Set a host and a port between 1 and 65535.');
                continue;
            }

            $address = 'tcp://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
            $errno = 0;
            $errstr = '';

            $stream = @stream_socket_client(
                $address,
                $errno,
                $errstr,
                (float) $target['timeout'],
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );

            if ($stream === false) {
                $results[$key] = self::fromSystemError($errno, $errstr, $host, $port);
                continue;
            }

            stream_set_blocking($stream, false);
            $openedAt = microtime(true);
            $pending[$key] = [
                'stream' => $stream,
                'opened_at' => $openedAt,
                'deadline' => $openedAt + (float) $target['timeout'],
                'host' => $host,
                'port' => $port,
            ];
        }

        while ($pending !== []) {
            $now = microtime(true);
            $write = [];
            $earliest = null;

            foreach ($pending as $key => $entry) {
                if ($entry['deadline'] <= $now) {
                    fclose($entry['stream']);
                    unset($pending[$key]);
                    $results[$key] = self::fail(
                        'timeout',
                        sprintf('%s did not answer on port %d within the timeout.', $entry['host'], $entry['port'])
                    );
                    continue;
                }
                $write[$key] = $entry['stream'];
                $earliest = $earliest === null ? $entry['deadline'] : min($earliest, $entry['deadline']);
            }

            if ($pending === []) {
                break;
            }

            $read = $except = [];
            $writeCopy = array_values($write);
            $wait = max(0.05, min(1.0, ($earliest ?? $now + 1) - $now));

            $ready = @stream_select($read, $writeCopy, $except, (int) $wait, (int) (fmod($wait, 1) * 1_000_000));
            if ($ready === false) {
                break;
            }
            if ($ready === 0) {
                continue;
            }

            foreach ($write as $key => $stream) {
                if (!in_array($stream, $writeCopy, true)) {
                    continue;
                }

                $entry = $pending[$key];
                unset($pending[$key]);

                // A socket that is writable but has no peer never connected.
                $connected = @stream_socket_get_name($stream, true);
                $ms = (int) round((microtime(true) - $entry['opened_at']) * 1000);
                fclose($stream);

                $results[$key] = $connected === false || $connected === ''
                    ? self::fail('connection_refused', sprintf('%s refused the connection on port %d.', $entry['host'], $entry['port']))
                    : ['ok' => true, 'ms' => $ms, 'code' => '', 'error' => ''];
            }
        }

        foreach ($pending as $key => $entry) {
            fclose($entry['stream']);
            $results[$key] = self::fail('timeout', sprintf('%s did not answer on port %d within the timeout.', $entry['host'], $entry['port']));
        }

        return $results;
    }

    /** Read whatever a service announces on connect, for banner matching. */
    public static function readBanner(string $host, int $port, float $timeout = 3.0, int $bytes = 256): string
    {
        $address = 'tcp://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($address, $errno, $errstr, $timeout);
        if ($stream === false) {
            return '';
        }

        stream_set_timeout($stream, (int) $timeout);
        $banner = (string) @fread($stream, $bytes);
        fclose($stream);

        return $banner;
    }

    /** @return array{ok:bool,ms:int,code:string,error:string} */
    private static function fail(string $code, string $error): array
    {
        return ['ok' => false, 'ms' => 0, 'code' => $code, 'error' => $error];
    }

    /** @return array{ok:bool,ms:int,code:string,error:string} */
    private static function fromSystemError(int $errno, string $errstr, string $host, int $port): array
    {
        $message = trim($errstr);

        if (stripos($message, 'not known') !== false || stripos($message, 'resolve') !== false || stripos($message, 'not resolve') !== false) {
            return self::fail('dns', sprintf('The name %s did not resolve.', $host));
        }
        if (stripos($message, 'refused') !== false) {
            return self::fail('connection_refused', sprintf('%s refused the connection on port %d.', $host, $port));
        }
        if (stripos($message, 'unreachable') !== false) {
            return self::fail('unreachable', sprintf('%s is unreachable from this server.', $host));
        }

        return self::fail('connect_failed', $message !== '' ? $message : sprintf('Could not connect to %s on port %d.', $host, $port));
    }
}
