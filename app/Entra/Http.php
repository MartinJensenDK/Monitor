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
     * A profile photo, which is the one thing Graph answers with bytes rather
     * than JSON. The caller passes the ETag it already holds; Microsoft then
     * answers 304 and sends nothing when the picture has not changed.
     *
     * Nothing is thrown for a missing photo: plenty of people simply have
     * none, and that is an answer, not a failure.
     *
     * @param array<string,string> $headers
     * @return array{status:int,bytes:string,type:string,etag:string}
     */
    public static function getBinary(string $url, array $headers = [], string $etag = ''): array
    {
        $lines = ['Accept: image/jpeg, image/*;q=0.8'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        if ($etag !== '') {
            $lines[] = 'If-None-Match: ' . $etag;
        }

        $response = self::raw($url, [CURLOPT_HTTPHEADER => $lines]);
        $status = $response['status'];

        if ($status >= 400 && $status !== 404) {
            $decoded = json_decode($response['body'], true);
            throw new DirectoryException($status, is_array($decoded)
                ? self::explain($status, $decoded)
                : sprintf('Microsoft answered %d when asked for a photo.', $status));
        }

        return [
            'status' => $status,
            'bytes' => $status === 200 ? $response['body'] : '',
            'type' => $response['headers']['content-type'] ?? 'image/jpeg',
            'etag' => $response['headers']['etag'] ?? '',
        ];
    }

    /**
     * @param array<int,mixed> $options
     * @return array<string,mixed>
     */
    private static function request(string $url, array $options): array
    {
        $response = self::raw($url, $options);
        $status = $response['status'];

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Microsoft answered %d with something that is not JSON.', $status));
        }

        if ($status >= 400) {
            throw new DirectoryException($status, self::explain($status, $decoded));
        }

        return $decoded;
    }

    /**
     * The curl call itself, with the response headers kept: the JSON path
     * ignores them, the photo path needs the ETag out of them.
     *
     * @param array<int,mixed> $options
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private static function raw(string $url, array $options): array
    {
        $headers = [];

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
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($errno !== 0) {
            throw new RuntimeException('Could not reach Microsoft: ' . $error);
        }

        return ['status' => $status, 'body' => (string) $body, 'headers' => $headers];
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
