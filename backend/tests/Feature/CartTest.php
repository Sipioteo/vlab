<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Dates;
use Tests\TestCase;

final class CartTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
        $this->setSetting('regulations.enforce_global_acceptance', false);
    }

    public function testCartLazilyCreatedExactlyOnce(): void
    {
        $user = $this->actingAs('student');
        [$status, $cart1] = $this->json('GET', '/api/v1/cart');
        $this->assertSame(200, $status);
        $this->assertSame('draft', $cart1['status']);
        [, $cart2] = $this->json('GET', '/api/v1/cart');
        $this->assertSame($cart1['id'], $cart2['id']);
        $this->assertSame(1, Order::where('user_id', $user->id)->where('status', 'draft')->count());
    }

    public function testAddingSameProductTwiceIncrementsQuantity(): void
    {
        $this->actingAs('student');
        $product = $this->seedProduct([], 5);
        $this->json('POST', '/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);
        [$status, $cart] = $this->json('POST', '/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);
        $this->assertSame(200, $status);
        $this->assertCount(1, $cart['items']);
        $this->assertSame(2, $cart['items'][0]['quantity']);
        $this->assertSame(2, $cart['items_count']);
        $this->assertSame(1, $cart['distinct_products']);
    }

    public function testQuantityBeyondPerProductLimitRejected(): void
    {
        $this->actingAs('student');
        $product = $this->seedProduct([], 5);
        [$status, $payload] = $this->json('POST', '/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3]);
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'limit_violation');
    }

    public function testCartItemPatchAndDeleteAndClear(): void
    {
        $this->actingAs('student');
        $product = $this->seedProduct([], 5);
        [, $cart] = $this->json('POST', '/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);
        $itemId = $cart['items'][0]['id'];
        [, $cart] = $this->json('PATCH', "/api/v1/cart/items/{$itemId}", ['quantity' => 2, 'notes' => 'Con custodia.']);
        $this->assertSame(2, $cart['items'][0]['quantity']);
        $this->assertSame('Con custodia.', $cart['items'][0]['notes']);
        // quantity 0 deletes the row.
        [, $cart] = $this->json('PATCH', "/api/v1/cart/items/{$itemId}", ['quantity' => 0]);
        $this->assertSame([], $cart['items']);
        // clear.
        $this->json('POST', '/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);
        $this->json('PUT', '/api/v1/cart/dates', ['pickup_date' => '2026-09-07', 'pickup_time' => '09:30', 'return_date' => '2026-09-08', 'return_time' => '14:00']);
        [, $cart] = $this->json('DELETE', '/api/v1/cart');
        $this->assertSame([], $cart['items']);
        $this->assertNull($cart['pickup_date']);
        $this->assertNull($cart['check']);
    }

    public function testCartCheckPresentOnlyWithBothDates(): void
    {
        $this->actingAs('student');
        $product = $this->seedProduct([], 5);
        [, $cart] = $this->json('POST', '/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);
        $this->assertNull($cart['check']);
        $this->assertNull($cart['items'][0]['available_quantity']);
        [, $cart] = $this->json('PUT', '/api/v1/cart/dates', [
            'pickup_date' => '2026-09-07', 'pickup_time' => '09:30',
            'return_date' => '2026-09-08', 'return_time' => '14:00',
        ]);
        $this->assertIsArray($cart['check']);
        $this->assertTrue($cart['check']['can_submit']);
        $this->assertSame(2, $cart['check']['duration_days']);
        $this->assertSame(5, $cart['items'][0]['available_quantity']);
        $this->assertTrue($cart['items'][0]['sufficient']);
    }

    public function testCheckoutEmptiesCartAndNewDraftIsCreated(): void
    {
        $user = $this->actingAs('student');
        $product = $this->seedProduct([], 5);
        $this->json('POST', '/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);
        [$status, $order] = $this->json('POST', '/api/v1/orders', [
            'from_cart' => true,
            'pickup_date' => '2026-09-07', 'pickup_time' => '09:30',
            'return_date' => '2026-09-08', 'return_time' => '14:00',
            'subject' => 'Materia', 'motivation' => 'Motivazione abbastanza lunga per superare il minimo.',
        ]);
        $this->assertSame(201, $status, json_encode($order));
        $this->assertSame('pending', $order['status']);
        [, $cart] = $this->json('GET', '/api/v1/cart');
        $this->assertNotSame($order['id'], $cart['id']);
        $this->assertSame([], $cart['items']);
    }

    public function testStaleDraftsArePruned(): void
    {
        $user = $this->actingAs('student');
        [, $cart] = $this->json('GET', '/api/v1/cart');
        $staleId = $cart['id'];
        Order::where('id', $staleId)->update(['updated_at' => '2026-08-01 00:00:00']);
        [, $fresh] = $this->json('GET', '/api/v1/cart');
        $this->assertNotSame($staleId, $fresh['id']);
        $this->assertNull(Order::find($staleId));
    }

    public function testStaffGetsRoleRequiredOnCartAndOrders(): void
    {
        foreach (['technician', 'assistant', 'admin'] as $role) {
            $this->actingAs($role);
            [$status, $payload] = $this->json('POST', '/api/v1/cart/items', ['product_id' => 1, 'quantity' => 1]);
            $this->assertSame(403, $status, $role);
            $this->assertErrorEnvelope($payload, 'role_required');
            [$status, $payload] = $this->json('POST', '/api/v1/orders', ['from_cart' => false, 'items' => []]);
            $this->assertSame(403, $status, $role);
            $this->assertErrorEnvelope($payload, 'role_required');
        }
    }

    public function testCheckoutSoftViolationAcknowledgeFlow(): void
    {
        $this->actingAs('student');
        $product = $this->seedProduct([], 5);
        $body = [
            'from_cart' => false,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            // 10 days -> soft max_loan_days_exceeded.
            'pickup_date' => '2026-09-07', 'pickup_time' => '09:30',
            'return_date' => '2026-09-16', 'return_time' => '14:00',
            'subject' => 'Materia', 'motivation' => 'Motivazione abbastanza lunga per superare il minimo.',
        ];
        [$status, $payload] = $this->json('POST', '/api/v1/orders', $body);
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'limit_violation');
        $this->assertNotEmpty($payload['error']['details']['violations']);

        [$status, $payload] = $this->json('POST', '/api/v1/orders', $body + ['acknowledge_exceeds_limits' => true]);
        $this->assertSame(201, $status, json_encode($payload));
        $this->assertTrue($payload['exceeds_limits']);
        $this->assertNotEmpty($payload['limit_violations']);
        $this->assertSame('max_loan_days_exceeded', $payload['limit_violations'][0]['code']);
    }

    public function testConcurrentCheckoutForLastUnit(): void
    {
        $product = $this->seedProduct([], 1);
        $body = [
            'from_cart' => false,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'pickup_date' => '2026-09-07', 'pickup_time' => '09:30',
            'return_date' => '2026-09-08', 'return_time' => '14:00',
            'subject' => 'Materia', 'motivation' => 'Motivazione abbastanza lunga per superare il minimo.',
        ];
        $this->actingAs('student');
        [$status] = $this->json('POST', '/api/v1/orders', $body);
        $this->assertSame(201, $status);
        $this->actingAs(\App\Models\User::where('ldap_uid', 'student2')->first());
        [$status, $payload] = $this->json('POST', '/api/v1/orders', $body);
        $this->assertSame(409, $status);
        $this->assertErrorEnvelope($payload, 'insufficient_availability');
        $this->assertSame($product->id, $payload['error']['details']['products'][0]['product_id']);
    }

    public function testCheckoutOnClosedDateReturnsSuggestions(): void
    {
        $this->actingAs('student');
        $product = $this->seedProduct([], 5);
        [$status, $payload] = $this->json('POST', '/api/v1/orders', [
            'from_cart' => false,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'pickup_date' => '2026-09-06', 'pickup_time' => '09:30', // Sunday
            'return_date' => '2026-09-08', 'return_time' => '14:00',
            'subject' => 'Materia', 'motivation' => 'Motivazione abbastanza lunga per superare il minimo.',
        ]);
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'date_not_bookable');
        $this->assertSame('pickup_date', $payload['error']['details']['field']);
        $this->assertCount(3, $payload['error']['details']['suggestions']);
        foreach ($payload['error']['details']['suggestions'] as $suggestion) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $suggestion);
        }
    }
}
