<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Settings\SettingsRepository;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\RecommendedProduct;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Dates;
use App\Support\Enums;
use Illuminate\Database\Capsule\Manager as Capsule;

final class ProductResource
{
    /**
     * Precompute unit counts and regulation flags for a set of products.
     *
     * @param int[] $productIds
     * @param int[] $categoryIds
     * @return array<string,mixed>
     */
    public static function maps(array $productIds, array $categoryIds): array
    {
        $unitsTotal = [];
        $unitsAvailable = [];
        if ($productIds !== []) {
            foreach (Capsule::table('product_units')
                ->selectRaw('product_id, COUNT(*) as total')
                ->whereIn('product_id', $productIds)
                ->whereNull('deleted_at')
                ->groupBy('product_id')->get() as $row) {
                $unitsTotal[(int) $row->product_id] = (int) $row->total;
            }
            foreach (Capsule::table('product_units')
                ->selectRaw('product_id, COUNT(*) as total')
                ->whereIn('product_id', $productIds)
                ->where('status', 'available')
                ->whereNull('deleted_at')
                ->groupBy('product_id')->get() as $row) {
                $unitsAvailable[(int) $row->product_id] = (int) $row->total;
            }
        }
        $regulatedProducts = [];
        $regulatedCategories = [];
        foreach (Capsule::table('regulation_targets')
            ->join('regulations', 'regulations.id', '=', 'regulation_targets.regulation_id')
            ->where('regulations.is_active', true)
            ->where('regulations.requires_acceptance', true)
            ->whereNotNull('regulations.published_at')
            ->whereNull('regulations.deleted_at')
            ->get(['regulation_targets.target_type', 'regulation_targets.target_id']) as $row) {
            if ($row->target_type === 'product') {
                $regulatedProducts[(int) $row->target_id] = true;
            } else {
                $regulatedCategories[(int) $row->target_id] = true;
            }
        }
        return [
            'units_total' => $unitsTotal,
            'units_available' => $unitsAvailable,
            'regulated_products' => $regulatedProducts,
            'regulated_categories' => $regulatedCategories,
        ];
    }

    /**
     * ProductSummary (SPEC §7.4).
     *
     * @param array<string,mixed>|null $maps from self::maps(); computed ad hoc when null
     * @return array<string,mixed>
     */
    public static function summary(Product $product, ?array $maps = null): array
    {
        if ($maps === null) {
            $maps = self::maps([(int) $product->id], [(int) $product->category_id]);
        }
        $pid = (int) $product->id;
        $category = $product->category;
        return [
            'id' => $pid,
            'slug' => $product->slug,
            'name' => $product->name,
            'brand' => $product->brand,
            'model' => $product->model,
            'category' => $category !== null ? [
                'id' => (int) $category->id,
                'slug' => $category->slug,
                'name' => $category->name,
            ] : null,
            'image_url' => $product->image_url,
            'status' => $product->status,
            'loan_mode' => $product->loan_mode,
            'requires_training' => (bool) $product->requires_training,
            'units_total' => $maps['units_total'][$pid] ?? 0,
            'units_available' => $maps['units_available'][$pid] ?? 0,
            'has_required_regulations' => isset($maps['regulated_products'][$pid])
                || isset($maps['regulated_categories'][(int) $product->category_id]),
            'is_featured' => (bool) $product->is_featured,
        ];
    }

    /**
     * Product detail (SPEC §7.4), visibility-filtered by viewer.
     *
     * @return array<string,mixed>
     */
    public static function detail(Product $product, ?User $viewer, ?array $maps = null): array
    {
        $out = self::summary($product, $maps);
        $isStaff = $viewer !== null && $viewer->isStaff();
        $settings = SettingsRepository::instance();

        $images = [];
        foreach ($product->images()->orderBy('position')->get() as $image) {
            $images[] = [
                'id' => (int) $image->id,
                'url' => $image->url,
                'alt' => $image->alt,
                'position' => (int) $image->position,
            ];
        }

        $recommended = [];
        $recRows = RecommendedProduct::where('product_id', $product->id)->orderBy('position')->get();
        if ($recRows->isNotEmpty()) {
            $recProducts = Product::whereIn('id', $recRows->pluck('recommended_product_id'))->whereNull('deleted_at')->get()->keyBy('id');
            $recMaps = self::maps(
                $recProducts->pluck('id')->map(static fn ($v) => (int) $v)->all(),
                $recProducts->pluck('category_id')->map(static fn ($v) => (int) $v)->all()
            );
            foreach ($recRows as $rec) {
                $recProduct = $recProducts->get($rec->recommended_product_id);
                if ($recProduct === null || (!$isStaff && $recProduct->status === 'retired')) {
                    continue;
                }
                $recommended[] = [
                    'relation' => $rec->relation,
                    'position' => (int) $rec->position,
                    'product' => self::summary($recProduct, $recMaps),
                ];
            }
        }

        $substitutes = [];
        $subRows = ProductSubstitute::where('product_id', $product->id)->orderBy('priority')->get();
        if ($subRows->isNotEmpty()) {
            $subProducts = Product::whereIn('id', $subRows->pluck('substitute_product_id'))->whereNull('deleted_at')->get()->keyBy('id');
            $subMaps = self::maps(
                $subProducts->pluck('id')->map(static fn ($v) => (int) $v)->all(),
                $subProducts->pluck('category_id')->map(static fn ($v) => (int) $v)->all()
            );
            foreach ($subRows as $sub) {
                $subProduct = $subProducts->get($sub->substitute_product_id);
                if ($subProduct === null || (!$isStaff && $subProduct->status === 'retired')) {
                    continue;
                }
                $substitutes[] = [
                    'priority' => (int) $sub->priority,
                    'product' => self::summary($subProduct, $subMaps),
                ];
            }
        }

        $regulations = [];
        foreach (self::productRegulations($product) as $reg) {
            $regulations[] = [
                'id' => (int) $reg->id,
                'slug' => $reg->slug,
                'title' => $reg->title,
                'scope' => $reg->scope,
                'version' => (int) $reg->version,
                'requires_acceptance' => (bool) $reg->requires_acceptance,
            ];
        }

        $out += [
            'description' => $product->description,
            'specs' => $product->specsArray(),
            'images' => $images,
            'min_loan_days' => $product->min_loan_days !== null ? (int) $product->min_loan_days : null,
            'max_loan_days' => $product->max_loan_days !== null ? (int) $product->max_loan_days : null,
            'replacement_value_note' => $product->replacement_value_note,
            'source_notes' => $product->source_notes,
            'position' => (int) $product->position,
            'recommended_products' => $recommended,
            'substitutes' => $substitutes,
            'regulations' => $regulations,
        ];

        if ($isStaff) {
            $out['units'] = array_map(
                static fn ($u) => ProductUnitResource::toArray($u),
                $product->units()->whereNull('deleted_at')->orderBy('label')->get()->all()
            );
        } elseif ((bool) ($settings->get('ui.show_unit_codes_to_students', false) ?? false)) {
            $out['units'] = array_map(
                static fn ($u) => ['id' => (int) $u->id, 'label' => $u->label, 'status' => $u->status],
                $product->units()->whereNull('deleted_at')->orderBy('label')->get()->all()
            );
        }

        $logsQuery = $product->logs()->whereNull('deleted_at')->orderByDesc('occurred_at')->limit(10);
        if (!$isStaff) {
            $logsQuery->where('is_public', true);
        }
        $out['recent_logs'] = array_map(
            static fn ($log) => ProductLogResource::toArray($log, $isStaff),
            $logsQuery->get()->all()
        );

        $out['created_at'] = Dates::iso($product->created_at);
        $out['updated_at'] = Dates::iso($product->updated_at);
        return $out;
    }

    /** Active, published regulations targeting the product or its category. @return Regulation[] */
    public static function productRegulations(Product $product): array
    {
        $regIds = Capsule::table('regulation_targets')
            ->where(static function ($q) use ($product) {
                $q->where(static function ($q2) use ($product) {
                    $q2->where('target_type', 'product')->where('target_id', $product->id);
                })->orWhere(static function ($q2) use ($product) {
                    $q2->where('target_type', 'category')->where('target_id', $product->category_id);
                });
            })
            ->pluck('regulation_id')->unique()->all();
        if ($regIds === []) {
            return [];
        }
        return Regulation::whereIn('id', $regIds)
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->orderBy('position')
            ->get()->all();
    }
}
