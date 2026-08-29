<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;
use App\Core\Rbac;

/**
 * The single place that decides which monitors a user may see.
 *
 * Every monitor query — lists, charts, incidents, the live endpoint — ANDs the
 * fragment returned here into its WHERE clause. Keeping it in one place is what
 * stops a new page from quietly leaking another team's monitors.
 */
final class MonitorScope
{
    /**
     * SQL fragment restricting $alias to the monitors the current user may read.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function visible(string $alias = 'm'): array
    {
        if (Auth::isAdmin()) {
            return ['1 = 1', []];
        }

        $userId = Auth::id();
        if ($userId === 0) {
            return ['1 = 0', []];
        }

        $groupIds = Auth::groupIds();
        if ($groupIds === []) {
            return [sprintf('%s.`created_by` = :scope_user', $alias), ['scope_user' => $userId]];
        }

        $list = implode(',', array_map('intval', $groupIds));

        $sql = sprintf(
            '(%1$s.`created_by` = :scope_user OR EXISTS (
                SELECT 1 FROM {{monitor_group_access}} mga
                WHERE mga.`monitor_id` = %1$s.`id` AND mga.`group_id` IN (%2$s)
            ))',
            $alias,
            $list
        );

        return [$sql, ['scope_user' => $userId]];
    }

    /**
     * SQL fragment restricting $alias to monitors the user may change.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function editable(string $alias = 'm'): array
    {
        if (Auth::isAdmin()) {
            return ['1 = 1', []];
        }

        if (!Auth::can('monitors.edit')) {
            return ['1 = 0', []];
        }

        $userId = Auth::id();
        $groupIds = Auth::groupIds();
        if ($groupIds === []) {
            return [sprintf('%s.`created_by` = :edit_user', $alias), ['edit_user' => $userId]];
        }

        $list = implode(',', array_map('intval', $groupIds));

        $sql = sprintf(
            '(%1$s.`created_by` = :edit_user OR EXISTS (
                SELECT 1 FROM {{monitor_group_access}} mga
                WHERE mga.`monitor_id` = %1$s.`id` AND mga.`group_id` IN (%2$s) AND mga.`access` = \'edit\'
            ))',
            $alias,
            $list
        );

        return [$sql, ['edit_user' => $userId]];
    }

    /**
     * Group ids the user may share a monitor with. A viewer gets none, an
     * editor gets the groups they can edit in, an admin gets all of them.
     *
     * @return array<int,int>
     */
    public static function assignableGroupIds(): array
    {
        if (Auth::isAdmin()) {
            return [];
        }

        return Auth::groupIds();
    }

    public static function roleAllowsEditing(): bool
    {
        return Rbac::allows(Auth::role(), 'monitors.edit');
    }
}
