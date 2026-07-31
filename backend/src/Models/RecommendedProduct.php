<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecommendedProduct extends Model
{
    protected $table = 'recommended_products';
    protected $guarded = [];

    protected $casts = [
        'product_id' => 'int',
        'recommended_product_id' => 'int',
        'position' => 'int',
    ];

    public function recommended()
    {
        return $this->belongsTo(Product::class, 'recommended_product_id');
    }
}
