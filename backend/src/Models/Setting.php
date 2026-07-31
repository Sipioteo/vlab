<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';
    protected $guarded = [];

    protected $casts = [
        'is_public' => 'bool',
        'is_secret' => 'bool',
        'nullable' => 'bool',
        'position' => 'int',
    ];
}
