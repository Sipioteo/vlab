<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $table = 'order_items';
    protected $guarded = [];

    protected $casts = [
        'order_id' => 'int',
        'product_id' => 'int',
        'quantity' => 'int',
        'returned_quantity' => 'int',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function assignedUnits()
    {
        return $this->hasMany(OrderItemUnit::class, 'order_item_id');
    }
}
