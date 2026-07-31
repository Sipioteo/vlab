<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItemUnit extends Model
{
    protected $table = 'order_item_units';
    protected $guarded = [];

    protected $casts = [
        'order_item_id' => 'int',
        'product_unit_id' => 'int',
    ];

    public function item()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function unit()
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }
}
