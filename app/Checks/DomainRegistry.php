<?php

declare(strict_types=1);

namespace App\Checks;

use App\Domain\Settings;

/**
 * Reads a domain's registration from the registry.
 *
 * RDAP first, because it is structured JSON and says exactly what it means.
 * Plenty of registries still have no RDAP service at all — .dk among them — so
 * there is a WHOIS fallback: ask IANA which server runs the TLD, then ask that
 * server, then read the dates out of the free text it sends back.
 */
final class DomainRegistry
{
    public const DEFAULT_RDAP = 'https://rdap.org/domain/{domain}';

    private const IANA_WHOIS = 'whois.iana.org';

    /** How long a TLD's WHOIS server address is trusted before asking IANA again. */
    private const REFERRAL_TTL = 2592000;

    /** Field names registries use for the expiry date, most specific first. */
    private const EXPIRY_FIELDS = [
        'registry expiry date',
        'registrar registration expiration date',
        'expiration date',
        'expiration time',
        'expiry date',
        'expires on',
        'expires',
        'expire',
        'paid-till',
        'renewal date',
        'valid until',
    ];

    private const CREATED_FIELDS = [
        'creation date',
        'created on',
        'created',
        'registered on',
        'registered',
        'registration date',
    ];

    private const REGISTRAR_FIELDS = ['registrar', 'sponsoring registrar', 'registrar name'];

    /**
     * @return array{
     *     ok:bool, code:string, error:string, source:string, ms:int,
     *     domain:string, expires_at:int|null, created_at:int|null,
     *     registrar:string, statuses:array<int,string>, nameservers:array<int,string>
     * }
     */
    public static function lookup(string $domain, float $timeout = 15.0): array
    {
        $domain = self::normalise($domain);
        if ($domain === '' || !str_contains($domain, '.')) {
            return self::failure('not_a_domain', 'Enter a registrable domain, such as example.com.');
        }

        $startedAt = microtime(true);

        $rdap = self::viaRdap($domain, $timeout);
        if ($rdap['ok']) {
            $rdap['ms'] = (int) round((microtime(true) - $startedAt) * 1000);

            return $rdap;
        }

        $whois = self::viaWhois($domain, $timeout);
        $whois['ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        // When neither worked, the RDAP message is usually the clearer one,
        // unless RDAP simply does not serve this TLD.
        if (!$whois['ok'] && $rdap['code'] !== 'no_rdap' && $rdap['code'] !== '') {
            $rdap['ms'] = $whois['ms'];

            return $rdap;
        }

        return $whois;
    }

    /** Strip scheme, path, www and trailing dot from whatever was typed. */
    public static function normalise(string $domain): string
    {
        $domain = trim($domain);
        if (str_contains($domain, '://')) {
            $domain = (string) (parse_url($domain, PHP_URL_HOST) ?: $domain);
        }

        $domain = strtolower(rtrim(explode('/', $domain)[0], '.'));

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }

    /** @return array<string,mixed> */
    private static function viaRdap(string $domain, float $timeout): array
    {
        $template = Settings::get('rdap_url', '') ?: self::DEFAULT_RDAP;
        $url = str_replace('{domain}', rawurlencode($domain), $template);

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => (int) $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, (int) $timeout),
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json, application/json'],
            CURLOPT_USERAGENT => 'Monitor/1.0 (+uptime monitor)',
        ]);

        $body = (string) curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($errno !== 0) {
            return self::failure('rdap_unreachable', 'The RDAP service could not be reached: ' . curl_strerror($errno));
        }

        if ($status === 404) {
            $document = json_decode($body, true);
            $title = is_array($document) ? (string) ($document['title'] ?? '') : '';

            // "No RDAP service" is a gap in coverage; a 404 from a registry
            // that does serve RDAP means the domain is genuinely unregistered.
            return stripos($title, 'no rdap service') !== false
                ? self::failure('no_rdap', 'No RDAP service publishes this TLD.')
                : self::failure('not_registered', sprintf('The registry has no record of %s. It may be unregistered or already deleted.', $domain));
        }

        if ($status !== 200) {
            return self::failure('rdap_error', sprintf('The RDAP service answered HTTP %d.', $status));
        }

        $document = json_decode($body, true);
        if (!is_array($document)) {
            return self::failure('rdap_error', 'The RDAP service did not return usable JSON.');
        }

        $expires = null;
        $created = null;
        foreach ((array) ($document['events'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $action = strtolower((string) ($event['eventAction'] ?? ''));
            $date = strtotime((string) ($event['eventDate'] ?? ''));
            if ($date === false) {
                continue;
            }
            if ($action === 'expiration') {
                $expires = $date;
            }
            if ($action === 'registration') {
                $created = $date;
            }
        }

        $nameservers = [];
        foreach ((array) ($document['nameservers'] ?? []) as $nameserver) {
            if (is_array($nameserver) && isset($nameserver['ldhName'])) {
                $nameservers[] = strtolower(rtrim((string) $nameserver['ldhName'], '.'));
            }
        }

        return [
            'ok' => true,
            'code' => '',
            'error' => '',
            'source' => 'RDAP',
            'ms' => 0,
            'domain' => $domain,
            'expires_at' => $expires,
            'created_at' => $created,
            'registrar' => self::rdapRegistrar($document),
            'statuses' => array_values(array_map(
                static fn ($s): string => strtolower((string) $s),
                (array) ($document['status'] ?? [])
            )),
            'nameservers' => array_values(array_unique($nameservers)),
        ];
    }

    /** @param array<string,mixed> $document */
    private static function rdapRegistrar(array $document): string
    {
        foreach ((array) ($document['entities'] ?? []) as $entity) {
            if (!is_array($entity) || !in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                continue;
            }

            // jCard: ["vcard", [["fn", {}, "text", "Example Registrar"], …]]
            foreach ((array) ($entity['vcardArray'][1] ?? []) as $field) {
                if (is_array($field) && ($field[0] ?? '') === 'fn') {
                    return mb_substr(trim((string) ($field[3] ?? '')), 0, 190);
                }
            }

            if (isset($entity['handle'])) {
                return mb_substr((string) $entity['handle'], 0, 190);
            }
        }

        return '';
    }

    /** @return array<string,mixed> */
    private static function viaWhois(string $domain, float $timeout): array
    {
        $server = self::whoisServer($domain, $timeout);
        if ($server === null) {
            return self::failure('no_whois', sprintf('No WHOIS server is published for the .%s registry.', self::tld($domain)));
        }

        $text = self::ask($server, $domain, $timeout);
        if ($text === null) {
            return self::failure('whois_unreachable', sprintf('The WHOIS server %s did not answer.', $server));
        }

        // Thin registries answer with a referral to the registrar's own server,
        // which is where the dates actually live.
        $referral = self::field($text, ['registrar whois server', 'whois server']);
        if ($referral !== '' && $referral !== $server && filter_var('http://' . $referral, FILTER_VALIDATE_URL) !== false) {
            $deeper = self::ask($referral, $domain, $timeout);
            if ($deeper !== null && self::field($deeper, self::EXPIRY_FIELDS) !== '') {
                $text = $deeper;
                $server = $referral;
            }
        }

        if (self::looksUnregistered($text, $domain)) {
            return self::failure('not_registered', sprintf('The registry has no record of %s. It may be unregistered or already deleted.', $domain));
        }

        $expiry = self::date(self::field($text, self::EXPIRY_FIELDS));
        if ($expiry === null) {
            return self::failure(
                'no_expiry',
                sprintf('%s answered, but published no expiry date for %s.', $server, $domain)
            );
        }

        return [
            'ok' => true,
            'code' => '',
            'error' => '',
            'source' => 'WHOIS ' . $server,
            'ms' => 0,
            'domain' => $domain,
            'expires_at' => $expiry,
            'created_at' => self::date(self::field($text, self::CREATED_FIELDS)),
            'registrar' => mb_substr(self::field($text, self::REGISTRAR_FIELDS), 0, 190),
            'statuses' => self::statuses($text),
            'nameservers' => self::nameservers($text),
        ];
    }

    /** Which server runs this TLD, according to IANA. Cached for a month. */
    private static function whoisServer(string $domain, float $timeout): ?string
    {
        $tld = self::tld($domain);
        if ($tld === '') {
            return null;
        }

        $key = 'whois_server_' . $tld;
        $cached = Settings::get($key, '');
        if ($cached !== '') {
            [$server, $storedAt] = array_pad(explode('|', $cached, 2), 2, '0');
            if (time() - (int) $storedAt < self::REFERRAL_TTL && $server !== '') {
                return $server;
            }
        }

        $text = self::ask(self::IANA_WHOIS, $tld, $timeout);
        if ($text === null) {
            // An expired cache entry still beats no answer at all.
            return $cached === '' ? null : (explode('|', $cached, 2)[0] ?: null);
        }

        $server = self::field($text, ['whois']);
        if ($server === '') {
            return null;
        }

        Settings::set($key, $server . '|' . time());

        return $server;
    }

    private static function ask(string $server, string $query, float $timeout): ?string
    {
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client('tcp://' . $server . ':43', $errno, $error, $timeout);
        if ($socket === false) {
            return null;
        }

        stream_set_timeout($socket, max(1, (int) $timeout));
        @fwrite($socket, $query . "\r\n");

        $text = '';
        $deadline = microtime(true) + $timeout;
        while (!feof($socket) && microtime(true) < $deadline) {
            $chunk = @fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $text .= $chunk;
            if (strlen($text) > 200000) {
                break;
            }
        }
        fclose($socket);

        return $text === '' ? null : $text;
    }

    /**
     * First value for any of these field names, matched case-insensitively.
     *
     * @param array<int,string> $names
     */
    private static function field(string $text, array $names): string
    {
        foreach ($names as $name) {
            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '%' || $line[0] === '#') {
                    continue;
                }
                if (!str_contains($line, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $line, 2);
                if (strtolower(trim($key)) === $name && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }

    /** @return array<int,string> */
    private static function statuses(string $text): array
    {
        $statuses = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^(domain )?status:\s*(.+)$/i', $line, $m) !== 1) {
                continue;
            }

            // "clientHold https://icann.org/epp#clientHold"
            $status = strtolower(trim(explode(' ', trim($m[2]))[0]));
            if ($status !== '') {
                $statuses[] = $status;
            }
        }

        return array_values(array_unique($statuses));
    }

    /** @return array<int,string> */
    private static function nameservers(string $text): array
    {
        $servers = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^(name server|nserver|nameserver|hostname)\s*:\s*(.+)$/i', $line, $m) !== 1) {
                continue;
            }

            $server = strtolower(trim(explode(' ', trim($m[2]))[0]));
            if ($server !== '') {
                $servers[] = rtrim($server, '.');
            }
        }

        return array_values(array_unique($servers));
    }

    private static function looksUnregistered(string $text, string $domain): bool
    {
        $haystack = strtolower($text);

        foreach ([
            'no match for',
            'not found',
            'no entries found',
            'no data found',
            'domain not found',
            'no object found',
            'status: free',
            'status: available',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function date(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        // Registries write dates a dozen ways; strtotime handles almost all of
        // them once the trailing "(UTC)" style notes are gone.
        $value = trim(preg_replace('/\s*\(.*\)$/', '', $value) ?? $value);

        // A bare date carries no zone, and reading it in the server's would
        // move the expiry a day either way. Registries mean UTC.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $value .= ' 00:00:00 UTC';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private static function tld(string $domain): string
    {
        $parts = explode('.', $domain);

        return strtolower((string) end($parts));
    }

    /** @return array<string,mixed> */
    private static function failure(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'error' => $message,
            'source' => '',
            'ms' => 0,
            'domain' => '',
            'expires_at' => null,
            'created_at' => null,
            'registrar' => '',
            'statuses' => [],
            'nameservers' => [],
        ];
    }
}
