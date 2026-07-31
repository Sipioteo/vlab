<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'products';
    protected $guarded = [];

    protected $casts = [
        'requires_training' => 'bool',
        'is_featured' => 'bool',
        'position' => 'int',
        'category_id' => 'int',
        'min_loan_days' => 'int',
        'max_loan_days' => 'int',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function units()
    {
        return $this->hasMany(ProductUnit::class, 'product_id');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class, 'product_id');
    }

    public function logs()
    {
        return $this->hasMany(ProductLog::class, 'product_id');
    }

    /** Direct substitutes (equivalents), ordered by explicit priority. */
    public function substitutes()
    {
        return $this->hasMany(ProductSubstitute::class, 'product_id')->orderBy('priority');
    }

    public function specsArray(): ?array
    {
        if ($this->specs === null || $this->specs === '') {
            return null;
        }
        $decoded = json_decode((string) $this->specs, true);
        return is_array($decoded) ? $decoded : null;
    }
}
