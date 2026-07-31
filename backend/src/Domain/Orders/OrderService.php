<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Calendar\CalendarService;
use App\Domain\Regulations\RegulationService;
use App\Domain\Settings\SettingsRepository;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\OrderItemUnit;
use App\Models\Product;
use App\Models\ProductLog;
use App\Models\ProductSubstitute;
use App\Models\ProductUnit;
use App\Models\User;
use App\Support\ApiException;
use App\Support\AuditLogger;
use App\Support\Dates;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Cart + order lifecycle (SPEC §5.6, §5.7, §7.9, §8).
 */
class OrderService
{
    public function __construct(
        private SettingsRepository $settings,
        private CalendarService $calendar,
        private AvailabilityService $availability,
        private LimitsEvaluator $limits,
        private OrderStateMachine $machine,
        private RegulationService $regulations,
    ) {
    }

    // ------------------------------------------------------------------ cart

    public function cart(User $user): Order
    {
        $this->pruneStaleCarts($user);
        return Order::firstOrCreate(['user_id' => $user->id, 'status' => 'draft']);
    }

    public function pruneStaleCarts(?User $user = null): int
    {
        $ttl = (int) ($this->settings->get('booking.cart_ttl_hours', 72) ?? 72);
        if ($ttl <= 0) {
            return 0;
        }
        $cutoff = Dates::nowUtc()->modify("-{$ttl} hours")->format('Y-m-d H:i:s');
        $query = Order::where('status', 'draft')->where('updated_at', '<', $cutoff);
        if ($user !== null) {
            $query->where('user_id', $user->id);
        }
        $stale = $query->get();
        foreach ($stale as $order) {
            OrderItem::where('order_id', $order->id)->delete();
            $order->forceDelete();
        }
        return count($stale);
    }

    public function addCartItem(User $user, int $productId, int $quantity, ?string $notes): Order
    {
        $cart = $this->cart($user);
        $product = Product::where('id', $productId)->whereNull('deleted_at')->first();
        if ($product === null || $product->status === 'retired') {
            throw ApiException::notFound('Prodotto non trovato.');
        }
        $maxQty = $this->settings->get('booking.max_quantity_per_product_per_order', 2);
        $existing = OrderItem::where('order_id', $cart->id)->where('product_id', $productId)->first();
        $newQty = ($existing?->quantity ?? 0) + $quantity;
        if ($maxQty !== null && $newQty > (int) $maxQty) {
            throw new ApiException(422, 'limit_violation', "La quantità massima per singolo prodotto è {$maxQty}.", [
                'violations' => [[
                    'code' => 'max_quantity_per_product_exceeded',
                    'severity' => 'hard',
                    'message' => "La quantità massima per singolo prodotto è {$maxQty}.",
                    'limit' => (int) $maxQty,
                    'actual' => $newQty,
                    'product_ids' => [$productId],
                ]],
            ]);
        }
        $maxItems = $this->settings->get('booking.max_items_per_order', 10);
        $distinct = OrderItem::where('order_id', $cart->id)->count();
        if ($existing === null && $maxItems !== null && $distinct + 1 > (int) $maxItems) {
            throw new ApiException(422, 'limit_violation', "Puoi richiedere al massimo {$maxItems} prodotti distinti.", [
                'violations' => [[
                    'code' => 'max_items_per_order_exceeded',
                    'severity' => 'hard',
                    'message' => "Puoi richiedere al massimo {$maxItems} prodotti distinti.",
                    'limit' => (int) $maxItems,
                    'actual' => $distinct + 1,
                    'product_ids' => [],
                ]],
            ]);
        }
        if ($existing !== null) {
            $existing->quantity = $newQty;
            if ($notes !== null) {
                $existing->notes = mb_substr($notes, 0, 255);
            }
            $existing->save();
        } else {
            OrderItem::create([
                'order_id' => $cart->id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'notes' => $notes !== null ? mb_substr($notes, 0, 255) : null,
            ]);
        }
        $this->recountItems($cart);
        return $cart->refresh();
    }

    public function updateCartItem(User $user, int $itemId, ?int $quantity, ?string $notes, bool $notesProvided): Order
    {
        $cart = $this->cart($user);
        $item = OrderItem::where('order_id', $cart->id)->where('id', $itemId)->first();
        if ($item === null) {
            throw ApiException::notFound('Articolo non trovato nel carrello.');
        }
        if ($quantity !== null) {
            if ($quantity === 0) {
                $item->delete();
                $this->recountItems($cart);
                return $cart->refresh();
            }
            $maxQty = $this->settings->get('booking.max_quantity_per_product_per_order', 2);
            if ($maxQty !== null && $quantity > (int) $maxQty) {
                throw new ApiException(422, 'limit_violation', "La quantità massima per singolo prodotto è {$maxQty}.", [
                    'violations' => [[
                        'code' => 'max_quantity_per_product_exceeded',
                        'severity' => 'hard',
                        'message' => "La quantità massima per singolo prodotto è {$maxQty}.",
                        'limit' => (int) $maxQty,
                        'actual' => $quantity,
                        'product_ids' => [(int) $item->product_id],
                    ]],
                ]);
            }
            $item->quantity = $quantity;
        }
        if ($notesProvided) {
            $item->notes = $notes !== null ? mb_substr($notes, 0, 255) : null;
        }
        $item->save();
        $this->recountItems($cart);
        return $cart->refresh();
    }

    /**
     * POST /cart/items/{itemId}/swap — atomically replace a cart item's
     * product with one of its configured substitutes (quantity and notes
     * preserved; merged into an existing row for the same product if any).
     */
    public function swapCartItem(User $user, int $itemId, int $substituteProductId): Order
    {
        $cart = $this->cart($user);
        $item = OrderItem::where('order_id', $cart->id)->where('id', $itemId)->first();
        if ($item === null) {
            throw ApiException::notFound('Articolo non trovato nel carrello.');
        }
        $substitute = Product::where('id', $substituteProductId)->whereNull('deleted_at')->first();
        if ($substitute === null || $substitute->status === 'retired') {
            throw ApiException::notFound('Prodotto non trovato.');
        }
        $isSubstitute = ProductSubstitute::where('product_id', $item->product_id)
            ->where('substitute_product_id', $substitute->id)
            ->exists();
        if (!$isSubstitute) {
            throw new ApiException(422, 'not_a_substitute', 'Questo prodotto non è tra le alternative configurate.');
        }

        Capsule::connection()->transaction(function () use ($cart, $item, $substitute) {
            $existing = OrderItem::where('order_id', $cart->id)
                ->where('product_id', $substitute->id)
                ->where('id', '!=', $item->id)
                ->first();
            $newQty = (int) $item->quantity + (int) ($existing?->quantity ?? 0);
            $maxQty = $this->settings->get('booking.max_quantity_per_product_per_order', 2);
            if ($maxQty !== null && $newQty > (int) $maxQty) {
                throw new ApiException(422, 'limit_violation', "La quantità massima per singolo prodotto è {$maxQty}.", [
                    'violations' => [[
                        'code' => 'max_quantity_per_product_exceeded',
                        'severity' => 'hard',
                        'message' => "La quantità massima per singolo prodotto è {$maxQty}.",
                        'limit' => (int) $maxQty,
                        'actual' => $newQty,
                        'product_ids' => [(int) $substitute->id],
                    ]],
                ]);
            }
            if ($existing !== null) {
                $existing->quantity = $newQty;
                if ($existing->notes === null && $item->notes !== null) {
                    $existing->notes = $item->notes;
                }
                $existing->save();
                $item->delete();
            } else {
                $item->product_id = $substitute->id;
                $item->save();
            }
        });
        $this->recountItems($cart);
        return $cart->refresh();
    }

    public function deleteCartItem(User $user, int $itemId): Order
    {
        $cart = $this->cart($user);
        $item = OrderItem::where('order_id', $cart->id)->where('id', $itemId)->first();
        if ($item === null) {
            throw ApiException::notFound('Articolo non trovato nel carrello.');
        }
        $item->delete();
        $this->recountItems($cart);
        return $cart->refresh();
    }

    /** @param array<string,mixed> $dates keys present are updated; explicit null clears. */
    public function setCartDates(User $user, array $dates): Order
    {
        $cart = $this->cart($user);
        foreach (['pickup_date', 'return_date'] as $field) {
            if (array_key_exists($field, $dates)) {
                $value = $dates[$field];
                if ($value !== null && !Dates::isValidDate((string) $value)) {
                    throw ApiException::validation([$field => ['Formato data non valido (YYYY-MM-DD).']]);
                }
                $cart->{$field} = $value;
            }
        }
        foreach (['pickup_time', 'return_time'] as $field) {
            if (array_key_exists($field, $dates)) {
                $value = $dates[$field];
                if ($value !== null && !Dates::isValidTime((string) $value)) {
                    throw ApiException::validation([$field => ['Formato orario non valido (HH:MM).']]);
                }
                $cart->{$field} = $value;
            }
        }
        $cart->save();
        return $cart->refresh();
    }

    public function clearCart(User $user): Order
    {
        $cart = $this->cart($user);
        OrderItem::where('order_id', $cart->id)->delete();
        $cart->pickup_date = null;
        $cart->pickup_time = null;
        $cart->return_date = null;
        $cart->return_time = null;
        $cart->items_count = 0;
        $cart->save();
        return $cart->refresh();
    }

    private function recountItems(Order $order): void
    {
        $order->items_count = (int) OrderItem::where('order_id', $order->id)->sum('quantity');
        $order->save();
    }

    // ------------------------------------------------- availability check ---

    /**
     * Items in the normalized internal shape, with products loaded.
     *
     * @param array<int,array<string,mixed>> $rawItems [{product_id, quantity, notes?}]
     * @return array<int,array{product_id:int, quantity:int, notes:?string, product:?Product}>
     */
    public function normalizeItems(array $rawItems): array
    {
        $ids = array_map(static fn ($i) => (int) ($i['product_id'] ?? 0), $rawItems);
        $products = Product::whereIn('id', $ids)->get()->keyBy('id');
        $out = [];
        foreach ($rawItems as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $out[] = [
                'product_id' => $pid,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'notes' => isset($item['notes']) && $item['notes'] !== null ? (string) $item['notes'] : null,
                'product' => $products->get($pid),
            ];
        }
        return $out;
    }

    /** @return array<int,array{product_id:int, quantity:int, notes:?string, product:?Product}> */
    public function cartItemsNormalized(Order $cart): array
    {
        $items = OrderItem::where('order_id', $cart->id)->orderBy('id')->get();
        return $this->normalizeItems($items->map(static fn ($i) => [
            'product_id' => $i->product_id,
            'quantity' => $i->quantity,
            'notes' => $i->notes,
        ])->all());
    }

    /**
     * The full POST /availability/check response payload (SPEC §7.8 #32).
     *
     * @param array<int,array{product_id:int, quantity:int, product:?Product}> $items normalized
     * @return array<string,mixed>
     */
    public function availabilityCheck(
        User $user,
        array $items,
        ?string $pickupDate,
        ?string $pickupTime,
        ?string $returnDate,
        ?string $returnTime,
        ?int $excludeOrderId = null
    ): array {
        $hasRange = $pickupDate !== null && $returnDate !== null && $returnDate >= $pickupDate;
        $productIds = array_map(static fn ($i) => $i['product_id'], $items);

        $availabilityByProduct = null;
        $availabilityOut = [];
        if ($hasRange && $productIds !== []) {
            $availabilityByProduct = $this->availability->availableForRange($productIds, $pickupDate, $returnDate, $excludeOrderId);
            foreach ($items as $item) {
                $info = $availabilityByProduct[$item['product_id']] ?? ['available' => 0, 'capacity' => 0];
                $availabilityOut[] = [
                    'product_id' => $item['product_id'],
                    'requested' => $item['quantity'],
                    'available' => $info['available'],
                    'capacity' => $info['capacity'],
                    'sufficient' => $info['available'] >= $item['quantity'],
                ];
            }
            $availabilityOut = $this->attachSuggestedSubstitutes($availabilityOut, $pickupDate, $returnDate, $excludeOrderId);
        }

        $violations = $this->limits->evaluate(
            $user,
            $items,
            $pickupDate,
            $pickupTime,
            $returnDate,
            $returnTime,
            $excludeOrderId,
            $availabilityByProduct
        );

        $required = $this->regulations->requiredForItems($items);
        $requiredOut = [];
        foreach ($required as $reg) {
            $requiredOut[] = [
                'id' => (int) $reg->id,
                'slug' => $reg->slug,
                'title' => $reg->title,
                'version' => (int) $reg->version,
                'accepted' => $this->regulations->hasAccepted($user, $reg),
                'scope' => $reg->scope,
            ];
        }

        $hasHard = LimitsEvaluator::hasHard($violations);
        $hasSoft = LimitsEvaluator::hasSoft($violations);

        // Quota (SPEC §7.8 #32): month/year relative to the requested pickup date
        // (falls back to today in lab tz).
        $refDate = $pickupDate ?? $this->calendar->today();
        $quota = $this->quotaFor($user, $refDate, $excludeOrderId);

        return [
            'ok' => $violations === [],
            'can_submit' => $hasRange && !$hasHard,
            'exceeds_limits' => $hasSoft,
            'violations' => $violations,
            'duration_days' => $hasRange ? Dates::inclusiveDays($pickupDate, $returnDate) : null,
            'availability' => $availabilityOut,
            'required_regulations' => $requiredOut,
            'pickup_slots' => $pickupDate !== null ? $this->calendar->pickupSlots($pickupDate) : [],
            'return_slots' => $returnDate !== null ? $this->calendar->returnSlots($returnDate) : [],
            // Display strings for the lab's default windows on the chosen days
            // (SPEC v1.4 §5.3): the frontend never recomputes settings math.
            'pickup_window' => $pickupDate !== null ? $this->calendar->windowLabel($pickupDate, 'pickup') : null,
            'return_window' => $returnDate !== null ? $this->calendar->windowLabel($returnDate, 'return') : null,
            'quota' => $quota,
        ];
    }

    /**
     * Adds `suggested_substitutes` to every INSUFFICIENT availability entry:
     * the product's DIRECT substitutes (never substitutes-of-substitutes),
     * filtered to those with enough availability in the range, ordered by
     * priority, capped at 3. One availability evaluation per candidate; no
     * traversal into the candidates' own substitute lists — non-recursive by
     * construction.
     *
     * @param array<int,array<string,mixed>> $availabilityOut
     * @return array<int,array<string,mixed>>
     */
    private function attachSuggestedSubstitutes(array $availabilityOut, string $pickupDate, string $returnDate, ?int $excludeOrderId): array
    {
        $insufficientIds = [];
        foreach ($availabilityOut as $entry) {
            if (!$entry['sufficient']) {
                $insufficientIds[] = (int) $entry['product_id'];
            }
        }
        if ($insufficientIds === []) {
            return $availabilityOut;
        }

        $subRows = ProductSubstitute::whereIn('product_id', $insufficientIds)->orderBy('priority')->get();
        if ($subRows->isEmpty()) {
            foreach ($availabilityOut as &$entry) {
                if (!$entry['sufficient']) {
                    $entry['suggested_substitutes'] = [];
                }
            }
            unset($entry);
            return $availabilityOut;
        }

        $byProduct = [];
        foreach ($subRows as $row) {
            $byProduct[(int) $row->product_id][] = $row;
        }
        $candidateIds = $subRows->pluck('substitute_product_id')->map(static fn ($v) => (int) $v)->unique()->values()->all();
        $candidates = Product::whereIn('id', $candidateIds)->whereNull('deleted_at')->where('status', '!=', 'retired')->get()->keyBy('id');
        $candidateAvailability = $this->availability->availableForRange($candidateIds, $pickupDate, $returnDate, $excludeOrderId);

        foreach ($availabilityOut as &$entry) {
            if ($entry['sufficient']) {
                continue;
            }
            $suggestions = [];
            foreach ($byProduct[(int) $entry['product_id']] ?? [] as $row) {
                if (count($suggestions) >= 3) {
                    break;
                }
                $candidate = $candidates->get((int) $row->substitute_product_id);
                if ($candidate === null) {
                    continue;
                }
                $available = (int) ($candidateAvailability[(int) $candidate->id]['available'] ?? 0);
                if ($available < (int) $entry['requested']) {
                    continue;
                }
                $suggestions[] = [
                    'product_id' => (int) $candidate->id,
                    'name' => $candidate->name,
                    'slug' => $candidate->slug,
                    'image_url' => $candidate->image_url,
                    'available_quantity' => $available,
                    'priority' => (int) $row->priority,
                ];
            }
            $entry['suggested_substitutes'] = $suggestions;
        }
        unset($entry);
        return $availabilityOut;
    }

    /** @return array<string,mixed> */
    public function quotaFor(User $user, string $refDate, ?int $excludeOrderId = null): array
    {
        $month = substr($refDate, 0, 7);
        $year = substr($refDate, 0, 4);
        $counted = ['pending', 'approved', 'picked_up', 'overdue', 'returned', 'returned_late', 'no_show'];
        $query = Order::where('user_id', $user->id)->whereIn('status', $counted)->whereNotNull('pickup_date');
        if ($excludeOrderId !== null) {
            $query->where('id', '!=', $excludeOrderId);
        }
        $dates = $query->pluck('pickup_date');
        $monthCount = 0;
        $yearCount = 0;
        foreach ($dates as $date) {
            $d = (string) Dates::datePart($date);
            if (str_starts_with($d, $month)) {
                $monthCount++;
            }
            if (str_starts_with($d, $year)) {
                $yearCount++;
            }
        }
        $activeQuery = Order::where('user_id', $user->id)->whereIn('status', ['pending', 'approved', 'picked_up', 'overdue']);
        if ($excludeOrderId !== null) {
            $activeQuery->where('id', '!=', $excludeOrderId);
        }
        $rawMonth = $this->settings->get('booking.max_orders_per_month', 4);
        $rawYear = $this->settings->get('booking.max_orders_per_year');
        $rawActive = $this->settings->get('booking.max_active_orders', 2);
        return [
            'orders_this_month' => $monthCount,
            'max_orders_per_month' => $rawMonth !== null ? (int) $rawMonth : null,
            'orders_this_year' => $yearCount,
            'max_orders_per_year' => $rawYear !== null ? (int) $rawYear : null,
            'active_orders' => (int) $activeQuery->count(),
            'max_active_orders' => $rawActive !== null ? (int) $rawActive : null,
        ];
    }

    // -------------------------------------------------------------- checkout

    /**
     * POST /api/v1/orders (SPEC §7.9 #40). Returns the created order.
     *
     * @param array<string,mixed> $payload
     */
    public function checkout(User $user, array $payload, ?string $ip, ?string $userAgent): Order
    {
        $fromCart = (bool) ($payload['from_cart'] ?? true);

        $errors = [];
        $pickupDate = isset($payload['pickup_date']) ? (string) $payload['pickup_date'] : null;
        $returnDate = isset($payload['return_date']) ? (string) $payload['return_date'] : null;
        // Time-window model (SPEC v1.4 §5.3): students never choose times.
        // `pickup_time`/`return_time` in the checkout payload are TOLERATED for
        // backward compatibility but IGNORED — the order is stored with NULL
        // times, meaning "the lab's window for that weekday". Only staff paths
        // (manual create, admin edit) may set explicit overrides.

        if ($pickupDate === null || !Dates::isValidDate($pickupDate)) {
            $errors['pickup_date'] = ['Il campo pickup_date è obbligatorio.'];
        }
        if ($returnDate === null || !Dates::isValidDate($returnDate)) {
            $errors['return_date'] = ['Il campo return_date è obbligatorio.'];
        }
        if ($pickupDate !== null && $returnDate !== null && Dates::isValidDate($pickupDate) && Dates::isValidDate($returnDate) && $returnDate < $pickupDate) {
            $errors['return_date'] = ['La data di riconsegna deve essere successiva o uguale al ritiro.'];
        }

        $subject = isset($payload['subject']) ? trim((string) $payload['subject']) : '';
        if ((bool) ($this->settings->get('booking.require_subject', true) ?? true)) {
            if (mb_strlen($subject) < 2 || mb_strlen($subject) > 191) {
                $errors['subject'] = ['Il campo materia è obbligatorio (2..191 caratteri).'];
            }
        }
        $motivation = isset($payload['motivation']) ? trim((string) $payload['motivation']) : '';
        if ((bool) ($this->settings->get('booking.require_motivation', true) ?? true)) {
            $minLen = (int) ($this->settings->get('booking.motivation_min_length', 20) ?? 20);
            if (mb_strlen($motivation) < $minLen) {
                $errors['motivation'] = ["La motivazione deve contenere almeno {$minLen} caratteri."];
            }
        }
        $professor = isset($payload['professor']) ? trim((string) $payload['professor']) : '';
        if ((bool) ($this->settings->get('booking.require_professor', false) ?? false) && $professor === '') {
            $errors['professor'] = ['Il docente di riferimento è obbligatorio.'];
        }

        $cart = null;
        if ($fromCart) {
            $cart = $this->cart($user);
            $items = $this->cartItemsNormalized($cart);
        } else {
            $rawItems = $payload['items'] ?? null;
            if (!is_array($rawItems) || $rawItems === [] || count($rawItems) > 50) {
                $errors['items'] = ['Specificare da 1 a 50 articoli.'];
                $items = [];
            } else {
                $items = $this->normalizeItems($rawItems);
            }
        }
        if ($items === [] && !isset($errors['items'])) {
            $errors['items'] = ['Il carrello è vuoto.'];
        }
        foreach ($items as $item) {
            if ($item['product'] === null || $item['product']->deleted_at !== null || $item['product']->status === 'retired') {
                $errors['items'] = ['Uno o più prodotti non sono disponibili.'];
                break;
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        // ---- regulations ----------------------------------------------------
        $acceptedIds = array_map('intval', (array) ($payload['accepted_regulation_ids'] ?? []));
        $requiredRegs = array_merge(
            $this->regulations->pendingGlobalFor($user),
            $this->regulations->requiredForItems($items)
        );
        $toAccept = [];
        $missing = [];
        $seen = [];
        foreach ($requiredRegs as $reg) {
            if (isset($seen[$reg->id])) {
                continue;
            }
            $seen[$reg->id] = true;
            if ($this->regulations->hasAccepted($user, $reg)) {
                continue;
            }
            if (in_array((int) $reg->id, $acceptedIds, true)) {
                $toAccept[] = $reg;
            } else {
                $missing[] = (int) $reg->id;
            }
        }
        if ($missing !== []) {
            throw new ApiException(409, 'regulation_acceptance_required', 'Devi accettare i regolamenti richiesti prima di inviare la richiesta.', [
                'regulation_ids' => $missing,
            ]);
        }

        $acknowledge = (bool) ($payload['acknowledge_exceeds_limits'] ?? false);
        $notes = isset($payload['notes']) && $payload['notes'] !== null ? mb_substr((string) $payload['notes'], 0, 2000) : null;

        return Capsule::connection()->transaction(function () use (
            $user, $items, $cart, $fromCart, $pickupDate, $returnDate,
            $subject, $motivation, $professor, $notes, $acknowledge, $toAccept, $ip, $userAgent
        ) {
            $this->lockForAvailability();
            $productIds = array_map(static fn ($i) => $i['product_id'], $items);
            $availabilityByProduct = $this->availability->availableForRange($productIds, $pickupDate, $returnDate, $cart?->id);
            $violations = $this->limits->evaluate(
                $user,
                $items,
                $pickupDate,
                null,
                $returnDate,
                null,
                $cart?->id,
                $availabilityByProduct
            );

            foreach ($violations as $v) {
                if ($v['code'] === 'date_not_bookable') {
                    throw new ApiException(422, 'date_not_bookable', $v['message'], [
                        'field' => 'pickup_date',
                        'suggestions' => $this->calendar->suggestPickupDates((string) $pickupDate),
                    ]);
                }
            }
            foreach ($violations as $v) {
                if ($v['code'] === 'insufficient_availability') {
                    $products = [];
                    foreach ($items as $item) {
                        $info = $availabilityByProduct[$item['product_id']] ?? ['available' => 0];
                        if ($item['quantity'] > $info['available']) {
                            $products[] = [
                                'product_id' => $item['product_id'],
                                'requested' => $item['quantity'],
                                'available' => $info['available'],
                            ];
                        }
                    }
                    throw new ApiException(409, 'insufficient_availability', 'La disponibilità non è più sufficiente per alcuni prodotti.', [
                        'products' => $products,
                    ]);
                }
            }
            $hard = array_values(array_filter($violations, static fn ($v) => $v['severity'] === 'hard'));
            if ($hard !== []) {
                throw new ApiException(422, 'limit_violation', 'La richiesta non rispetta i limiti di prestito.', [
                    'violations' => $violations,
                ]);
            }
            $soft = array_values(array_filter($violations, static fn ($v) => $v['severity'] === 'soft'));
            if ($soft !== [] && !$acknowledge) {
                throw new ApiException(422, 'limit_violation', 'La richiesta supera i limiti di prestito: conferma per procedere.', [
                    'violations' => $violations,
                ]);
            }

            $order = $fromCart && $cart !== null ? $cart : new Order(['user_id' => $user->id]);

            [$code, $sequence] = $this->nextOrderCode();
            $now = Dates::nowDb();
            $order->user_id = $user->id;
            $order->status = 'pending';
            $order->code = $code;
            $order->year_sequence = $sequence;
            $order->pickup_date = $pickupDate;
            $order->pickup_time = null;
            $order->pickup_time_end = null;
            $order->return_date = $returnDate;
            $order->return_time = null;
            $order->return_time_end = null;
            $order->subject = $subject !== '' ? $subject : null;
            $order->motivation = $motivation !== '' ? $motivation : null;
            $order->professor = $professor !== '' ? $professor : null;
            $order->notes = $notes;
            $order->exceeds_limits = $soft !== [];
            $order->limit_violations = json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $order->submitted_at = $now;
            $order->save();

            if (!$fromCart) {
                foreach ($items as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'notes' => $item['notes'] !== null ? mb_substr($item['notes'], 0, 255) : null,
                    ]);
                }
            }

            // Snapshot product names/brands.
            foreach (OrderItem::where('order_id', $order->id)->get() as $orderItem) {
                $product = Product::find($orderItem->product_id);
                $orderItem->product_name_snapshot = $product?->name;
                $orderItem->product_brand_snapshot = $product?->brand;
                $orderItem->save();
            }
            $this->recountItems($order);

            foreach ($toAccept as $reg) {
                $this->regulations->accept($user, $reg, (int) $order->id, $ip, $userAgent);
            }

            $this->writeEvent($order, 'draft', 'pending', 'submit', $user, null, null);
            return $order->refresh();
        });
    }

    // ------------------------------------------------------- manual creation

    /**
     * POST /api/v1/orders/manual — a staff member registers a loan on behalf
     * of a student (walk-in at the counter, phone booking, after-the-fact
     * correction). Mirrors checkout for the order side effects and
     * editOrderFull for the availability/force semantics:
     *
     * - availability re-checked inside the transaction (§2 concurrency rule);
     *   shortfall → 422 `insufficient_availability` with details.products,
     *   overridable with `force: true` (admin only → flagged forced_overbook);
     * - LimitsEvaluator runs and records exceeds_limits/limit_violations but
     *   never blocks a staff action;
     * - regulation acceptance is NOT enforced (the signature on the printed
     *   module covers it) — pending regulations are reported by the caller;
     * - if initial_status is `approved` (the default), the pending→approved
     *   transition goes through the state machine so the approval event and
     *   actor are recorded coherently.
     *
     * @param array<string,mixed> $payload
     * @return array{0:Order, 1:?array} [order, overbooked products or null]
     */
    public function createManual(User $actor, User $target, array $payload): array
    {
        $force = (bool) ($payload['force'] ?? false);
        if ($force && $actor->role !== 'admin') {
            throw ApiException::forbidden('Solo un amministratore può forzare la creazione senza disponibilità.');
        }

        $errors = [];
        // Contract names are start_date/end_date; the checkout aliases are
        // accepted too so staff tooling can reuse existing payload builders.
        $pickupDate = isset($payload['start_date']) ? (string) $payload['start_date']
            : (isset($payload['pickup_date']) ? (string) $payload['pickup_date'] : null);
        $returnDate = isset($payload['end_date']) ? (string) $payload['end_date']
            : (isset($payload['return_date']) ? (string) $payload['return_date'] : null);
        // Times are OPTIONAL overrides (SPEC v1.4 §5.3): NULL = the lab's
        // window for that weekday; time alone = precise appointment; time +
        // *_time_end = custom range. Staff-provided times are honored.
        $times = [];
        foreach (['pickup_time', 'pickup_time_end', 'return_time', 'return_time_end'] as $f) {
            $value = isset($payload[$f]) && $payload[$f] !== '' ? $payload[$f] : null;
            if ($value !== null && !Dates::isValidTime((string) $value)) {
                $errors[$f] = ['Formato orario non valido (HH:MM).'];
                $value = null;
            }
            $times[$f] = $value !== null ? (string) $value : null;
        }

        if ($pickupDate === null || !Dates::isValidDate($pickupDate)) {
            $errors['start_date'] = ['Il campo start_date è obbligatorio (YYYY-MM-DD).'];
        }
        if ($returnDate === null || !Dates::isValidDate($returnDate)) {
            $errors['end_date'] = ['Il campo end_date è obbligatorio (YYYY-MM-DD).'];
        }
        if ($pickupDate !== null && $returnDate !== null
            && Dates::isValidDate($pickupDate) && Dates::isValidDate($returnDate)
            && $returnDate < $pickupDate) {
            $errors['end_date'] = ['La data di riconsegna deve essere successiva o uguale al ritiro.'];
        }

        $initialStatus = (string) ($payload['initial_status'] ?? 'approved');
        if (!in_array($initialStatus, ['approved', 'pending'], true)) {
            $errors['initial_status'] = ['Stato iniziale non valido: approved o pending.'];
        }

        $rawItems = $payload['items'] ?? null;
        $items = [];
        if (!is_array($rawItems) || $rawItems === [] || count($rawItems) > 50) {
            $errors['items'] = ['Specificare da 1 a 50 articoli.'];
        } else {
            $items = $this->normalizeItems($rawItems);
            foreach ($items as $item) {
                if ($item['product'] === null || $item['product']->deleted_at !== null || $item['product']->status === 'retired') {
                    $errors['items'] = ['Uno o più prodotti non sono disponibili.'];
                    break;
                }
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $this->assertTimeOverrides($pickupDate, $returnDate, $times, $force);

        $optional = [];
        foreach (['subject', 'professor', 'motivation', 'notes', 'staff_notes'] as $f) {
            $value = isset($payload[$f]) && $payload[$f] !== null ? trim((string) $payload[$f]) : '';
            $optional[$f] = $value !== '' ? mb_substr($value, 0, $f === 'subject' || $f === 'professor' ? 191 : 2000) : null;
        }

        $comment = isset($payload['comment']) && $payload['comment'] !== null ? (string) $payload['comment'] : null;

        return Capsule::connection()->transaction(function () use (
            $actor, $target, $items, $pickupDate, $returnDate, $times,
            $optional, $initialStatus, $force, $comment
        ) {
            $this->lockForAvailability();

            // ---- availability, re-checked inside the transaction ------------
            $productIds = array_map(static fn ($i) => $i['product_id'], $items);
            $availabilityByProduct = $this->availability->availableForRange($productIds, $pickupDate, $returnDate, null);
            $short = [];
            foreach ($items as $item) {
                $info = $availabilityByProduct[$item['product_id']] ?? ['available' => 0];
                if ($item['quantity'] > $info['available']) {
                    $short[] = [
                        'product_id' => $item['product_id'],
                        'name' => $item['product']?->name,
                        'requested' => $item['quantity'],
                        'available' => (int) $info['available'],
                    ];
                }
            }
            $overbook = null;
            if ($short !== []) {
                if (!$force) {
                    throw new ApiException(422, 'insufficient_availability', 'La disponibilità non è sufficiente per alcuni prodotti nel periodo selezionato.', [
                        'products' => $short,
                    ]);
                }
                $overbook = $short;
            }

            // ---- limits: recorded on the order, never blocking staff --------
            $violations = $this->limits->evaluate(
                $target,
                $items,
                $pickupDate,
                $times['pickup_time'],
                $returnDate,
                $times['return_time'],
                null,
                $availabilityByProduct
            );

            [$code, $sequence] = $this->nextOrderCode();
            $order = new Order([
                'user_id' => $target->id,
                'status' => 'pending',
                'code' => $code,
                'year_sequence' => $sequence,
                'pickup_date' => $pickupDate,
                'pickup_time' => $times['pickup_time'],
                'pickup_time_end' => $times['pickup_time_end'],
                'return_date' => $returnDate,
                'return_time' => $times['return_time'],
                'return_time_end' => $times['return_time_end'],
                'subject' => $optional['subject'],
                'motivation' => $optional['motivation'],
                'professor' => $optional['professor'],
                'notes' => $optional['notes'],
                'staff_notes' => $optional['staff_notes'],
                'exceeds_limits' => LimitsEvaluator::hasSoft($violations),
                'limit_violations' => json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'submitted_at' => Dates::nowDb(),
            ]);
            $order->save();

            foreach ($items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] !== null ? mb_substr($item['notes'], 0, 255) : null,
                    'product_name_snapshot' => $item['product']->name,
                    'product_brand_snapshot' => $item['product']->brand,
                ]);
            }
            $this->recountItems($order);

            // Birth event: `create` by the staff actor, marked as manual.
            $meta = ['manual' => true, 'created_by' => (int) $actor->id];
            if ($overbook !== null) {
                $meta['forced'] = true;
                $meta['overbooked_products'] = $overbook;
            }
            $this->writeEvent($order, null, 'pending', 'create', $actor, $comment, $meta);

            if ($initialStatus === 'approved') {
                // Through the state machine, so the approval actor/event are
                // recorded exactly like a queue approval. Availability is not
                // re-asserted here: it was checked (or force-overridden) above,
                // in this same transaction.
                $this->machine->assertCan($order, 'approve', $actor);
                $order->status = 'approved';
                $order->decided_by = $actor->id;
                $order->decided_at = Dates::nowDb();
                $order->save();
                $this->writeEvent($order, 'pending', 'approved', 'approve', $actor, null, null);
            }

            $auditChanges = [
                'after' => [
                    'user_id' => (int) $target->id,
                    'user_ldap_uid' => (string) $target->ldap_uid,
                    'code' => $code,
                    'pickup_date' => $pickupDate,
                    'return_date' => $returnDate,
                    'initial_status' => $initialStatus,
                    'items' => array_map(static fn ($i) => [
                        'product_id' => $i['product_id'],
                        'quantity' => $i['quantity'],
                    ], $items),
                ],
            ];
            if ($overbook !== null) {
                $auditChanges['forced_overbook'] = $overbook;
            }
            AuditLogger::log($actor, 'order.create_manual', 'Order', (string) $order->id, $auditChanges);

            return [$order->refresh(), $overbook];
        });
    }

    /** @return array{0:string, 1:int} [code, year_sequence] */
    private function nextOrderCode(): array
    {
        $year = substr($this->calendar->today(), 0, 4);
        $max = (int) Capsule::table('orders')
            ->where('code', 'like', "VL-{$year}-%")
            ->max('year_sequence');
        $sequence = $max + 1;
        return [sprintf('VL-%s-%04d', $year, $sequence), $sequence];
    }

    private function lockForAvailability(): void
    {
        // On MySQL/PG take row locks; SQLite serializes writes at the file level.
        $driver = Capsule::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            Capsule::table('products')->lockForUpdate()->limit(1)->get();
        }
    }

    // ----------------------------------------------------------- transitions

    public function approve(Order $order, User $actor, array $payload): Order
    {
        $this->machine->assertCan($order, 'approve', $actor);
        return Capsule::connection()->transaction(function () use ($order, $actor, $payload) {
            foreach (['pickup_date', 'return_date'] as $f) {
                if (isset($payload[$f]) && $payload[$f] !== null) {
                    if (!Dates::isValidDate((string) $payload[$f])) {
                        throw ApiException::validation([$f => ['Formato data non valido.']]);
                    }
                    $order->{$f} = (string) $payload[$f];
                }
            }
            foreach (['pickup_time', 'return_time'] as $f) {
                if (isset($payload[$f]) && $payload[$f] !== null) {
                    if (!Dates::isValidTime((string) $payload[$f])) {
                        throw ApiException::validation([$f => ['Formato orario non valido.']]);
                    }
                    $order->{$f} = (string) $payload[$f];
                }
            }
            $this->assertOrderAvailability($order);
            $order->status = 'approved';
            $order->decided_by = $actor->id;
            $order->decided_at = Dates::nowDb();
            if (array_key_exists('staff_notes', $payload) && $payload['staff_notes'] !== null) {
                $order->staff_notes = (string) $payload['staff_notes'];
            }
            $order->save();
            $this->writeEvent($order, 'pending', 'approved', 'approve', $actor, $payload['comment'] ?? null, null);
            return $order->refresh();
        });
    }

    public function reject(Order $order, User $actor, string $reason, ?string $comment = null): Order
    {
        $this->machine->assertCan($order, 'reject', $actor);
        $order->status = 'rejected';
        $order->rejection_reason = $reason;
        $order->decided_by = $actor->id;
        $order->decided_at = Dates::nowDb();
        $order->save();
        $this->writeEvent($order, 'pending', 'rejected', 'reject', $actor, $comment ?? $reason, null);
        return $order->refresh();
    }

    public function cancel(Order $order, User $actor, ?string $reason): Order
    {
        $this->machine->assertCan($order, 'cancel', $actor);
        $from = (string) $order->status;
        $order->status = 'cancelled';
        $order->cancelled_by = $actor->id;
        $order->cancelled_at = Dates::nowDb();
        $order->save();
        $this->writeEvent($order, $from, 'cancelled', 'cancel', $actor, $reason, null);
        return $order->refresh();
    }

    public function markNoShow(Order $order, User $actor, ?string $comment): Order
    {
        $this->machine->assertCan($order, 'mark_no_show', $actor);
        $order->status = 'no_show';
        $order->save();
        $this->writeEvent($order, 'approved', 'no_show', 'mark_no_show', $actor, $comment, null);
        return $order->refresh();
    }

    /** @param array<string,mixed> $payload */
    public function pickup(Order $order, User $actor, array $payload): Order
    {
        $this->machine->assertCan($order, 'pickup', $actor);
        return Capsule::connection()->transaction(function () use ($order, $actor, $payload) {
            $pickedUpAt = isset($payload['picked_up_at']) && $payload['picked_up_at'] !== null
                ? (new DateTimeImmutable((string) $payload['picked_up_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
                : Dates::nowDb();
            $meta = null;
            $items = OrderItem::where('order_id', $order->id)->get()->keyBy('id');

            $assignments = $payload['assignments'] ?? null;
            if (is_array($assignments) && $assignments !== []) {
                foreach ($assignments as $assignment) {
                    $itemId = (int) ($assignment['order_item_id'] ?? 0);
                    $item = $items->get($itemId);
                    if ($item === null) {
                        throw ApiException::validation(['assignments' => ['Articolo non appartenente alla richiesta.']]);
                    }
                    $unitIds = array_map('intval', (array) ($assignment['product_unit_ids'] ?? []));
                    if (count($unitIds) !== (int) $item->quantity) {
                        throw ApiException::validation(['assignments' => ['Il numero di unità deve corrispondere alla quantità richiesta.']]);
                    }
                    foreach ($unitIds as $unitId) {
                        $unit = ProductUnit::where('id', $unitId)->whereNull('deleted_at')->first();
                        if ($unit === null || (int) $unit->product_id !== (int) $item->product_id) {
                            throw ApiException::validation(['assignments' => ['Unità non valida per il prodotto richiesto.']]);
                        }
                        if ($unit->status !== 'available') {
                            throw ApiException::conflict('unit_in_use', 'Una o più unità non sono prestabili.', ['product_unit_id' => $unitId]);
                        }
                        if ($this->unitBusy($unitId)) {
                            throw ApiException::conflict('unit_in_use', 'Una o più unità risultano già assegnate ad un altro prestito.', ['product_unit_id' => $unitId]);
                        }
                        OrderItemUnit::create([
                            'order_item_id' => $item->id,
                            'product_unit_id' => $unitId,
                            'assigned_at' => $pickedUpAt,
                            'condition_out' => isset($assignment['condition_out']) ? (string) $assignment['condition_out'] : 'ok',
                            'note' => isset($assignment['note']) && $assignment['note'] !== null ? mb_substr((string) $assignment['note'], 0, 255) : null,
                        ]);
                    }
                }
            } elseif ((bool) ($this->settings->get('booking.auto_assign_units_on_pickup', true) ?? true)) {
                $partial = false;
                foreach ($items as $item) {
                    $units = ProductUnit::where('product_id', $item->product_id)
                        ->where('status', 'available')
                        ->whereNull('deleted_at')
                        ->orderBy('label')
                        ->get();
                    $assigned = 0;
                    foreach ($units as $unit) {
                        if ($assigned >= (int) $item->quantity) {
                            break;
                        }
                        if ($this->unitBusy((int) $unit->id)) {
                            continue;
                        }
                        OrderItemUnit::create([
                            'order_item_id' => $item->id,
                            'product_unit_id' => $unit->id,
                            'assigned_at' => $pickedUpAt,
                            'condition_out' => 'ok',
                        ]);
                        $assigned++;
                    }
                    if ($assigned < (int) $item->quantity) {
                        $partial = true;
                    }
                }
                if ($partial) {
                    $meta = ['auto_assignment' => 'partial'];
                }
            }

            $order->status = 'picked_up';
            $order->picked_up_at = $pickedUpAt;
            $order->handed_over_by = $actor->id;
            $order->save();
            $this->writeEvent($order, 'approved', 'picked_up', 'pickup', $actor, $payload['comment'] ?? null, $meta);
            return $order->refresh();
        });
    }

    private function unitBusy(int $unitId): bool
    {
        return Capsule::table('order_item_units')
            ->join('order_items', 'order_items.id', '=', 'order_item_units.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_item_units.product_unit_id', $unitId)
            ->whereNull('order_item_units.returned_at')
            ->whereIn('orders.status', ['picked_up', 'overdue'])
            ->exists();
    }

    /** @param array<string,mixed> $payload */
    public function returnOrder(Order $order, User $actor, array $payload): Order
    {
        $this->machine->assertCan($order, 'return', $actor);
        return Capsule::connection()->transaction(function () use ($order, $actor, $payload) {
            $from = (string) $order->status;
            $returnedAt = isset($payload['returned_at']) && $payload['returned_at'] !== null
                ? (new DateTimeImmutable((string) $payload['returned_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
                : Dates::nowDb();

            $items = OrderItem::where('order_id', $order->id)->get()->keyBy('id');
            $returns = $payload['returns'] ?? null;

            if (is_array($returns) && $returns !== []) {
                foreach ($returns as $ret) {
                    $item = $items->get((int) ($ret['order_item_id'] ?? 0));
                    if ($item === null) {
                        throw ApiException::validation(['returns' => ['Articolo non appartenente alla richiesta.']]);
                    }
                    $item->returned_quantity = isset($ret['returned_quantity'])
                        ? max(0, (int) $ret['returned_quantity'])
                        : (int) $item->quantity;
                    $item->save();
                    foreach ((array) ($ret['units'] ?? []) as $unitRet) {
                        $unitId = (int) ($unitRet['product_unit_id'] ?? 0);
                        $assignment = OrderItemUnit::where('order_item_id', $item->id)
                            ->where('product_unit_id', $unitId)
                            ->first();
                        if ($assignment === null) {
                            throw ApiException::validation(['returns' => ['Unità non assegnata a questo articolo.']]);
                        }
                        $conditionIn = isset($unitRet['condition_in']) ? (string) $unitRet['condition_in'] : 'ok';
                        $assignment->returned_at = $returnedAt;
                        $assignment->condition_in = $conditionIn;
                        if (isset($unitRet['note']) && $unitRet['note'] !== null) {
                            $assignment->note = mb_substr((string) $unitRet['note'], 0, 255);
                        }
                        $assignment->save();
                        $this->applyConditionIn($unitId, $conditionIn, $actor);
                    }
                }
                // Close any remaining open unit assignments.
                foreach ($items as $item) {
                    OrderItemUnit::where('order_item_id', $item->id)
                        ->whereNull('returned_at')
                        ->update(['returned_at' => $returnedAt]);
                }
            } else {
                foreach ($items as $item) {
                    $item->returned_quantity = (int) $item->quantity;
                    $item->save();
                    OrderItemUnit::where('order_item_id', $item->id)
                        ->whereNull('returned_at')
                        ->update(['returned_at' => $returnedAt]);
                }
            }

            // Product logs created in the same round-trip.
            foreach ((array) ($payload['logs'] ?? []) as $log) {
                if (!isset($log['product_id'], $log['type'], $log['title'])) {
                    continue;
                }
                ProductLog::create([
                    'product_id' => (int) $log['product_id'],
                    'product_unit_id' => isset($log['product_unit_id']) && $log['product_unit_id'] !== null ? (int) $log['product_unit_id'] : null,
                    'order_id' => $order->id,
                    'user_id' => $actor->id,
                    'type' => (string) $log['type'],
                    'severity' => (string) ($log['severity'] ?? 'info'),
                    'title' => mb_substr((string) $log['title'], 0, 191),
                    'body' => isset($log['body']) && $log['body'] !== null ? (string) $log['body'] : null,
                    'occurred_at' => $returnedAt,
                    'is_public' => (bool) ($log['is_public'] ?? true),
                ]);
            }

            // Late computation in lab timezone.
            $tz = new DateTimeZone($this->calendar->timezone());
            $returnedDate = (new DateTimeImmutable($returnedAt, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
            $dueDate = (string) Dates::datePart($order->return_date);
            $lateDays = max(0, Dates::diffDays($dueDate, $returnedDate));
            $isLate = $from === 'overdue' || $returnedDate > $dueDate;

            $order->status = $isLate ? 'returned_late' : 'returned';
            $order->returned_at = $returnedAt;
            $order->received_by = $actor->id;
            $order->late_days = $isLate ? $lateDays : null;
            $order->save();
            $this->writeEvent($order, $from, (string) $order->status, 'return', $actor, $payload['comment'] ?? null, $isLate ? ['late_days' => $lateDays] : null);
            return $order->refresh();
        });
    }

    private function applyConditionIn(int $unitId, string $conditionIn, User $actor): void
    {
        $newStatus = match ($conditionIn) {
            'damaged' => 'maintenance',
            'missing' => 'missing',
            default => null,
        };
        if ($newStatus !== null) {
            $unit = ProductUnit::find($unitId);
            if ($unit !== null && $unit->status !== $newStatus) {
                $before = $unit->status;
                $unit->status = $newStatus;
                $unit->save();
                AuditLogger::log($actor, 'unit.status_change', 'ProductUnit', (string) $unitId, [
                    'before' => ['status' => $before],
                    'after' => ['status' => $newStatus],
                ]);
            }
        }
    }

    public function reopen(Order $order, User $actor, string $toStatus, string $reason): Order
    {
        $this->machine->assertCan($order, 'reopen', $actor);
        if (!in_array($toStatus, ['pending', 'approved', 'picked_up'], true)) {
            throw ApiException::validation(['to_status' => ['Stato di destinazione non valido.']]);
        }
        return Capsule::connection()->transaction(function () use ($order, $actor, $toStatus, $reason) {
            $from = (string) $order->status;
            $this->assertOrderAvailability($order);
            $order->status = $toStatus;
            if ($toStatus !== 'picked_up') {
                $order->returned_at = null;
                $order->received_by = null;
                $order->late_days = null;
            }
            if ($toStatus === 'pending') {
                $order->decided_by = null;
                $order->decided_at = null;
            }
            $order->cancelled_by = null;
            $order->cancelled_at = null;
            $order->rejection_reason = null;
            $order->save();
            $this->writeEvent($order, $from, $toStatus, 'reopen', $actor, $reason, ['reason' => $reason]);
            AuditLogger::log($actor, 'order.reopen', 'Order', (string) $order->id, [
                'before' => ['status' => $from],
                'after' => ['status' => $toStatus, 'reason' => $reason],
            ]);
            return $order->refresh();
        });
    }

    /** @param array<string,mixed> $payload PUT /orders/{id} (SPEC §7.9 #43). */
    public function editOrder(Order $order, User $actor, array $payload): Order
    {
        $this->machine->assertCan($order, 'edit', $actor);
        return Capsule::connection()->transaction(function () use ($order, $actor, $payload) {
            $changes = [];
            foreach (['pickup_date', 'return_date'] as $f) {
                if (array_key_exists($f, $payload) && $payload[$f] !== null) {
                    if (!Dates::isValidDate((string) $payload[$f])) {
                        throw ApiException::validation([$f => ['Formato data non valido.']]);
                    }
                    if ((string) $payload[$f] !== (string) Dates::datePart($order->{$f})) {
                        $changes[$f] = ['before' => Dates::datePart($order->{$f}), 'after' => $payload[$f]];
                        $order->{$f} = (string) $payload[$f];
                    }
                }
            }
            // Explicit null clears an override → back to the lab's weekday window.
            foreach (['pickup_time', 'pickup_time_end', 'return_time', 'return_time_end'] as $f) {
                if (array_key_exists($f, $payload)) {
                    $value = $payload[$f] !== null && $payload[$f] !== '' ? (string) $payload[$f] : null;
                    if ($value !== null && !Dates::isValidTime($value)) {
                        throw ApiException::validation([$f => ['Formato orario non valido.']]);
                    }
                    if ($value !== ($order->{$f} !== null ? (string) $order->{$f} : null)) {
                        $changes[$f] = ['before' => $order->{$f}, 'after' => $value];
                        $order->{$f} = $value;
                    }
                }
            }
            if ($order->pickup_time === null) {
                $order->pickup_time_end = null;
            }
            if ($order->return_time === null) {
                $order->return_time_end = null;
            }
            $this->assertTimeOverrides(
                Dates::datePart($order->pickup_date),
                Dates::datePart($order->return_date),
                [
                    'pickup_time' => $order->pickup_time,
                    'pickup_time_end' => $order->pickup_time_end,
                    'return_time' => $order->return_time,
                    'return_time_end' => $order->return_time_end,
                ],
                false,
                [
                    'pickup' => array_key_exists('pickup_time', $payload) || array_key_exists('pickup_time_end', $payload) || array_key_exists('pickup_date', $payload),
                    'return' => array_key_exists('return_time', $payload) || array_key_exists('return_time_end', $payload) || array_key_exists('return_date', $payload),
                ]
            );
            foreach (['subject', 'professor', 'staff_notes'] as $f) {
                if (array_key_exists($f, $payload)) {
                    $value = $payload[$f] !== null ? (string) $payload[$f] : null;
                    if ($value !== $order->{$f}) {
                        $changes[$f] = ['before' => $order->{$f}, 'after' => $value];
                        $order->{$f} = $value;
                    }
                }
            }

            if (isset($payload['items']) && is_array($payload['items'])) {
                $items = $this->normalizeItems($payload['items']);
                if ($items === []) {
                    throw ApiException::validation(['items' => ['La richiesta deve contenere almeno un articolo.']]);
                }
                OrderItem::where('order_id', $order->id)->delete();
                foreach ($items as $item) {
                    if ($item['product'] === null) {
                        throw ApiException::validation(['items' => ['Prodotto non trovato.']]);
                    }
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'notes' => $item['notes'],
                        'product_name_snapshot' => $item['product']->name,
                        'product_brand_snapshot' => $item['product']->brand,
                    ]);
                }
                $changes['items'] = ['after' => array_map(static fn ($i) => [
                    'product_id' => $i['product_id'],
                    'quantity' => $i['quantity'],
                ], $items)];
            }

            $this->assertOrderAvailability($order);

            // Re-evaluate limits.
            $items = $this->cartItemsNormalized($order);
            $owner = User::find($order->user_id);
            $availabilityByProduct = $this->availability->availableForRange(
                array_map(static fn ($i) => $i['product_id'], $items),
                (string) Dates::datePart($order->pickup_date),
                (string) Dates::datePart($order->return_date),
                (int) $order->id
            );
            $violations = $this->limits->evaluate(
                $owner,
                $items,
                Dates::datePart($order->pickup_date),
                $order->pickup_time,
                Dates::datePart($order->return_date),
                $order->return_time,
                (int) $order->id,
                $availabilityByProduct
            );
            $order->exceeds_limits = LimitsEvaluator::hasSoft($violations);
            $order->limit_violations = json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $order->save();
            $this->recountItems($order);
            $this->writeEvent($order, (string) $order->status, (string) $order->status, 'note', $actor, null, ['changes' => $changes]);
            return $order->refresh();
        });
    }

    /**
     * Admin-only full edit (`orders.edit_full`): any submitted order, past,
     * present or future — dates, slots, items, subject, motivation, professor,
     * notes. Status is NOT touched here: corrections go through the state
     * machine endpoints (reopen & co.).
     *
     * Availability is always re-checked on the new configuration excluding the
     * order itself; a shortfall raises 422 `insufficient_availability` unless
     * `force: true` is passed (explicit physical-reality override). A forced
     * overbooking save is flagged in the returned tuple, in the order event
     * meta and in the audit log.
     *
     * @param array<string,mixed> $payload
     * @param bool $datesOnly restrict to pickup/return date+time (change_dates)
     * @return array{0:Order, 1:?array} [order, overbooked products or null]
     */
    public function editOrderFull(Order $order, User $actor, array $payload, bool $datesOnly = false): array
    {
        if ($actor->role !== 'admin') {
            throw ApiException::forbidden('La modifica completa dei prestiti richiede il permesso orders.edit_full.');
        }
        if ($order->status === 'draft') {
            throw new ApiException(409, 'invalid_transition', 'Una bozza si modifica dal carrello, non da qui.', [
                'current_status' => 'draft',
                'action' => $datesOnly ? 'change_dates' : 'edit',
                'allowed_actions' => $this->machine->allowedActions($order, $actor),
            ]);
        }
        $force = (bool) ($payload['force'] ?? false);

        return Capsule::connection()->transaction(function () use ($order, $actor, $payload, $datesOnly, $force) {
            $this->lockForAvailability();
            $changes = [];

            foreach (['pickup_date', 'return_date'] as $f) {
                if (array_key_exists($f, $payload) && $payload[$f] !== null) {
                    if (!Dates::isValidDate((string) $payload[$f])) {
                        throw ApiException::validation([$f => ['Formato data non valido (YYYY-MM-DD).']]);
                    }
                    if ((string) $payload[$f] !== (string) Dates::datePart($order->{$f})) {
                        $changes[$f] = ['before' => Dates::datePart($order->{$f}), 'after' => (string) $payload[$f]];
                        $order->{$f} = (string) $payload[$f];
                    }
                }
            }
            if (Dates::datePart($order->return_date) < Dates::datePart($order->pickup_date)) {
                throw ApiException::validation(['return_date' => ['La data di riconsegna deve essere successiva o uguale al ritiro.']]);
            }
            // Time-window model (SPEC v1.4 §5.3): explicit null CLEARS an
            // override (back to the lab's weekday window); a time sets a
            // precise override; time + *_time_end sets a custom range.
            foreach (['pickup_time', 'pickup_time_end', 'return_time', 'return_time_end'] as $f) {
                if (array_key_exists($f, $payload)) {
                    $value = $payload[$f] !== null && $payload[$f] !== '' ? (string) $payload[$f] : null;
                    if ($value !== null && !Dates::isValidTime($value)) {
                        throw ApiException::validation([$f => ['Formato orario non valido (HH:MM).']]);
                    }
                    if ($value !== ($order->{$f} !== null ? (string) $order->{$f} : null)) {
                        $changes[$f] = ['before' => $order->{$f}, 'after' => $value];
                        $order->{$f} = $value;
                    }
                }
            }
            // Clearing a start drops its end too — a dangling end is meaningless.
            if ($order->pickup_time === null && $order->pickup_time_end !== null) {
                $changes['pickup_time_end'] = ['before' => $order->pickup_time_end, 'after' => null];
                $order->pickup_time_end = null;
            }
            if ($order->return_time === null && $order->return_time_end !== null) {
                $changes['return_time_end'] = ['before' => $order->return_time_end, 'after' => null];
                $order->return_time_end = null;
            }
            $this->assertTimeOverrides(
                Dates::datePart($order->pickup_date),
                Dates::datePart($order->return_date),
                [
                    'pickup_time' => $order->pickup_time,
                    'pickup_time_end' => $order->pickup_time_end,
                    'return_time' => $order->return_time,
                    'return_time_end' => $order->return_time_end,
                ],
                $force,
                [
                    'pickup' => array_key_exists('pickup_time', $payload) || array_key_exists('pickup_time_end', $payload) || array_key_exists('pickup_date', $payload),
                    'return' => array_key_exists('return_time', $payload) || array_key_exists('return_time_end', $payload) || array_key_exists('return_date', $payload),
                ]
            );

            if (!$datesOnly) {
                foreach (['subject', 'professor', 'motivation', 'notes', 'staff_notes'] as $f) {
                    if (array_key_exists($f, $payload)) {
                        $value = $payload[$f] !== null ? (string) $payload[$f] : null;
                        if ($value !== $order->{$f}) {
                            $changes[$f] = ['before' => $order->{$f}, 'after' => $value];
                            $order->{$f} = $value;
                        }
                    }
                }

                if (isset($payload['items']) && is_array($payload['items'])) {
                    $items = $this->normalizeItems($payload['items']);
                    if ($items === [] || count($items) > 50) {
                        throw ApiException::validation(['items' => ['Specificare da 1 a 50 articoli.']]);
                    }
                    foreach ($items as $item) {
                        if ($item['product'] === null) {
                            throw ApiException::validation(['items' => ['Prodotto non trovato.']]);
                        }
                    }
                    $existing = OrderItem::where('order_id', $order->id)->orderBy('id')->get();
                    $before = $existing->map(static fn ($i) => [
                        'product_id' => (int) $i->product_id,
                        'quantity' => (int) $i->quantity,
                    ])->values()->all();
                    $byProduct = $existing->keyBy('product_id');
                    $keptProductIds = [];
                    foreach ($items as $item) {
                        $keptProductIds[] = $item['product_id'];
                        $row = $byProduct->get($item['product_id']);
                        if ($row !== null) {
                            // Keep the row (unit assignment history hangs off it).
                            $row->quantity = $item['quantity'];
                            if ($item['notes'] !== null) {
                                $row->notes = mb_substr($item['notes'], 0, 255);
                            }
                            $row->save();
                        } else {
                            OrderItem::create([
                                'order_id' => $order->id,
                                'product_id' => $item['product_id'],
                                'quantity' => $item['quantity'],
                                'notes' => $item['notes'] !== null ? mb_substr($item['notes'], 0, 255) : null,
                                'product_name_snapshot' => $item['product']->name,
                                'product_brand_snapshot' => $item['product']->brand,
                            ]);
                        }
                    }
                    foreach ($existing as $row) {
                        if (!in_array((int) $row->product_id, $keptProductIds, true)) {
                            OrderItemUnit::where('order_item_id', $row->id)->delete();
                            $row->delete();
                        }
                    }
                    $after = array_map(static fn ($i) => [
                        'product_id' => $i['product_id'],
                        'quantity' => $i['quantity'],
                    ], $items);
                    if ($before !== $after) {
                        $changes['items'] = ['before' => $before, 'after' => $after];
                    }
                }
            }

            // ---- availability on the new configuration, excluding this order.
            $overbook = null;
            $short = $this->orderAvailabilityShortfall($order);
            if ($short !== []) {
                if (!$force) {
                    throw new ApiException(422, 'insufficient_availability', 'La disponibilità non è sufficiente per alcuni prodotti nel periodo selezionato.', [
                        'products' => $short,
                    ]);
                }
                $overbook = $short;
            }

            // ---- limits re-evaluation: recorded on the order, never blocking
            // an admin correction (checkout-style flags stay truthful).
            $items = $this->cartItemsNormalized($order);
            $owner = User::find($order->user_id);
            $availabilityByProduct = $this->availability->availableForRange(
                array_map(static fn ($i) => $i['product_id'], $items),
                (string) Dates::datePart($order->pickup_date),
                (string) Dates::datePart($order->return_date),
                (int) $order->id
            );
            $violations = $owner !== null ? $this->limits->evaluate(
                $owner,
                $items,
                Dates::datePart($order->pickup_date),
                $order->pickup_time,
                Dates::datePart($order->return_date),
                $order->return_time,
                (int) $order->id,
                $availabilityByProduct
            ) : [];
            $order->exceeds_limits = LimitsEvaluator::hasSoft($violations);
            $order->limit_violations = json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $order->save();
            $this->recountItems($order);

            if ($changes !== []) {
                $meta = ['changes' => $changes, 'edit_full' => true];
                if ($overbook !== null) {
                    $meta['forced'] = true;
                    $meta['overbooked_products'] = $overbook;
                }
                $this->writeEvent($order, (string) $order->status, (string) $order->status, 'edit', $actor, $payload['comment'] ?? null, $meta);
                $auditChanges = [
                    'before' => array_map(static fn ($c) => $c['before'] ?? null, $changes),
                    'after' => array_map(static fn ($c) => $c['after'] ?? null, $changes),
                ];
                if ($overbook !== null) {
                    $auditChanges['forced_overbook'] = $overbook;
                }
                AuditLogger::log($actor, 'order.edit_full', 'Order', (string) $order->id, $auditChanges);
            }
            return [$order->refresh(), $overbook];
        });
    }

    public function addNotes(Order $order, User $actor, ?string $staffNotes, ?string $comment): Order
    {
        if ($staffNotes !== null) {
            $order->staff_notes = $staffNotes;
            $order->save();
        }
        if ($comment !== null && $comment !== '') {
            $this->writeEvent($order, (string) $order->status, (string) $order->status, 'note', $actor, $comment, null);
        }
        return $order->refresh();
    }

    /**
     * Error prevention on explicit time overrides (SPEC v1.4 §5.3): an end
     * needs a start, a range must be ordered, and the override must fall
     * within the day's opening hours (`hours.weekly`). `force: true` (admin)
     * downgrades the opening-hours bound from block to "the lab knows better".
     *
     * A leg whose day is closed in `hours.weekly` is NOT time-bounded here:
     * the date-level warnings (`date_not_bookable`, never blocking staff)
     * already cover it, and refusing the time while accepting the date would
     * be incoherent.
     *
     * @param array{pickup_time:?string,pickup_time_end:?string,return_time:?string,return_time_end:?string} $times
     * @param array{pickup:bool,return:bool}|null $legsToCheck legs actually
     *        touched by this request; untouched legs (e.g. items-only edits on
     *        an order with pre-existing odd times) are never re-validated.
     */
    private function assertTimeOverrides(
        ?string $pickupDate,
        ?string $returnDate,
        array $times,
        bool $force,
        ?array $legsToCheck = null
    ): void {
        $errors = [];
        $legs = [
            ['pickup', $times['pickup_time'], $times['pickup_time_end'], $pickupDate],
            ['return', $times['return_time'], $times['return_time_end'], $returnDate],
        ];
        foreach ($legs as [$kind, $time, $end, $date]) {
            if ($legsToCheck !== null && !($legsToCheck[$kind] ?? true)) {
                continue;
            }
            $field = $kind . '_time';
            if ($end !== null && $time === null) {
                $errors[$field . '_end'] = ['Indica anche l\'orario di inizio della fascia.'];
                continue;
            }
            if ($time === null) {
                continue;
            }
            if ($end !== null && $end <= $time) {
                $errors[$field . '_end'] = ['La fine della fascia deve essere successiva all\'inizio.'];
            }
            if (!$force && $date !== null && Dates::isValidDate($date)) {
                $opening = $this->calendar->openingFor($date);
                $latest = $end ?? $time;
                if ($opening !== null && ($time < $opening['open'] || $latest > $opening['close'])) {
                    $errors[$field] = ["L'orario deve rientrare nell'apertura del giorno ({$opening['open']}–{$opening['close']})."];
                }
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
    }

    /** 409 insufficient_availability if this order's own demand no longer fits. */
    private function assertOrderAvailability(Order $order): void
    {
        $short = $this->orderAvailabilityShortfall($order);
        if ($short !== []) {
            throw new ApiException(409, 'insufficient_availability', 'La disponibilità non è più sufficiente per alcuni prodotti.', [
                'products' => $short,
            ]);
        }
    }

    /**
     * Products of the order whose demand exceeds availability over the order's
     * own range, excluding the order itself.
     *
     * @return array<int,array{product_id:int, name:?string, requested:int, available:int}>
     */
    private function orderAvailabilityShortfall(Order $order): array
    {
        $pickupDate = Dates::datePart($order->pickup_date);
        $returnDate = Dates::datePart($order->return_date);
        if ($pickupDate === null || $returnDate === null) {
            return [];
        }
        $items = OrderItem::where('order_id', $order->id)->get();
        $productIds = $items->pluck('product_id')->map(static fn ($v) => (int) $v)->all();
        if ($productIds === []) {
            return [];
        }
        $availability = $this->availability->availableForRange($productIds, $pickupDate, $returnDate, (int) $order->id);
        $short = [];
        foreach ($items as $item) {
            $info = $availability[(int) $item->product_id] ?? ['available' => 0];
            if ((int) $item->quantity > $info['available']) {
                $short[] = [
                    'product_id' => (int) $item->product_id,
                    'name' => $item->product_name_snapshot ?? $item->product?->name,
                    'requested' => (int) $item->quantity,
                    'available' => (int) $info['available'],
                ];
            }
        }
        return $short;
    }

    // ---------------------------------------------------------- overdue sweep

    /** @param iterable<Order> $orders */
    public function refreshOverdue(iterable $orders): void
    {
        $tz = new DateTimeZone($this->calendar->timezone());
        $now = Dates::nowUtc();
        $overdueGrace = (int) ($this->settings->get('booking.overdue_grace_hours', 0) ?? 0);
        $noShowGrace = (int) ($this->settings->get('booking.no_show_grace_hours', 48) ?? 48);
        foreach ($orders as $order) {
            if ($order->status === 'picked_up' && $order->return_date !== null) {
                $deadline = (new DateTimeImmutable(Dates::datePart($order->return_date) . ' 23:59:59', $tz))
                    ->modify("+{$overdueGrace} hours");
                if ($now > $deadline) {
                    $order->status = 'overdue';
                    $order->save();
                    $this->writeSystemEvent($order, 'picked_up', 'overdue', 'mark_overdue');
                }
            } elseif ($order->status === 'approved' && $order->pickup_date !== null) {
                $deadline = (new DateTimeImmutable(Dates::datePart($order->pickup_date) . ' 23:59:59', $tz))
                    ->modify("+{$noShowGrace} hours");
                if ($now > $deadline) {
                    $order->status = 'no_show';
                    $order->save();
                    $this->writeSystemEvent($order, 'approved', 'no_show', 'mark_no_show');
                }
            }
        }
    }

    public function refreshOverdueAll(): int
    {
        $orders = Order::whereIn('status', ['picked_up', 'approved'])->get();
        $before = $orders->pluck('status')->all();
        $this->refreshOverdue($orders);
        $changed = 0;
        foreach ($orders as $i => $order) {
            if ($order->status !== $before[$i]) {
                $changed++;
            }
        }
        return $changed;
    }

    // ---------------------------------------------------------------- events

    public function writeEvent(Order $order, ?string $from, string $to, string $action, User $actor, ?string $comment, ?array $meta): OrderEvent
    {
        return OrderEvent::create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_type' => 'user',
            'actor_role' => $actor->role,
            'comment' => $comment !== null && $comment !== '' ? $comment : null,
            'meta' => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    private function writeSystemEvent(Order $order, string $from, string $to, string $action): void
    {
        OrderEvent::create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'action' => $action,
            'actor_id' => null,
            'actor_type' => 'system',
            'actor_role' => null,
        ]);
    }
}
