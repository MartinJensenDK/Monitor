<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;

/**
 * The single place that decides which machines a user may see.
 *
 * The twin of MonitorScope, and deliberately identical in shape: every device
 * query -- the two lists, the detail page, the live endpoint, the search
 * across installed software -- ANDs the fragment returned here into its WHERE
 * clause. One idea of sharing, so a new page cannot invent a second one.
 */
final class DeviceScope
{
    /**
     * SQL fragment restricting $alias to the machines the current user may read.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function visible(string $alias = 'd'): array
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
            return [sprintf('%s.`created_by` = :dscope_user', $alias), ['dscope_user' => $userId]];
        }

        $list = implode(',', array_map('intval', $groupIds));

        return [
            sprintf(
                '(%1$s.`created_by` = :dscope_user OR EXISTS (
                    SELECT 1 FROM {{device_group_access}} dga
                    WHERE dga.`device_id` = %1$s.`id` AND dga.`group_id` IN (%2$s)
                ))',
                $alias,
                $list
            ),
            ['dscope_user' => $userId],
        ];
    }

    /**
     * SQL fragment restricting $alias to machines the user may change or send
     * a command to.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function editable(string $alias = 'd'): array
    {
        if (Auth::isAdmin()) {
            return ['1 = 1', []];
        }

        if (!Auth::can('devices.manage')) {
            return ['1 = 0', []];
        }

        $userId = Auth::id();
        $groupIds = Auth::groupIds();
        if ($groupIds === []) {
            return [sprintf('%s.`created_by` = :dedit_user', $alias), ['dedit_user' => $userId]];
        }

        $list = implode(',', array_map('intval', $groupIds));

        return [
            sprintf(
                '(%1$s.`created_by` = :dedit_user OR EXISTS (
                    SELECT 1 FROM {{device_group_access}} dga
                    WHERE dga.`device_id` = %1$s.`id` AND dga.`group_id` IN (%2$s) AND dga.`access` = \'edit\'
                ))',
                $alias,
                $list
            ),
            ['dedit_user' => $userId],
        ];
    }
}
