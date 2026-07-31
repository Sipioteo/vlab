<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Closure extends Model
{
    use SoftDeletes;

    protected $table = 'closures';
    protected $guarded = [];

    protected $casts = [
        'blocks_pickup' => 'bool',
        'blocks_return' => 'bool',
        'is_recurring_yearly' => 'bool',
    ];
}
