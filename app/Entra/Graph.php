<?php

declare(strict_types=1);

namespace App\Entra;

use RuntimeException;

/**
 * The directory half: reads users and groups from Microsoft Graph with the
 * application's own credentials, so a sync does not depend on anyone being
 * signed in.
 */
final class Graph
{
    private static ?string $token = null;

    private static int $expiresAt = 0;

    /** Client credentials token, cached for the life of the process. */
    public static function token(): string
    {
        if (self::$token !== null && self::$expiresAt > time() + 60) {
            return self::$token;
        }

        if (!Entra::isConfigured()) {
            throw new RuntimeException('Microsoft Entra ID is not configured.');
        }

        $response = Http::postForm(Entra::tokenEndpoint(), [
            'client_id' => Entra::clientId(),
            'client_secret' => Entra::clientSecret(),
            'grant_type' => 'client_credentials',
            'scope' => 'https://graph.microsoft.com/.default',
        ]);

        $token = (string) ($response['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Microsoft returned no access token for the application.');
        }

        self::$token = $token;
        self::$expiresAt = time() + (int) ($response['expires_in'] ?? 3600);

        return $token;
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    public static function get(string $path, array $query = []): array
    {
        $url = str_starts_with($path, 'http')
            ? $path
            : Entra::graph() . '/' . ltrim($path, '/') . ($query === [] ? '' : '?' . http_build_query($query));

        return Http::getJson($url, ['Authorization' => 'Bearer ' . self::token()]);
    }

    /**
     * Follow @odata.nextLink until the collection runs out.
     *
     * @param array<string,string> $query
     * @return array<int,array<string,mixed>>
     */
    public static function collect(string $path, array $query = [], int $maxPages = 50): array
    {
        $items = [];
        $page = 0;
        $next = null;

        do {
            $response = $next === null ? self::get($path, $query) : self::get($next);
            foreach ((array) ($response['value'] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $next = isset($response['@odata.nextLink']) ? (string) $response['@odata.nextLink'] : null;
            $page++;
        } while ($next !== null && $page < $maxPages);

        return $items;
    }

    /**
     * Groups in the directory, for the picker in settings.
     *
     * @return array<int,array{id:string,name:string,description:string}>
     */
    public static function groups(string $search = ''): array
    {
        $query = ['$select' => 'id,displayName,description', '$top' => '100', '$orderby' => 'displayName'];
        if ($search !== '') {
            $escaped = str_replace("'", "''", $search);
            $query['$filter'] = sprintf("startswith(displayName,'%s')", $escaped);
            unset($query['$orderby']);
        }

        return array_map(static fn (array $g): array => [
            'id' => (string) ($g['id'] ?? ''),
            'name' => (string) ($g['displayName'] ?? ''),
            'description' => (string) ($g['description'] ?? ''),
        ], self::collect('groups', $query, 5));
    }

    /**
     * A group, or null when the directory says it does not exist. Any other
     * refusal — a missing permission, an expired secret — is thrown, so a
     * configuration problem never reads as "somebody deleted the group".
     *
     * @return array{id:string,name:string,description:string}|null
     */
    public static function group(string $objectId): ?array
    {
        try {
            $group = self::get('groups/' . rawurlencode($objectId), ['$select' => 'id,displayName,description']);
        } catch (DirectoryException $e) {
            if ($e->isNotFound()) {
                return null;
            }

            throw $e;
        }

        return [
            'id' => (string) ($group['id'] ?? ''),
            'name' => (string) ($group['displayName'] ?? ''),
            'description' => (string) ($group['description'] ?? ''),
        ];
    }

    /**
     * Members of a group. Only user objects — nested groups and service
     * principals are skipped rather than half-imported.
     *
     * @return array<int,array{id:string,name:string,email:string,enabled:bool}>
     */
    public static function groupMembers(string $objectId): array
    {
        $members = self::collect(
            'groups/' . rawurlencode($objectId) . '/members',
            ['$select' => 'id,displayName,mail,userPrincipalName,accountEnabled', '$top' => '100']
        );

        $users = [];
        foreach ($members as $member) {
            if (($member['@odata.type'] ?? '#microsoft.graph.user') !== '#microsoft.graph.user') {
                continue;
            }

            $email = strtolower(trim((string) ($member['mail'] ?? $member['userPrincipalName'] ?? '')));
            $id = (string) ($member['id'] ?? '');

            if ($id === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $users[] = [
                'id' => $id,
                'name' => trim((string) ($member['displayName'] ?? '')) ?: explode('@', $email)[0],
                'email' => $email,
                'enabled' => (bool) ($member['accountEnabled'] ?? true),
            ];
        }

        return $users;
    }

    /** A cheap call that proves the credentials and permissions work. */
    /** @return array{ok:bool,message:string,groups:int} */
    public static function test(): array
    {
        try {
            self::token();
            $groups = self::collect('groups', ['$select' => 'id', '$top' => '1'], 1);

            return [
                'ok' => true,
                'message' => 'Connected to the directory and read its groups.',
                'groups' => count($groups),
            ];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'groups' => 0];
        }
    }
}
