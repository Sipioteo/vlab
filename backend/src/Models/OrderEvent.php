<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderEvent extends Model
{
    protected $table = 'order_events';
    protected $guarded = [];

    protected $casts = [
        'order_id' => 'int',
        'actor_id' => 'int',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
