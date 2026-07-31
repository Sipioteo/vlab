<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegulationTarget extends Model
{
    protected $table = 'regulation_targets';
    protected $guarded = [];

    protected $casts = [
        'regulation_id' => 'int',
        'target_id' => 'int',
    ];
}
