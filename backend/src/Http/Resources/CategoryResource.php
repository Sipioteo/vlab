<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Database\Capsule\Manager as Capsule;

final class CategoryResource
{
    /** @return array<string,mixed> */
    public static function toArray(Category $category, ?int $productsCount = null): array
    {
        if ($productsCount === null) {
            $productsCount = (int) Capsule::table('products')
                ->where('category_id', $category->id)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'retired')
                ->count();
        }
        return [
            'id' => (int) $category->id,
            'slug' => $category->slug,
            'name' => $category->name,
            'description' => $category->description,
            'icon' => $category->icon,
            'image_url' => $category->image_url,
            'parent_id' => $category->parent_id !== null ? (int) $category->parent_id : null,
            'position' => (int) $category->position,
            'is_active' => (bool) $category->is_active,
            'products_count' => $productsCount,
        ];
    }
}
