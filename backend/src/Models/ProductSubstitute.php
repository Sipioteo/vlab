<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Directional substitute relation (X can be replaced by Y). Never traversed
 * recursively: only a product's DIRECT substitutes are ever considered.
 */
class ProductSubstitute extends Model
{
    protected $table = 'product_substitutes';
    protected $guarded = [];

    protected $casts = [
        'product_id' => 'int',
        'substitute_product_id' => 'int',
        'priority' => 'int',
    ];

    public function substitute()
    {
        return $this->belongsTo(Product::class, 'substitute_product_id');
    }
}
