<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The role sets the ceiling for what a user may do. Which monitors they may do
 * it to is decided separately by group membership (see App\Domain\MonitorScope).
 */
final class Rbac
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_VIEWER = 'viewer';

    /** @return array<int,string> */
    public static function roles(): array
    {
        return [self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_VIEWER];
    }

    /** @return array<string,array<int,string>> */
    public static function matrix(): array
    {
        $viewer = [
            'dashboard.view',
            'monitors.view',
            'incidents.view',
            'profile.edit',
        ];

        $editor = array_merge($viewer, [
            'monitors.create',
            'monitors.edit',
            'monitors.pause',
            'incidents.acknowledge',
            'locations.manage',
            'incidents.resolve',
            'notifications.edit',
        ]);

        $admin = array_merge($editor, [
            'monitors.delete',
            'users.view',
            'users.manage',
            'groups.view',
            'groups.manage',
            'settings.view',
            'settings.manage',
            'audit.view',
        ]);

        return [
            self::ROLE_VIEWER => $viewer,
            self::ROLE_EDITOR => $editor,
            self::ROLE_ADMIN => $admin,
        ];
    }

    /** @return array<int,string> */
    public static function permissions(string $role): array
    {
        return self::matrix()[$role] ?? [];
    }

    public static function allows(?string $role, string $permission): bool
    {
        if ($role === null) {
            return false;
        }

        return in_array($permission, self::permissions($role), true);
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::ROLE_ADMIN => Lang::get('role.admin'),
            self::ROLE_EDITOR => Lang::get('role.editor'),
            default => Lang::get('role.viewer'),
        };
    }
}
