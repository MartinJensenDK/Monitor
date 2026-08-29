<?php

declare(strict_types=1);

namespace App\Entra;

use RuntimeException;

/**
 * The little bit of HTTP the Entra integration needs. Kept separate so the
 * OIDC and Graph classes read as protocol, not as curl options.
 */
final class Http
{
    private const TIMEOUT = 20;

    /**
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    public static function postForm(string $url, array $fields): array
    {
        return self::request($url, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    public static function getJson(string $url, array $headers = []): array
    {
        $lines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return self::request($url, [CURLOPT_HTTPHEADER => $lines]);
    }

    /**
     * @param array<int,mixed> $options
     * @return array<string,mixed>
     */
    private static function request(string $url, array $options): array
    {
        $handle = curl_init();
        curl_setopt_array($handle, $options + [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'Monitor/1.0 (+uptime monitor)',
        ]);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($errno !== 0) {
            throw new RuntimeException('Could not reach Microsoft: ' . $error);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Microsoft answered %d with something that is not JSON.', $status));
        }

        if ($status >= 400) {
            throw new DirectoryException($status, self::explain($status, $decoded));
        }

        return $decoded;
    }

    /**
     * Azure's errors are precise but buried. Surface the part an administrator
     * can act on.
     *
     * @param array<string,mixed> $body
     */
    private static function explain(int $status, array $body): string
    {
        $description = (string) ($body['error_description'] ?? '');
        $code = is_array($body['error'] ?? null)
            ? (string) ($body['error']['code'] ?? '')
            : (string) ($body['error'] ?? '');
        $message = is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : '';

        // The first line of error_description carries the AADSTS code.
        if ($description !== '') {
            $first = trim(explode("\n", $description)[0]);

            return match (true) {
                str_contains($description, 'AADSTS7000215') => 'The client secret is wrong or has expired. Create a new one in the app registration.',
                str_contains($description, 'AADSTS700016') => 'No application with that client id exists in this directory. Check the client id and the tenant id.',
                str_contains($description, 'AADSTS90002') => 'That tenant id does not exist.',
                str_contains($description, 'AADSTS50011') => 'The redirect URI is not registered on the app registration. Add the one shown in settings, exactly as it is written.',
                str_contains($description, 'AADSTS65001') => 'An administrator has not granted consent for the permissions this app needs.',
                default => $first,
            };
        }

        if ($code === 'Authorization_RequestDenied') {
            return 'Microsoft Graph refused the request: the app registration is missing '
                . implode(' and ', Entra::GRAPH_PERMISSIONS) . ', or admin consent was never granted.';
        }

        if ($message !== '') {
            return $message;
        }

        return sprintf('Microsoft answered %d.', $status);
    }
}
