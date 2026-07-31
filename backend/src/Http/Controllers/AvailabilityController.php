<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Calendar\CalendarService;
use App\Domain\Orders\OrderService;
use App\Domain\Settings\SettingsRepository;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\ApiException;
use App\Support\Dates;
use App\Support\Enums;
use App\Support\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AvailabilityController extends Controller
{
    public function __construct(
        private AvailabilityService $availability,
        private CalendarService $calendar,
        private SettingsRepository $settings,
        private OrderService $orders,
    ) {
    }

    /** GET /availability/products — dates → products (SPEC §7.8 #30). */
    public function products(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $user = $this->user($request);
        $isStaff = $user !== null && $user->isStaff();

        $start = isset($query['start_date']) ? (string) $query['start_date'] : null;
        $end = isset($query['end_date']) ? (string) $query['end_date'] : null;
        $errors = [];
        if ($start === null || !Dates::isValidDate($start)) {
            $errors['start_date'] = ['Il campo start_date è obbligatorio (YYYY-MM-DD).'];
        }
        if ($end === null || !Dates::isValidDate($end)) {
            $errors['end_date'] = ['Il campo end_date è obbligatorio (YYYY-MM-DD).'];
        }
        if ($errors === [] && $end < $start) {
            $errors['end_date'] = ['end_date deve essere successiva o uguale a start_date.'];
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        [$sort, $order] = self::sortParams($query, ['position', 'name', 'created_at', 'units_available', 'popularity'], 'position');
        $minQuantity = max(1, (int) ($query['min_quantity'] ?? 1));
        $includeUnavailable = self::boolParam($query, 'include_unavailable', false);
        $excludeOrderId = null;
        if (isset($query['exclude_order_id']) && $query['exclude_order_id'] !== '') {
            $excludeOrderId = $this->guardExcludeOrder((int) $query['exclude_order_id'], $user);
        }

        // Product filtering mirrors GET /products.
        $builder = Product::query()->with('category');
        if (!$isStaff) {
            $builder->where('status', '!=', 'retired');
        }
        if (isset($query['loan_mode']) && in_array((string) $query['loan_mode'], Enums::LOAN_MODES, true)) {
            $builder->where('loan_mode', (string) $query['loan_mode']);
        }
        if (isset($query['q']) && trim((string) $query['q']) !== '') {
            $like = '%' . mb_strtolower(trim((string) $query['q'])) . '%';
            $builder->where(static function ($b) use ($like) {
                $b->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(brand, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(model, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$like]);
            });
        }
        $superset = $builder->get()->all();

        $categoryId = isset($query['category_id']) ? (int) $query['category_id'] : null;
        if ($categoryId === null && isset($query['category_slug'])) {
            $cat = Category::where('slug', (string) $query['category_slug'])->first();
            $categoryId = $cat !== null ? (int) $cat->id : -1;
        }
        $brand = isset($query['brand']) && $query['brand'] !== '' ? (string) $query['brand'] : null;
        $matchesCategory = static fn ($p) => $categoryId === null || (int) $p->category_id === $categoryId;
        $matchesBrand = static fn ($p) => $brand === null || mb_strtolower((string) $p->brand) === mb_strtolower($brand);
        $filtered = array_values(array_filter($superset, static fn ($p) => $matchesCategory($p) && $matchesBrand($p)));

        $ids = array_map(static fn ($p) => (int) $p->id, $filtered);
        $maps = ProductResource::maps($ids, array_map(static fn ($p) => (int) $p->category_id, $filtered));
        $info = $this->availability->availableForRange($ids, $start, $end, $excludeOrderId);

        if (!$includeUnavailable) {
            $filtered = array_values(array_filter($filtered, static fn ($p) => ($info[(int) $p->id]['available'] ?? 0) >= $minQuantity));
        }

        usort($filtered, static function ($a, $b) use ($sort, $maps, $info) {
            switch ($sort) {
                case 'name':
                    return strcasecmp((string) $a->name, (string) $b->name);
                case 'created_at':
                    return strcmp((string) $a->created_at, (string) $b->created_at);
                case 'units_available':
                    return ($maps['units_available'][(int) $a->id] ?? 0) <=> ($maps['units_available'][(int) $b->id] ?? 0);
                default:
                    return [(int) $a->position, (string) $a->name] <=> [(int) $b->position, (string) $b->name];
            }
        });
        if ($order === 'desc') {
            $filtered = array_reverse($filtered);
        }

        $paginator = new Paginator($query);
        [$page, $meta] = $paginator->paginateArray($filtered);
        $data = [];
        foreach ($page as $product) {
            $pid = (int) $product->id;
            $item = ProductResource::summary($product, $maps);
            $item['available_quantity'] = $info[$pid]['available'] ?? 0;
            $item['capacity'] = $info[$pid]['capacity'] ?? 0;
            $item['bottleneck_date'] = $info[$pid]['bottleneck_date'] ?? null;
            $data[] = $item;
        }

        // Range validity report — never refuses (SPEC §7.8 #30).
        $violations = [];
        $pickupValid = $this->calendar->canPickup($start);
        $returnValid = $this->calendar->canReturn($end, $start);
        if (!$pickupValid || !$returnValid) {
            $violations[] = [
                'code' => 'date_not_bookable',
                'severity' => 'hard',
                'message' => 'Una delle date selezionate non è prenotabile (laboratorio chiuso o fuori finestra).',
                'limit' => null,
                'actual' => null,
                'product_ids' => [],
            ];
        }
        $duration = Dates::inclusiveDays($start, $end);
        $maxLoanDays = $this->settings->get('booking.max_loan_days', 7);
        $allowExceeding = (bool) ($this->settings->get('booking.allow_exceeding_limits', true) ?? true);
        if ($maxLoanDays !== null && $duration > (int) $maxLoanDays) {
            $violations[] = [
                'code' => 'max_loan_days_exceeded',
                'severity' => $allowExceeding ? 'soft' : 'hard',
                'message' => "La durata richiesta ({$duration} giorni) supera il limite di {$maxLoanDays} giorni.",
                'limit' => (int) $maxLoanDays,
                'actual' => $duration,
                'product_ids' => [],
            ];
        }
        $hardCap = $this->settings->get('booking.max_loan_days_hard_cap', 30);
        if ($hardCap !== null && $duration > (int) $hardCap) {
            $violations[] = [
                'code' => 'max_loan_days_hard_cap_exceeded',
                'severity' => 'hard',
                'message' => "La durata richiesta ({$duration} giorni) supera il limite invalicabile di {$hardCap} giorni.",
                'limit' => (int) $hardCap,
                'actual' => $duration,
                'product_ids' => [],
            ];
        }

        // Facets over the availability-filtered set.
        $forCategoryFacet = array_values(array_filter($superset, $matchesBrand));
        $forBrandFacet = array_values(array_filter($superset, $matchesCategory));
        $catCounts = [];
        foreach ($forCategoryFacet as $p) {
            $catCounts[(int) $p->category_id] = ($catCounts[(int) $p->category_id] ?? 0) + 1;
        }
        $categories = [];
        if ($catCounts !== []) {
            foreach (Category::whereIn('id', array_keys($catCounts))->orderBy('position')->get() as $cat) {
                $categories[] = ['id' => (int) $cat->id, 'name' => $cat->name, 'slug' => $cat->slug, 'count' => $catCounts[(int) $cat->id] ?? 0];
            }
        }
        $brandCounts = [];
        foreach ($forBrandFacet as $p) {
            if ($p->brand !== null && $p->brand !== '') {
                $brandCounts[(string) $p->brand] = ($brandCounts[(string) $p->brand] ?? 0) + 1;
            }
        }
        ksort($brandCounts, SORT_NATURAL | SORT_FLAG_CASE);
        $brands = [];
        foreach ($brandCounts as $name => $count) {
            $brands[] = ['name' => $name, 'count' => $count];
        }

        return $this->json($response, [
            'data' => $data,
            'meta' => $meta,
            'range' => ['start_date' => $start, 'end_date' => $end, 'days' => $duration],
            'range_validity' => [
                'pickup_date_valid' => $pickupValid,
                'return_date_valid' => $returnValid,
                'violations' => $violations,
            ],
            'filters' => ['categories' => $categories, 'brands' => $brands],
        ]);
    }

    /** POST /availability/dates — products → dates (SPEC §7.8 #31). */
    public function dates(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $user = $this->user($request);
        $items = $body['items'] ?? null;
        if (!is_array($items) || $items === [] || count($items) > 50) {
            throw ApiException::validation(['items' => ['Specificare da 1 a 50 articoli.']]);
        }
        foreach ($items as $item) {
            if (!is_array($item) || (int) ($item['product_id'] ?? 0) <= 0 || (int) ($item['quantity'] ?? 1) < 1) {
                throw ApiException::validation(['items' => ['Ogni articolo richiede product_id e quantity >= 1.']]);
            }
        }
        $window = $this->calendar->bookingWindow();
        $from = isset($body['from']) && Dates::isValidDate((string) $body['from']) ? (string) $body['from'] : $window['min_date'];
        $to = isset($body['to']) && Dates::isValidDate((string) $body['to']) ? (string) $body['to'] : $window['max_date'];
        if ($to < $from) {
            throw ApiException::validation(['to' => ['to deve essere successiva o uguale a from.']]);
        }
        if (Dates::inclusiveDays($from, $to) > 366) {
            $to = Dates::addDays($from, 365);
        }
        $duration = isset($body['duration_days']) && $body['duration_days'] !== null
            ? max(1, (int) $body['duration_days'])
            : (int) ($this->settings->get('booking.max_loan_days', 7) ?? 7);
        $excludeOrderId = null;
        if (isset($body['exclude_order_id']) && $body['exclude_order_id'] !== null) {
            $excludeOrderId = $this->guardExcludeOrder((int) $body['exclude_order_id'], $user);
        }
        $normalized = array_map(static fn ($i) => [
            'product_id' => (int) $i['product_id'],
            'quantity' => max(1, (int) ($i['quantity'] ?? 1)),
        ], $items);
        return $this->json($response, $this->availability->datesReport($normalized, $from, $to, $duration, $excludeOrderId));
    }

    /** POST /availability/check — pre-flight cart validation (SPEC §7.8 #32). */
    public function check(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        if (isset($body['items']) && is_array($body['items'])) {
            $items = $this->orders->normalizeItems($body['items']);
        } else {
            $cart = $this->orders->cart($user);
            $items = $this->orders->cartItemsNormalized($cart);
        }
        $excludeOrderId = null;
        if (isset($body['exclude_order_id']) && $body['exclude_order_id'] !== null) {
            $excludeOrderId = $this->guardExcludeOrder((int) $body['exclude_order_id'], $user);
        }
        $payload = $this->orders->availabilityCheck(
            $user,
            $items,
            isset($body['pickup_date']) && Dates::isValidDate((string) $body['pickup_date']) ? (string) $body['pickup_date'] : null,
            isset($body['pickup_time']) && Dates::isValidTime((string) $body['pickup_time']) ? (string) $body['pickup_time'] : null,
            isset($body['return_date']) && Dates::isValidDate((string) $body['return_date']) ? (string) $body['return_date'] : null,
            isset($body['return_time']) && Dates::isValidTime((string) $body['return_time']) ? (string) $body['return_time'] : null,
            $excludeOrderId
        );
        return $this->json($response, $payload);
    }

    private function guardExcludeOrder(int $orderId, ?User $user): ?int
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
}
