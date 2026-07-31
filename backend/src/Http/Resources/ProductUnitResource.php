<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductUnit;
use App\Support\Dates;
use App\Support\Enums;
use Illuminate\Database\Capsule\Manager as Capsule;

final class ProductUnitResource
{
    /** @return array<string,mixed> */
    public static function toArray(ProductUnit $unit): array
    {
        $current = Capsule::table('order_item_units')
            ->join('order_items', 'order_items.id', '=', 'order_item_units.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_item_units.product_unit_id', $unit->id)
            ->whereNull('order_item_units.returned_at')
            ->whereIn('orders.status', ['picked_up', 'overdue'])
            ->first(['orders.id', 'orders.code', 'orders.return_date']);
        return [
            'id' => (int) $unit->id,
            'product_id' => (int) $unit->product_id,
            'label' => $unit->label,
            'serial_number' => $unit->serial_number,
            'asset_code' => $unit->asset_code,
            'purchase_date' => Dates::datePart($unit->purchase_date),
            'inspection_date' => Dates::datePart($unit->inspection_date),
            'next_inspection_date' => Dates::datePart($unit->next_inspection_date),
            'status' => $unit->status,
            'status_label' => Enums::UNIT_STATUS_LABELS[$unit->status] ?? $unit->status,
            'condition_note' => $unit->condition_note,
            'location' => $unit->location,
            'current_order' => $current !== null ? [
                'id' => (int) $current->id,
                'code' => $current->code,
                'return_date' => Dates::datePart($current->return_date),
            ] : null,
            'created_at' => Dates::iso($unit->created_at),
        ];
    }
}
