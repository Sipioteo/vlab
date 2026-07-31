<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;

    protected $table = 'categories';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'bool',
        'position' => 'int',
        'parent_id' => 'int',
    ];

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
