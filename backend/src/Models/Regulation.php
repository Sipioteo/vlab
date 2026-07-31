<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Regulation extends Model
{
    use SoftDeletes;

    protected $table = 'regulations';
    protected $guarded = [];

    protected $casts = [
        'version' => 'int',
        'requires_acceptance' => 'bool',
        'is_active' => 'bool',
        'position' => 'int',
        'file_size' => 'int',
    ];

    public function targets()
    {
        return $this->hasMany(RegulationTarget::class, 'regulation_id');
    }

    public function acceptances()
    {
        return $this->hasMany(RegulationAcceptance::class, 'regulation_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
