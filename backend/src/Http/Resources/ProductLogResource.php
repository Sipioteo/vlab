<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductLog;
use App\Support\Dates;
use App\Support\Enums;

final class ProductLogResource
{
    /** @return array<string,mixed> */
    public static function toArray(ProductLog $log, bool $staffView): array
    {
        $unit = $log->unit;
        $order = $log->order;
        $author = $log->author;
        return [
            'id' => (int) $log->id,
            'product_id' => (int) $log->product_id,
            'product_unit_id' => $log->product_unit_id !== null ? (int) $log->product_unit_id : null,
            'unit_label' => $unit?->label,
            'order_id' => $log->order_id !== null ? (int) $log->order_id : null,
            'order_code' => $order?->code,
            'type' => $log->type,
            'type_label' => Enums::LOG_TYPE_LABELS[$log->type] ?? $log->type,
            'severity' => $log->severity,
            'title' => $log->title,
            'body' => $log->body,
            'occurred_at' => Dates::iso($log->occurred_at),
            'resolved_at' => Dates::iso($log->resolved_at),
            'is_public' => (bool) $log->is_public,
            'user' => ($staffView && $author !== null) ? [
                'id' => (int) $author->id,
                'display_name' => $author->displayName(),
                'role' => $author->role,
            ] : null,
            'created_at' => Dates::iso($log->created_at),
        ];
    }
}
