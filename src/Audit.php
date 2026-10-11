<?php
declare(strict_types=1);

namespace App;

final class Audit
{
    public static function log(?int $userId, string $action, string $entity, ?int $entityId, array $details = []): void
    {
        DB::insert(
            'INSERT INTO audit_log (user_id, action, entity, entity_id, details, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [
                $userId,
                $action,
                $entity,
                $entityId,
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                PHP_SAPI === 'cli' ? 'cli' : client_ip(),
            ]
        );
    }
}
