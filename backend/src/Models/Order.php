<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $table = 'orders';
    protected $guarded = [];

    protected $casts = [
        'exceeds_limits' => 'bool',
        'user_id' => 'int',
        'items_count' => 'int',
        'year_sequence' => 'int',
        'late_days' => 'int',
        'decided_by' => 'int',
        'handed_over_by' => 'int',
        'received_by' => 'int',
        'cancelled_by' => 'int',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function events()
    {
        return $this->hasMany(OrderEvent::class, 'order_id');
    }

    public function limitViolationsArray(): array
    {
        if ($this->limit_violations === null || $this->limit_violations === '') {
            return [];
        }
        $decoded = json_decode((string) $this->limit_violations, true);
        return is_array($decoded) ? $decoded : [];
    }
}
