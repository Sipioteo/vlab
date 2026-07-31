<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $table = 'product_images';
    protected $guarded = [];

    protected $casts = [
        'position' => 'int',
        'product_id' => 'int',
    ];
}
