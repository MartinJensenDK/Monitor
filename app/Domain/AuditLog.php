<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;
use App\Core\Db;

final class AuditLog
{
    /** @param array<string,mixed> $meta */
    public static function record(string $action, string $entity, ?int $entityId, string $summary, array $meta = []): void
    {
        $user = Auth::user();

        Db::insert('audit_log', [
            'user_id' => $user === null ? null : (int) $user['id'],
            'user_label' => $user === null ? 'system' : (string) $user['email'],
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'summary' => mb_substr($summary, 0, 255),
            'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 100): array
    {
        return Db::select(
            'SELECT * FROM {{audit_log}} ORDER BY `id` DESC LIMIT ' . max(1, min(500, $limit))
        );
    }
}
