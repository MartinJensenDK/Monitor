<?php

declare(strict_types=1);

namespace App\Checks;

/**
 * A small DNS client.
 *
 * PHP's dns_get_record() always asks the system resolver, which is exactly what
 * a DNS monitor must not do: the question is usually "does 8.8.8.8 still see
 * the record I published", and a cached local answer hides that. So the query
 * is built and parsed here, and the nameserver is ours to choose.
 *
 * UDP first, then TCP when the answer comes back truncated.
 */
final class DnsResolver
{
    /** Record types the interface offers, in the order they are listed. */
    public const TYPES = [
        'A' => 1,
        'AAAA' => 28,
        'CNAME' => 5,
        'MX' => 15,
        'TXT' => 16,
        'NS' => 2,
        'SOA' => 6,
        'SRV' => 33,
        'PTR' => 12,
        'CAA' => 257,
    ];

    private const RCODES = [
        0 => 'NOERROR',
        1 => 'FORMERR',
        2 => 'SERVFAIL',
        3 => 'NXDOMAIN',
        4 => 'NOTIMP',
        5 => 'REFUSED',
    ];

    /**
     * Ask one nameserver one question.
     *
     * @return array{ok:bool,ms:int,rcode:string,records:array<int,string>,ttl:int|null,error:string}
     */
    public static function query(string $name, string $type, string $server, float $timeout = 5.0): array
    {
        $qtype = self::TYPES[strtoupper($type)] ?? 1;
        $name = self::normalise($name);

        if ($name === '') {
            return self::failure('The monitor has no name to look up.');
        }

        $id = random_int(0, 65535);
        $query = self::buildQuery($id, $name, $qtype);

        $startedAt = microtime(true);
        $response = self::exchangeUdp($query, $server, $timeout);

        if ($response['truncated']) {
            $response = self::exchangeTcp($query, $server, $timeout);
        }

        $ms = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response['payload'] === null) {
            return self::failure($response['error'], $ms);
        }

        $parsed = self::parse($response['payload'], $id, $qtype);
        $parsed['ms'] = max(1, $ms);

        return $parsed;
    }

    /**
     * The resolvers configured on this server, so "no resolver given" still
     * means a real, named nameserver rather than an invisible default.
     *
     * @return array<int,string>
     */
    public static function systemResolvers(): array
    {
        $servers = [];

        $resolv = @file_get_contents('/etc/resolv.conf');
        if (is_string($resolv)) {
            foreach (preg_split('/\R/', $resolv) ?: [] as $line) {
                if (preg_match('/^\s*nameserver\s+(\S+)/i', $line, $m) === 1) {
                    $servers[] = $m[1];
                }
            }
        }

        // A container without resolv.conf should still be able to run the check.
        return $servers === [] ? ['1.1.1.1'] : $servers;
    }

    public static function isValidServer(string $server): bool
    {
        return filter_var($server, FILTER_VALIDATE_IP) !== false;
    }

    /** Trim a name the way a person would type it: URL, trailing dot, spaces. */
    public static function normalise(string $name): string
    {
        $name = trim($name);
        if (str_contains($name, '://')) {
            $name = (string) (parse_url($name, PHP_URL_HOST) ?: $name);
        }

        return strtolower(rtrim($name, '.'));
    }

    /** @return array{payload:string|null,truncated:bool,error:string} */
    private static function exchangeUdp(string $query, string $server, float $timeout): array
    {
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            'udp://' . self::hostPort($server),
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            return ['payload' => null, 'truncated' => false, 'error' => 'Could not reach the nameserver ' . $server . ': ' . $error];
        }

        stream_set_timeout($socket, (int) $timeout, (int) (fmod($timeout, 1) * 1_000_000));
        @fwrite($socket, $query);

        $payload = @fread($socket, 4096);
        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if ($meta['timed_out'] ?? false) {
            return ['payload' => null, 'truncated' => false, 'error' => $server . ' did not answer within ' . (int) $timeout . ' seconds.'];
        }
        if (!is_string($payload) || strlen($payload) < 12) {
            return ['payload' => null, 'truncated' => false, 'error' => $server . ' returned an answer that was too short to be DNS.'];
        }

        // TC bit: the answer did not fit in a datagram, ask again over TCP.
        $flags = unpack('n', substr($payload, 2, 2))[1] ?? 0;

        return ['payload' => $payload, 'truncated' => ($flags & 0x0200) !== 0, 'error' => ''];
    }

    /** @return array{payload:string|null,truncated:bool,error:string} */
    private static function exchangeTcp(string $query, string $server, float $timeout): array
    {
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client('tcp://' . self::hostPort($server), $errno, $error, $timeout);

        if ($socket === false) {
            return ['payload' => null, 'truncated' => false, 'error' => 'Could not reach ' . $server . ' over TCP: ' . $error];
        }

        stream_set_timeout($socket, (int) $timeout);
        @fwrite($socket, pack('n', strlen($query)) . $query);

        $header = self::readExactly($socket, 2);
        if ($header === null) {
            fclose($socket);

            return ['payload' => null, 'truncated' => false, 'error' => $server . ' closed the TCP connection without answering.'];
        }

        $length = unpack('n', $header)[1] ?? 0;
        $payload = $length > 0 ? self::readExactly($socket, $length) : null;
        fclose($socket);

        if ($payload === null) {
            return ['payload' => null, 'truncated' => false, 'error' => $server . ' sent a short answer over TCP.'];
        }

        return ['payload' => $payload, 'truncated' => false, 'error' => ''];
    }

    /** @param resource $socket */
    private static function readExactly($socket, int $length): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($socket, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private static function hostPort(string $server): string
    {
        return str_contains($server, ':') && filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $server . ']:53'
            : $server . ':53';
    }

    private static function buildQuery(int $id, string $name, int $qtype): string
    {
        $question = '';
        foreach (explode('.', $name) as $label) {
            if ($label === '') {
                continue;
            }
            $question .= chr(min(63, strlen($label))) . substr($label, 0, 63);
        }
        $question .= "\x00" . pack('nn', $qtype, 1);

        // Recursion desired; one question, no other sections.
        return pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $question;
    }

    /**
     * @return array{ok:bool,ms:int,rcode:string,records:array<int,string>,ttl:int|null,error:string}
     */
    private static function parse(string $payload, int $expectedId, int $qtype): array
    {
        $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($payload, 0, 12));
        if ($header === false) {
            return self::failure('The nameserver sent an answer that could not be read.');
        }

        if ((int) $header['id'] !== $expectedId) {
            return self::failure('The answer did not match the question that was asked.');
        }

        $rcode = (int) $header['flags'] & 0x000f;
        $rcodeName = self::RCODES[$rcode] ?? ('RCODE' . $rcode);

        if ($rcode !== 0) {
            return [
                'ok' => false,
                'ms' => 0,
                'rcode' => $rcodeName,
                'records' => [],
                'ttl' => null,
                'error' => match ($rcode) {
                    3 => 'The name does not exist (NXDOMAIN).',
                    2 => 'The nameserver failed to answer (SERVFAIL).',
                    5 => 'The nameserver refused the query (REFUSED).',
                    default => 'The nameserver answered ' . $rcodeName . '.',
                },
            ];
        }

        $offset = 12;
        for ($i = 0; $i < (int) $header['qd']; $i++) {
            self::readName($payload, $offset);
            $offset += 4;
        }

        $records = [];
        $ttl = null;

        for ($i = 0; $i < (int) $header['an']; $i++) {
            self::readName($payload, $offset);
            $meta = unpack('ntype/nclass/Nttl/nlength', substr($payload, $offset, 10));
            if ($meta === false) {
                break;
            }
            $offset += 10;
            $data = substr($payload, $offset, (int) $meta['length']);
            $dataOffset = $offset;
            $offset += (int) $meta['length'];

            // A CNAME in an A lookup is part of the answer, not the answer.
            if ((int) $meta['type'] !== $qtype && $qtype !== self::TYPES['CNAME']) {
                continue;
            }

            $value = self::formatRecord((int) $meta['type'], $data, $payload, $dataOffset);
            if ($value !== null) {
                $records[] = $value;
                $ttl = $ttl === null ? (int) $meta['ttl'] : min($ttl, (int) $meta['ttl']);
            }
        }

        return [
            'ok' => true,
            'ms' => 0,
            'rcode' => $rcodeName,
            'records' => $records,
            'ttl' => $ttl,
            'error' => '',
        ];
    }

    private static function formatRecord(int $type, string $data, string $payload, int $dataOffset): ?string
    {
        switch ($type) {
            case self::TYPES['A']:
                return strlen($data) === 4 ? (string) inet_ntop($data) : null;

            case self::TYPES['AAAA']:
                return strlen($data) === 16 ? (string) inet_ntop($data) : null;

            case self::TYPES['CNAME']:
            case self::TYPES['NS']:
            case self::TYPES['PTR']:
                $cursor = $dataOffset;

                return self::readName($payload, $cursor);

            case self::TYPES['MX']:
                $priority = unpack('n', substr($data, 0, 2))[1] ?? 0;
                $cursor = $dataOffset + 2;

                return $priority . ' ' . self::readName($payload, $cursor);

            case self::TYPES['TXT']:
                $text = '';
                $cursor = 0;
                while ($cursor < strlen($data)) {
                    $length = ord($data[$cursor]);
                    $text .= substr($data, $cursor + 1, $length);
                    $cursor += $length + 1;
                }

                return $text;

            case self::TYPES['SOA']:
                $cursor = $dataOffset;
                $primary = self::readName($payload, $cursor);
                $mailbox = self::readName($payload, $cursor);
                $numbers = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nminimum', substr($payload, $cursor, 20));

                return $primary . ' ' . $mailbox . ' ' . implode(' ', array_map('strval', $numbers === false ? [] : $numbers));

            case self::TYPES['SRV']:
                $head = unpack('npriority/nweight/nport', substr($data, 0, 6));
                $cursor = $dataOffset + 6;

                return $head === false
                    ? null
                    : $head['priority'] . ' ' . $head['weight'] . ' ' . $head['port'] . ' ' . self::readName($payload, $cursor);

            case self::TYPES['CAA']:
                if (strlen($data) < 2) {
                    return null;
                }
                $flags = ord($data[0]);
                $tagLength = ord($data[1]);

                return $flags . ' ' . substr($data, 2, $tagLength) . ' "' . substr($data, 2 + $tagLength) . '"';

            default:
                return null;
        }
    }

    /**
     * Read a name at $offset, following compression pointers, and leave
     * $offset just past the name in the record stream.
     */
    private static function readName(string $payload, int &$offset): string
    {
        $labels = [];
        $jumped = false;
        $cursor = $offset;
        $guard = 0;

        while ($guard++ < 128) {
            if ($cursor >= strlen($payload)) {
                break;
            }

            $length = ord($payload[$cursor]);

            if ($length === 0) {
                $cursor++;
                break;
            }

            if (($length & 0xc0) === 0xc0) {
                $pointer = (($length & 0x3f) << 8) | ord($payload[$cursor + 1] ?? "\x00");
                if (!$jumped) {
                    $offset = $cursor + 2;
                    $jumped = true;
                }
                $cursor = $pointer;
                continue;
            }

            $labels[] = substr($payload, $cursor + 1, $length);
            $cursor += $length + 1;
        }

        if (!$jumped) {
            $offset = $cursor;
        }

        return strtolower(implode('.', $labels));
    }

    /** @return array{ok:bool,ms:int,rcode:string,records:array<int,string>,ttl:int|null,error:string} */
    private static function failure(string $message, int $ms = 0): array
    {
        return ['ok' => false, 'ms' => $ms, 'rcode' => '', 'records' => [], 'ttl' => null, 'error' => $message];
    }
}
