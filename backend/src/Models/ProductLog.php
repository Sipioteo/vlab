<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductLog extends Model
{
    use SoftDeletes;

    protected $table = 'product_logs';
    protected $guarded = [];

    protected $casts = [
        'is_public' => 'bool',
        'product_id' => 'int',
        'product_unit_id' => 'int',
        'order_id' => 'int',
        'user_id' => 'int',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit()
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
