<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Availability\AvailabilityService;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSubstitute;
use App\Models\ProductUnit;
use App\Models\RecommendedProduct;
use App\Models\User;
use App\Support\ApiException;
use App\Support\AuditLogger;
use App\Support\Dates;
use App\Support\Enums;
use App\Support\Paginator;
use App\Support\Str;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ProductController extends Controller
{
    public function __construct(private AvailabilityService $availability)
    {
    }

    // ---------------------------------------------------------------- index

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $user = $this->user($request);
        $isStaff = $user !== null && $user->isStaff();

        [$sort, $order] = self::sortParams($query, ['position', 'name', 'created_at', 'units_available', 'popularity'], 'position');

        $availableFrom = isset($query['available_from']) && Dates::isValidDate((string) $query['available_from']) ? (string) $query['available_from'] : null;
        $availableTo = isset($query['available_to']) && Dates::isValidDate((string) $query['available_to']) ? (string) $query['available_to'] : null;
        $withAvailability = $availableFrom !== null && $availableTo !== null && $availableTo >= $availableFrom;

        // Superset (without category/brand filters) for faceting.
        $superset = $this->baseQuery($query, $isStaff)->get()->all();

        $categoryId = isset($query['category_id']) ? (int) $query['category_id'] : null;
        if ($categoryId === null && isset($query['category_slug'])) {
            $cat = Category::where('slug', (string) $query['category_slug'])->first();
            $categoryId = $cat !== null ? (int) $cat->id : -1;
        }
        $brand = isset($query['brand']) && $query['brand'] !== '' ? (string) $query['brand'] : null;

        $matchesCategory = static fn ($p) => $categoryId === null || (int) $p->category_id === $categoryId;
        $matchesBrand = static fn ($p) => $brand === null || mb_strtolower((string) $p->brand) === mb_strtolower($brand);

        $filtered = array_values(array_filter($superset, static fn ($p) => $matchesCategory($p) && $matchesBrand($p)));

        // Facets ignore their own filter (SPEC §7.7 #14).
        $forCategoryFacet = array_values(array_filter($superset, $matchesBrand));
        $forBrandFacet = array_values(array_filter($superset, $matchesCategory));

        $ids = array_map(static fn ($p) => (int) $p->id, $filtered);
        $catIds = array_map(static fn ($p) => (int) $p->category_id, $filtered);
        $maps = ProductResource::maps($ids, $catIds);

        $availabilityInfo = [];
        if ($withAvailability && $ids !== []) {
            $availabilityInfo = $this->availability->availableForRange($ids, $availableFrom, $availableTo);
            if (self::boolParam($query, 'only_available', false)) {
                $filtered = array_values(array_filter($filtered, static fn ($p) => ($availabilityInfo[(int) $p->id]['available'] ?? 0) >= 1));
            }
        }

        if (self::boolParam($query, 'has_units', false)) {
            $filtered = array_values(array_filter($filtered, static fn ($p) => ($maps['units_total'][(int) $p->id] ?? 0) > 0));
        }

        $filtered = $this->sortProducts($filtered, $sort, $order, $maps);

        $paginator = new Paginator($query);
        [$page, $meta] = $paginator->paginateArray($filtered);

        $data = [];
        foreach ($page as $product) {
            $item = ProductResource::summary($product, $maps);
            if ($withAvailability) {
                $item['available_quantity'] = $availabilityInfo[(int) $product->id]['available'] ?? 0;
            }
            $data[] = $item;
        }

        return $this->json($response, [
            'data' => $data,
            'meta' => $meta,
            'filters' => $this->facets($forCategoryFacet, $forBrandFacet),
        ]);
    }

    /** Eloquent query applying every filter EXCEPT category/brand. */
    private function baseQuery(array $query, bool $isStaff)
    {
        $builder = Product::query();
        if ($isStaff) {
            if (isset($query['status']) && in_array((string) $query['status'], Enums::PRODUCT_STATUSES, true)) {
                $builder->where('status', (string) $query['status']);
            }
        } else {
            $builder->where('status', '!=', 'retired');
        }
        if (isset($query['loan_mode']) && in_array((string) $query['loan_mode'], Enums::LOAN_MODES, true)) {
            $builder->where('loan_mode', (string) $query['loan_mode']);
        }
        $featured = self::boolParam($query, 'featured');
        if ($featured !== null) {
            $builder->where('is_featured', $featured);
        }
        if (isset($query['q']) && trim((string) $query['q']) !== '') {
            $term = mb_strtolower(trim((string) $query['q']));
            $like = '%' . $term . '%';
            $builder->where(static function ($b) use ($like) {
                $b->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(brand, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(model, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$like]);
            });
        }
        return $builder->with('category');
    }

    /** @param array<int,Product> $products */
    private function sortProducts(array $products, string $sort, string $order, array $maps): array
    {
        $popularity = [];
        if ($sort === 'popularity') {
            foreach (Capsule::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', '!=', 'cancelled')
                ->where('orders.status', '!=', 'draft')
                ->whereNull('orders.deleted_at')
                ->selectRaw('order_items.product_id as pid, COUNT(*) as cnt')
                ->groupBy('order_items.product_id')->get() as $row) {
                $popularity[(int) $row->pid] = (int) $row->cnt;
            }
        }
        usort($products, static function ($a, $b) use ($sort, $maps, $popularity) {
            switch ($sort) {
                case 'name':
                    return strcasecmp((string) $a->name, (string) $b->name);
                case 'created_at':
                    return strcmp((string) $a->created_at, (string) $b->created_at);
                case 'units_available':
                    return ($maps['units_available'][(int) $a->id] ?? 0) <=> ($maps['units_available'][(int) $b->id] ?? 0);
                case 'popularity':
                    return ($popularity[(int) $a->id] ?? 0) <=> ($popularity[(int) $b->id] ?? 0);
                default:
                    return [(int) $a->position, (string) $a->name] <=> [(int) $b->position, (string) $b->name];
            }
        });
        if ($order === 'desc') {
            $products = array_reverse($products);
        }
        return $products;
    }

    /** @return array{categories: array<int,mixed>, brands: array<int,mixed>} */
    private function facets(array $forCategoryFacet, array $forBrandFacet): array
    {
        $catCounts = [];
        foreach ($forCategoryFacet as $p) {
            $cid = (int) $p->category_id;
            $catCounts[$cid] = ($catCounts[$cid] ?? 0) + 1;
        }
        $categories = [];
        if ($catCounts !== []) {
            foreach (Category::whereIn('id', array_keys($catCounts))->orderBy('position')->get() as $cat) {
                $categories[] = [
                    'id' => (int) $cat->id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                    'count' => $catCounts[(int) $cat->id] ?? 0,
                ];
            }
        }
        $brandCounts = [];
        foreach ($forBrandFacet as $p) {
            if ($p->brand === null || $p->brand === '') {
                continue;
            }
            $brandCounts[(string) $p->brand] = ($brandCounts[(string) $p->brand] ?? 0) + 1;
        }
        ksort($brandCounts, SORT_NATURAL | SORT_FLAG_CASE);
        $brands = [];
        foreach ($brandCounts as $name => $count) {
            $brands[] = ['name' => $name, 'count' => $count];
        }
        return ['categories' => $categories, 'brands' => $brands];
    }

    // ----------------------------------------------------------------- show

    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $isStaff = $user !== null && $user->isStaff();
        $product = $this->find((string) $args['idOrSlug'], $isStaff);
        $out = ProductResource::detail($product, $user);
        $query = $request->getQueryParams();
        $from = isset($query['available_from']) && Dates::isValidDate((string) $query['available_from']) ? (string) $query['available_from'] : null;
        $to = isset($query['available_to']) && Dates::isValidDate((string) $query['available_to']) ? (string) $query['available_to'] : null;
        if ($from !== null && $to !== null && $to >= $from) {
            $info = $this->availability->availableForRange([(int) $product->id], $from, $to);
            $out['available_quantity'] = $info[(int) $product->id]['available'] ?? 0;
        }
        return $this->json($response, $out);
    }

    // ---------------------------------------------------------------- store

    public function store(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $user = $this->requireUser($request);
        $name = isset($body['name']) ? trim((string) $body['name']) : '';
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 255) {
            $errors['name'] = ['Il nome è obbligatorio (2..255 caratteri).'];
        }
        $categoryId = isset($body['category_id']) ? (int) $body['category_id'] : 0;
        if ($categoryId <= 0 || Category::find($categoryId) === null) {
            $errors['category_id'] = ['La categoria è obbligatoria e deve esistere.'];
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $slug = isset($body['slug']) && $body['slug'] !== null && $body['slug'] !== '' ? (string) $body['slug'] : null;
        if ($slug !== null) {
            if (Product::withTrashed()->where('slug', $slug)->exists()) {
                throw ApiException::conflict('duplicate_slug', 'Slug già in uso.');
            }
        } else {
            $slug = Str::uniqueSlug(Str::slug($name), static fn ($s) => Product::withTrashed()->where('slug', $s)->exists());
        }

        $product = Product::create([
            'name' => $name,
            'slug' => $slug,
            'category_id' => $categoryId,
            'brand' => $body['brand'] ?? null,
            'model' => $body['model'] ?? null,
            'description' => $body['description'] ?? null,
            'specs' => isset($body['specs']) && $body['specs'] !== null ? json_encode($body['specs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'image_url' => $body['image_url'] ?? null,
            'status' => in_array($body['status'] ?? 'available', Enums::PRODUCT_STATUSES, true) ? ($body['status'] ?? 'available') : 'available',
            'loan_mode' => in_array($body['loan_mode'] ?? 'takeaway', Enums::LOAN_MODES, true) ? ($body['loan_mode'] ?? 'takeaway') : 'takeaway',
            'requires_training' => (bool) ($body['requires_training'] ?? false),
            'min_loan_days' => isset($body['min_loan_days']) && $body['min_loan_days'] !== null ? (int) $body['min_loan_days'] : null,
            'max_loan_days' => isset($body['max_loan_days']) && $body['max_loan_days'] !== null ? (int) $body['max_loan_days'] : null,
            'replacement_value_note' => $body['replacement_value_note'] ?? null,
            'source_notes' => $body['source_notes'] ?? null,
            'position' => (int) ($body['position'] ?? 0),
            'is_featured' => (bool) ($body['is_featured'] ?? false),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        // Units: explicit array wins over initial_units; default 1 (SPEC §7.7 #16).
        if (isset($body['units']) && is_array($body['units']) && $body['units'] !== []) {
            $i = 1;
            foreach ($body['units'] as $unit) {
                $this->createUnit($product, (array) $unit, $i, $user);
                $i++;
            }
        } else {
            $count = isset($body['initial_units']) ? max(0, (int) $body['initial_units']) : 1;
            for ($i = 1; $i <= $count; $i++) {
                ProductUnit::create([
                    'product_id' => $product->id,
                    'label' => sprintf('%02d', $i),
                    'status' => 'available',
                    'created_by' => $user->id,
                ]);
            }
        }

        if (isset($body['images']) && is_array($body['images'])) {
            $this->replaceImages($product, $body['images']);
        } elseif (!empty($body['image_url'])) {
            ProductImage::create(['product_id' => $product->id, 'url' => (string) $body['image_url'], 'position' => 0]);
        }
        $this->syncPrimaryImage($product);

        if (isset($body['recommended_product_ids']) && is_array($body['recommended_product_ids'])) {
            $this->replaceRecommended($product, $body['recommended_product_ids']);
        }

        if (isset($body['substitute_product_ids']) && is_array($body['substitute_product_ids'])) {
            $this->replaceSubstitutes($product, $body['substitute_product_ids']);
        }

        AuditLogger::log($user, 'product.create', 'Product', (string) $product->id, ['after' => ['name' => $name, 'slug' => $slug]]);
        return $this->json($response, ProductResource::detail($product->refresh(), $user), 201)
            ->withHeader('Location', '/api/v1/products/' . $product->id);
    }

    // --------------------------------------------------------------- update

    public function update(Request $request, Response $response, array $args): Response
    {
        $product = Product::find((int) $args['id']);
        if ($product === null) {
            throw ApiException::notFound('Prodotto non trovato.');
        }
        $user = $this->requireUser($request);
        $body = $this->body($request);

        if (array_key_exists('name', $body) && $body['name'] !== null) {
            $name = trim((string) $body['name']);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 255) {
                throw ApiException::validation(['name' => ['Il nome deve avere 2..255 caratteri.']]);
            }
            $product->name = $name;
        }
        if (array_key_exists('slug', $body) && $body['slug'] !== null && $body['slug'] !== $product->slug) {
            if (Product::withTrashed()->where('slug', (string) $body['slug'])->where('id', '!=', $product->id)->exists()) {
                throw ApiException::conflict('duplicate_slug', 'Slug già in uso.');
            }
            $product->slug = (string) $body['slug'];
        }
        if (array_key_exists('category_id', $body) && $body['category_id'] !== null) {
            if (Category::find((int) $body['category_id']) === null) {
                throw ApiException::validation(['category_id' => ['La categoria deve esistere.']]);
            }
            $product->category_id = (int) $body['category_id'];
        }
        foreach (['brand', 'model', 'description', 'replacement_value_note', 'source_notes', 'image_url'] as $field) {
            if (array_key_exists($field, $body)) {
                $product->{$field} = $body[$field];
            }
        }
        if (array_key_exists('specs', $body)) {
            $product->specs = $body['specs'] !== null ? json_encode($body['specs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        if (array_key_exists('status', $body) && in_array((string) $body['status'], Enums::PRODUCT_STATUSES, true)) {
            $product->status = (string) $body['status'];
        }
        if (array_key_exists('loan_mode', $body) && in_array((string) $body['loan_mode'], Enums::LOAN_MODES, true)) {
            $product->loan_mode = (string) $body['loan_mode'];
        }
        if (array_key_exists('requires_training', $body) && $body['requires_training'] !== null) {
            $product->requires_training = (bool) $body['requires_training'];
        }
        foreach (['min_loan_days', 'max_loan_days'] as $field) {
            if (array_key_exists($field, $body)) {
                $product->{$field} = $body[$field] !== null ? (int) $body[$field] : null;
            }
        }
        if (array_key_exists('position', $body) && $body['position'] !== null) {
            $product->position = (int) $body['position'];
        }
        if (array_key_exists('is_featured', $body) && $body['is_featured'] !== null) {
            $product->is_featured = (bool) $body['is_featured'];
        }
        $product->updated_by = $user->id;
        $product->save();

        if (isset($body['images']) && is_array($body['images'])) {
            $this->replaceImages($product, $body['images']);
        }
        $this->syncPrimaryImage($product);

        if (isset($body['recommended_product_ids']) && is_array($body['recommended_product_ids'])) {
            $this->replaceRecommended($product, $body['recommended_product_ids']);
        }

        if (isset($body['substitute_product_ids']) && is_array($body['substitute_product_ids'])) {
            $this->replaceSubstitutes($product, $body['substitute_product_ids']);
        }

        AuditLogger::log($user, 'product.update', 'Product', (string) $product->id, null);
        return $this->json($response, ProductResource::detail($product->refresh(), $user));
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $product = Product::find((int) $args['id']);
        if ($product === null) {
            throw ApiException::notFound('Prodotto non trovato.');
        }
        $lockingOrders = Capsule::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $product->id)
            ->whereIn('orders.status', ['pending', 'approved', 'picked_up', 'overdue'])
            ->whereNull('orders.deleted_at')
            ->pluck('orders.id')->unique()->values()->all();
        if ($lockingOrders !== []) {
            throw ApiException::conflict('conflict', 'Il prodotto è presente in richieste attive.', ['order_ids' => array_map('intval', $lockingOrders)]);
        }
        $product->delete();
        AuditLogger::log($this->user($request), 'product.delete', 'Product', (string) $product->id, null);
        return $response->withStatus(204);
    }

    // ---------------------------------------------------------- recommended

    public function replaceRecommendedEndpoint(Request $request, Response $response, array $args): Response
    {
        $product = Product::find((int) $args['id']);
        if ($product === null) {
            throw ApiException::notFound('Prodotto non trovato.');
        }
        $body = $this->body($request);
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $this->replaceRecommended($product, $items);
        $data = [];
        foreach (RecommendedProduct::where('product_id', $product->id)->orderBy('position')->get() as $rec) {
            $recProduct = Product::find($rec->recommended_product_id);
            if ($recProduct === null) {
                continue;
            }
            $data[] = [
                'relation' => $rec->relation,
                'position' => (int) $rec->position,
                'product' => ProductResource::summary($recProduct),
            ];
        }
        return $this->json($response, ['data' => $data]);
    }

    // ---------------------------------------------------------- substitutes

    /** PUT /products/{id}/substitutes — mirrors the recommended endpoint. */
    public function replaceSubstitutesEndpoint(Request $request, Response $response, array $args): Response
    {
        $product = Product::find((int) $args['id']);
        if ($product === null) {
            throw ApiException::notFound('Prodotto non trovato.');
        }
        $body = $this->body($request);
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $this->replaceSubstitutes($product, $items);
        $data = [];
        foreach (ProductSubstitute::where('product_id', $product->id)->orderBy('priority')->get() as $sub) {
            $subProduct = Product::find($sub->substitute_product_id);
            if ($subProduct === null) {
                continue;
            }
            $data[] = [
                'priority' => (int) $sub->priority,
                'product' => ProductResource::summary($subProduct),
            ];
        }
        return $this->json($response, ['data' => $data]);
    }

    public function brands(Request $request, Response $response): Response
    {
        $counts = [];
        foreach (Product::whereNotNull('brand')->where('brand', '!=', '')->where('status', '!=', 'retired')->get(['brand']) as $p) {
            $counts[(string) $p->brand] = ($counts[(string) $p->brand] ?? 0) + 1;
        }
        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
        $data = [];
        foreach ($counts as $name => $count) {
            $data[] = ['name' => $name, 'products_count' => $count];
        }
        return $this->json($response, ['data' => $data, 'meta' => null]);
    }

    public function availability(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $isStaff = $user !== null && $user->isStaff();
        $product = $this->find((string) $args['id'], $isStaff);
        $query = $request->getQueryParams();
        $from = isset($query['from']) ? (string) $query['from'] : null;
        $to = isset($query['to']) ? (string) $query['to'] : null;
        $errors = [];
        if ($from === null || !Dates::isValidDate($from)) {
            $errors['from'] = ['Il campo from è obbligatorio (YYYY-MM-DD).'];
        }
        if ($to === null || !Dates::isValidDate($to)) {
            $errors['to'] = ['Il campo to è obbligatorio (YYYY-MM-DD).'];
        }
        if ($errors === [] && ($to < $from || Dates::inclusiveDays($from, $to) > 366)) {
            $errors['to'] = ['Intervallo non valido (massimo 366 giorni).'];
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $excludeOrderId = null;
        if (isset($query['exclude_order_id']) && $query['exclude_order_id'] !== '') {
            $excludeOrderId = $this->resolveExcludeOrderId((int) $query['exclude_order_id'], $user);
        }
        return $this->json($response, $this->availability->productDays((int) $product->id, $from, $to, $excludeOrderId));
    }

    /** exclude_order_id is staff/owner only (SPEC §9.2). */
    private function resolveExcludeOrderId(int $orderId, ?User $user): ?int
    {
        if ($user === null) {
            throw ApiException::forbidden('exclude_order_id richiede l\'autenticazione.');
        }
        if ($user->isStaff()) {
            return $orderId;
        }
        $order = Order::find($orderId);
        if ($order === null || (int) $order->user_id !== (int) $user->id) {
            throw ApiException::forbidden('Puoi escludere solo le tue richieste.');
        }
        return $orderId;
    }

    // -------------------------------------------------------------- helpers

    private function find(string $idOrSlug, bool $isStaff): Product
    {
        $product = ctype_digit($idOrSlug)
            ? Product::find((int) $idOrSlug)
            : Product::where('slug', $idOrSlug)->first();
        if ($product === null || (!$isStaff && $product->status === 'retired')) {
            throw ApiException::notFound('Prodotto non trovato.');
        }
        return $product;
    }

    private function createUnit(Product $product, array $data, int $fallbackIndex, User $user): ProductUnit
    {
        $label = isset($data['label']) && $data['label'] !== null && $data['label'] !== ''
            ? (string) $data['label']
            : sprintf('%02d', $fallbackIndex);
        return ProductUnit::create([
            'product_id' => $product->id,
            'label' => $label,
            'serial_number' => $data['serial_number'] ?? null,
            'asset_code' => $data['asset_code'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'inspection_date' => $data['inspection_date'] ?? null,
            'next_inspection_date' => $data['next_inspection_date'] ?? null,
            'status' => in_array($data['status'] ?? 'available', Enums::UNIT_STATUSES, true) ? ($data['status'] ?? 'available') : 'available',
            'condition_note' => $data['condition_note'] ?? null,
            'location' => $data['location'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    private function replaceImages(Product $product, array $images): void
    {
        ProductImage::where('product_id', $product->id)->delete();
        foreach ($images as $i => $image) {
            $image = (array) $image;
            if (empty($image['url'])) {
                continue;
            }
            ProductImage::create([
                'product_id' => $product->id,
                'url' => (string) $image['url'],
                'alt' => $image['alt'] ?? null,
                'position' => (int) ($image['position'] ?? $i),
            ]);
        }
    }

    private function syncPrimaryImage(Product $product): void
    {
        $primary = ProductImage::where('product_id', $product->id)->orderBy('position')->first();
        $product->image_url = $primary?->url;
        $product->save();
    }

    private function replaceRecommended(Product $product, array $items): void
    {
        foreach ($items as $item) {
            $item = (array) $item;
            if ((int) ($item['product_id'] ?? 0) === (int) $product->id) {
                throw new ApiException(422, 'self_recommendation', 'Un prodotto non può consigliare sé stesso.');
            }
        }
        RecommendedProduct::where('product_id', $product->id)->delete();
        foreach ($items as $i => $item) {
            $item = (array) $item;
            $recId = (int) ($item['product_id'] ?? 0);
            if ($recId <= 0 || Product::find($recId) === null) {
                continue;
            }
            RecommendedProduct::create([
                'product_id' => $product->id,
                'recommended_product_id' => $recId,
                'relation' => in_array($item['relation'] ?? 'accessory', Enums::RECOMMENDATION_RELATIONS, true) ? ($item['relation'] ?? 'accessory') : 'accessory',
                'position' => (int) ($item['position'] ?? $i),
            ]);
        }
    }

    /** Replaces the full substitute set (same semantics as replaceRecommended). */
    private function replaceSubstitutes(Product $product, array $items): void
    {
        foreach ($items as $item) {
            $item = (array) $item;
            if ((int) ($item['product_id'] ?? 0) === (int) $product->id) {
                throw new ApiException(422, 'self_substitution', 'Un prodotto non può sostituire sé stesso.');
            }
        }
        ProductSubstitute::where('product_id', $product->id)->delete();
        $seen = [];
        foreach ($items as $i => $item) {
            $item = (array) $item;
            $subId = (int) ($item['product_id'] ?? 0);
            if ($subId <= 0 || isset($seen[$subId]) || Product::find($subId) === null) {
                continue;
            }
            $seen[$subId] = true;
            ProductSubstitute::create([
                'product_id' => $product->id,
                'substitute_product_id' => $subId,
                'priority' => (int) ($item['priority'] ?? $i),
            ]);
        }
    }
}
