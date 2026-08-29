<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;

/**
 * Object-level checks. Rbac answers "may this role do X"; Gate answers "may
 * this user do X to this particular monitor".
 */
final class Gate
{
    /** @param array<string,mixed> $monitor */
    public static function canView(array $monitor): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }
        if ((int) ($monitor['created_by'] ?? 0) === Auth::id() && Auth::id() !== 0) {
            return true;
        }

        return self::hasGroupAccess((int) $monitor['id'], ['view', 'edit']);
    }

    /** @param array<string,mixed> $monitor */
    public static function canEdit(array $monitor): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }
        if (!Auth::can('monitors.edit')) {
            return false;
        }
        if ((int) ($monitor['created_by'] ?? 0) === Auth::id() && Auth::id() !== 0) {
            return true;
        }

        return self::hasGroupAccess((int) $monitor['id'], ['edit']);
    }

    /** @param array<string,mixed> $monitor */
    public static function canDelete(array $monitor): bool
    {
        return Auth::can('monitors.delete');
    }

    /** @param array<string,mixed> $monitor */
    public static function authorizeEdit(array $monitor): void
    {
        if (!self::canEdit($monitor)) {
            throw HttpException::forbidden('You can see this monitor, but editing it needs edit access in one of its groups.');
        }
    }

    /** @param array<int,string> $levels */
    private static function hasGroupAccess(int $monitorId, array $levels): bool
    {
        $groupIds = Auth::groupIds();
        if ($groupIds === []) {
            return false;
        }

        $groupList = implode(',', array_map('intval', $groupIds));
        $levelList = implode(',', array_map(static fn (string $l): string => "'" . $l . "'", $levels));

        return (bool) Db::value(
            sprintf(
                'SELECT 1 FROM {{monitor_group_access}} WHERE `monitor_id` = ? AND `group_id` IN (%s) AND `access` IN (%s) LIMIT 1',
                $groupList,
                $levelList
            ),
            [$monitorId]
        );
    }
}
