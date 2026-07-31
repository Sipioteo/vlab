<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Orders\OrderService;
use App\Http\Resources\ProductResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\ApiException;
use App\Support\Dates;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CartController extends Controller
{
    public function __construct(
        private OrderService $orders,
        private AvailabilityService $availability,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $cart = $this->orders->cart($user);
        return $this->json($response, $this->cartPayload($user, $cart));
    }

    public function addItem(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $productId = (int) ($body['product_id'] ?? 0);
        $quantity = max(1, (int) ($body['quantity'] ?? 1));
        if ($productId <= 0) {
            throw ApiException::validation(['product_id' => ['Il campo product_id è obbligatorio.']]);
        }
        $cart = $this->orders->addCartItem($user, $productId, $quantity, isset($body['notes']) ? (string) $body['notes'] : null);
        return $this->json($response, $this->cartPayload($user, $cart));
    }

    public function patchItem(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $quantity = null;
        if (array_key_exists('quantity', $body) && $body['quantity'] !== null) {
            $quantity = (int) $body['quantity'];
            if ($quantity < 0) {
                throw ApiException::validation(['quantity' => ['La quantità non può essere negativa.']]);
            }
        }
        $cart = $this->orders->updateCartItem(
            $user,
            (int) $args['itemId'],
            $quantity,
            isset($body['notes']) && $body['notes'] !== null ? (string) $body['notes'] : null,
            array_key_exists('notes', $body)
        );
        return $this->json($response, $this->cartPayload($user, $cart));
    }

    /** POST /cart/items/{itemId}/swap — replace an item with a substitute. */
    public function swapItem(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $productId = (int) ($body['product_id'] ?? 0);
        if ($productId <= 0) {
            throw ApiException::validation(['product_id' => ['Il campo product_id è obbligatorio.']]);
        }
        $cart = $this->orders->swapCartItem($user, (int) $args['itemId'], $productId);
        return $this->json($response, $this->cartPayload($user, $cart));
    }

    public function deleteItem(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $cart = $this->orders->deleteCartItem($user, (int) $args['itemId']);
        return $this->json($response, $this->cartPayload($user, $cart));
    }

    public function putDates(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $cart = $this->orders->setCartDates($user, $this->body($request));
        return $this->json($response, $this->cartPayload($user, $cart));
    }

    public function clear(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $cart = $this->orders->clearCart($user);
        return $this->json($response, $this->cartPayload($user, $cart));
    }

    /** Full cart object (SPEC §7.9 #34). @return array<string,mixed> */
    private function cartPayload(User $user, Order $cart): array
    {
        $items = OrderItem::where('order_id', $cart->id)->orderBy('id')->get();
        $pickupDate = Dates::datePart($cart->pickup_date);
        $returnDate = Dates::datePart($cart->return_date);
        $hasRange = $pickupDate !== null && $returnDate !== null && $returnDate >= $pickupDate;

        $productIds = $items->pluck('product_id')->map(static fn ($v) => (int) $v)->all();
        $maps = ProductResource::maps($productIds, []);
        $availability = $hasRange && $productIds !== []
            ? $this->availability->availableForRange($productIds, $pickupDate, $returnDate)
            : [];

        $itemsOut = [];
        foreach ($items as $item) {
            $product = $item->product;
            $pid = (int) $item->product_id;
            $available = $hasRange ? ($availability[$pid]['available'] ?? 0) : null;
            $itemsOut[] = [
                'id' => (int) $item->id,
                'product_id' => $pid,
                'quantity' => (int) $item->quantity,
                'notes' => $item->notes,
                'product' => $product !== null ? ProductResource::summary($product, $maps) : null,
                'available_quantity' => $available,
                'sufficient' => $hasRange ? ($available >= (int) $item->quantity) : null,
            ];
        }

        $check = null;
        if ($hasRange) {
            $normalized = $this->orders->cartItemsNormalized($cart);
            // Times are ignored on the student path (SPEC v1.4 §5.3): the
            // order will use the lab's weekday window, so stale cart times
            // must not pollute the pre-flight check.
            $check = $this->orders->availabilityCheck(
                $user,
                $normalized,
                $pickupDate,
                null,
                $returnDate,
                null
            );
        }

        return [
            'id' => (int) $cart->id,
            'status' => 'draft',
            'pickup_date' => $pickupDate,
            'pickup_time' => $cart->pickup_time,
            'return_date' => $returnDate,
            'return_time' => $cart->return_time,
            'items' => $itemsOut,
            'items_count' => (int) $items->sum('quantity'),
            'distinct_products' => count($itemsOut),
            'check' => $check,
            'updated_at' => Dates::iso($cart->updated_at),
        ];
    }
}
