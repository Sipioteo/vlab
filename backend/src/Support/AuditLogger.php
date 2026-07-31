<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;

final class AuditLogger
{
    /**
     * @param array{before?:mixed, after?:mixed}|null $changes
     */
    public static function log(
        ?User $user,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $changes = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);
    }
}
