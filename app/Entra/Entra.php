<?php

declare(strict_types=1);

namespace App\Entra;

use App\Domain\Settings;
use App\Support\Env;

/**
 * Everything the Entra integration needs to know about itself: whether it is
 * switched on, which directory it talks to, and where Microsoft should send
 * people back to.
 */
final class Entra
{
    public const AUTHORITY = 'https://login.microsoftonline.com';
    public const GRAPH = 'https://graph.microsoft.com/v1.0';

    /**
     * Both endpoints can be pointed elsewhere from .env. That exists so an
     * integration like this one can be tested against a stand-in directory
     * instead of a live tenant; leave both unset in production.
     */
    public static function authority(): string
    {
        return rtrim((string) (Env::get('ENTRA_AUTHORITY_URL') ?: self::AUTHORITY), '/');
    }

    public static function graph(): string
    {
        return rtrim((string) (Env::get('ENTRA_GRAPH_URL') ?: self::GRAPH), '/');
    }

    /** Permissions the app registration needs, in the words Azure uses. */
    public const GRAPH_PERMISSIONS = ['User.Read.All', 'GroupMember.Read.All'];

    public static function ssoEnabled(): bool
    {
        return Settings::bool('entra_enabled') && self::isConfigured();
    }

    public static function syncEnabled(): bool
    {
        return Settings::bool('entra_sync_enabled') && self::isConfigured();
    }

    public static function localLoginAllowed(): bool
    {
        return Settings::bool('entra_allow_local_login') || !self::ssoEnabled();
    }

    public static function isConfigured(): bool
    {
        return self::tenantId() !== '' && self::clientId() !== '' && self::clientSecret() !== '';
    }

    public static function tenantId(): string
    {
        return trim(Settings::get('entra_tenant_id'));
    }

    public static function clientId(): string
    {
        return trim(Settings::get('entra_client_id'));
    }

    public static function clientSecret(): string
    {
        return Settings::get('entra_client_secret');
    }

    public static function issuer(): string
    {
        return sprintf('%s/%s/v2.0', self::authority(), self::tenantId());
    }

    public static function authorizeEndpoint(): string
    {
        return sprintf('%s/%s/oauth2/v2.0/authorize', self::authority(), rawurlencode(self::tenantId()));
    }

    public static function tokenEndpoint(): string
    {
        return sprintf('%s/%s/oauth2/v2.0/token', self::authority(), rawurlencode(self::tenantId()));
    }

    public static function jwksUri(): string
    {
        return sprintf('%s/%s/discovery/v2.0/keys', self::authority(), rawurlencode(self::tenantId()));
    }

    /** The exact value that has to be registered in Azure as a redirect URI. */
    public static function redirectUri(): string
    {
        return rtrim(Settings::get('site_url'), '/') . '/auth/entra/callback';
    }

    public static function defaultRole(): string
    {
        $role = Settings::get('entra_default_role', 'viewer');

        return in_array($role, ['admin', 'editor', 'viewer'], true) ? $role : 'viewer';
    }

    /**
     * Object ids of the groups an administrator chose to mirror.
     *
     * @return array<int,string>
     */
    public static function syncGroupIds(): array
    {
        $decoded = json_decode(Settings::get('entra_sync_groups', '[]'), true);

        return is_array($decoded)
            ? array_values(array_filter(array_map('strval', $decoded), static fn (string $id): bool => $id !== ''))
            : [];
    }

    /** @param array<int,string> $ids */
    public static function setSyncGroupIds(array $ids): void
    {
        Settings::set('entra_sync_groups', (string) json_encode(array_values(array_unique($ids))));
    }
}
