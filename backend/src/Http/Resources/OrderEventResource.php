<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderEvent;
use App\Support\Dates;
use App\Support\Enums;

final class OrderEventResource
{
    /** @return array<string,mixed> */
    public static function toArray(OrderEvent $event): array
    {
        $actor = $event->actor_type === 'user' ? $event->actor : null;
        $meta = null;
        if ($event->meta !== null && $event->meta !== '') {
            $decoded = json_decode((string) $event->meta, true);
            $meta = is_array($decoded) ? $decoded : null;
        }
        return [
            'id' => (int) $event->id,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'action' => $event->action,
            'action_label' => Enums::ACTION_LABELS[$event->action] ?? $event->action,
            'actor' => $actor !== null ? [
                'id' => (int) $actor->id,
                'display_name' => $actor->displayName(),
                'role' => $actor->role,
            ] : null,
            'actor_type' => $event->actor_type,
            'comment' => $event->comment,
            'meta' => $meta,
            'created_at' => Dates::iso($event->created_at),
        ];
    }
}
