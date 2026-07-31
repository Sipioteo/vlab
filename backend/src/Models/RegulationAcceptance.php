<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegulationAcceptance extends Model
{
    protected $table = 'regulation_acceptances';
    protected $guarded = [];

    protected $casts = [
        'regulation_id' => 'int',
        'user_id' => 'int',
        'version' => 'int',
        'order_id' => 'int',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
