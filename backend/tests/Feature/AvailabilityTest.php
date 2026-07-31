<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class AvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
    }

    public function testAvailabilityProductsFiltersByMinQuantity(): void
    {
        $catalogCategory = $this->seedCategory();
        $rich = $this->seedProduct(['category_id' => $catalogCategory->id], 5);
        $poor = $this->seedProduct(['category_id' => $catalogCategory->id], 1);
        // Consume the poor product's only unit.
        $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-07',
            'return_date' => '2026-09-09',
            'items' => [['product_id' => $poor->id, 'quantity' => 1]],
        ]);
        [$status, $payload] = $this->json('GET', '/api/v1/availability/products?start_date=2026-09-07&end_date=2026-09-08');
        $this->assertSame(200, $status);
        $ids = array_map(static fn ($p) => $p['id'], $payload['data']);
        $this->assertContains($rich->id, $ids);
        $this->assertNotContains($poor->id, $ids);
        $this->assertSame(['start_date' => '2026-09-07', 'end_date' => '2026-09-08', 'days' => 2], $payload['range']);
        $this->assertTrue($payload['range_validity']['pickup_date_valid']);

        // include_unavailable=true returns it with available_quantity 0.
        [, $payload] = $this->json('GET', '/api/v1/availability/products?start_date=2026-09-07&end_date=2026-09-08&include_unavailable=true');
        $found = null;
        foreach ($payload['data'] as $p) {
            if ($p['id'] === $poor->id) {
                $found = $p;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame(0, $found['available_quantity']);
        $this->assertSame(1, $found['capacity']);
        $this->assertSame('2026-09-07', $found['bottleneck_date']);
    }

    public function testAvailabilityDatesDenseDaysAndWindows(): void
    {
        $product = $this->seedProduct([], 2);
        [$status, $payload] = $this->json('POST', '/api/v1/availability/dates', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'from' => '2026-09-07',
            'to' => '2026-09-13',
            'duration_days' => 3,
        ]);
        $this->assertSame(200, $status);
        // Dense: one entry per calendar day, inclusive.
        $this->assertCount(7, $payload['days']);
        $this->assertSame('2026-09-07', $payload['days'][0]['date']);
        $this->assertSame('2026-09-13', $payload['days'][6]['date']);
        $this->assertSame(3, $payload['duration_days']);
        foreach ($payload['windows'] as $window) {
            $this->assertSame(3, $window['days']);
            // Window pickup day must be a bookable pickup; Fri 11 Sep -> Sun 13 Sep is
            // omitted because the return day is closed.
            $this->assertNotSame('2026-09-11', $window['pickup_date']);
            $this->assertNotSame('2026-09-12', $window['pickup_date']);
            $this->assertNotSame('2026-09-13', $window['pickup_date']);
        }
        // Mon..Wed is the first feasible 3-day window.
        $this->assertSame('2026-09-07', $payload['first_available_window']['pickup_date']);
        $this->assertSame('2026-09-09', $payload['first_available_window']['return_date']);
    }

    public function testAvailabilityDatesBlockingProducts(): void
    {
        $free = $this->seedProduct([], 3);
        $busy = $this->seedProduct([], 1);
        $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-08',
            'return_date' => '2026-09-09',
            'items' => [['product_id' => $busy->id, 'quantity' => 1]],
        ]);
        [, $payload] = $this->json('POST', '/api/v1/availability/dates', [
            'items' => [
                ['product_id' => $free->id, 'quantity' => 1],
                ['product_id' => $busy->id, 'quantity' => 1],
            ],
            'from' => '2026-09-07',
            'to' => '2026-09-10',
            'duration_days' => 2,
        ]);
        $byPickup = [];
        foreach ($payload['windows'] as $w) {
            $byPickup[$w['pickup_date']] = $w;
        }
        // Mon 7 -> Tue 8 window includes the busy day.
        $this->assertFalse($byPickup['2026-09-07']['all_available']);
        $this->assertSame([$busy->id], $byPickup['2026-09-07']['blocking_product_ids']);
        // Wed 9 -> Thu 10 overlaps the 9th, still blocked.
        $this->assertFalse($byPickup['2026-09-09']['all_available']);
        // Thu 10 -> Fri 11 is free.
        $this->assertTrue($byPickup['2026-09-10']['all_available']);
        $this->assertSame([], $byPickup['2026-09-10']['blocking_product_ids']);
    }

    public function testAvailabilityCheckPayloadShape(): void
    {
        $this->actingAs('student');
        $product = $this->seedProduct([], 4);
        [$status, $payload] = $this->json('POST', '/api/v1/availability/check', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'pickup_date' => '2026-09-07',
            'pickup_time' => '09:30',
            'return_date' => '2026-09-16',
            'return_time' => '14:00',
        ]);
        $this->assertSame(200, $status);
        $this->assertFalse($payload['ok']);
        $this->assertTrue($payload['can_submit']); // only soft violations
        $this->assertTrue($payload['exceeds_limits']);
        $this->assertSame('max_loan_days_exceeded', $payload['violations'][0]['code']);
        $this->assertSame(10, $payload['duration_days']);
        $this->assertSame(4, $payload['availability'][0]['available']);
        $this->assertTrue($payload['availability'][0]['sufficient']);
        $this->assertNotEmpty($payload['pickup_slots']);
        $this->assertNotEmpty($payload['return_slots']);
        $this->assertArrayHasKey('orders_this_month', $payload['quota']);
        $this->assertSame(4, $payload['quota']['max_orders_per_month']);
        $this->assertNull($payload['quota']['max_orders_per_year']);
    }

    public function testAvailabilityCheckRequiresAuth(): void
    {
        [$status, $payload] = $this->json('POST', '/api/v1/availability/check', ['items' => []]);
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($payload, 'unauthenticated');
    }

    public function testProductAvailabilityEndpointDays(): void
    {
        $product = $this->seedProduct([], 3);
        $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-08',
            'return_date' => '2026-09-09',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        [$status, $payload] = $this->json('GET', "/api/v1/products/{$product->id}/availability?from=2026-09-07&to=2026-09-10");
        $this->assertSame(200, $status);
        $this->assertSame($product->id, $payload['product_id']);
        $this->assertSame(3, $payload['capacity']);
        $this->assertCount(4, $payload['days']);
        $this->assertSame(3, $payload['days'][0]['available']);
        $this->assertSame(1, $payload['days'][1]['available']);
        $this->assertSame(2, $payload['days'][1]['reserved']);
        $this->assertTrue($payload['days'][1]['is_open']);
    }

    public function testCalendarOpeningShape(): void
    {
        [$status, $payload] = $this->json('GET', '/api/v1/calendar/opening?from=2026-09-07&to=2026-09-13');
        $this->assertSame(200, $status);
        $this->assertSame('Europe/Rome', $payload['timezone']);
        $this->assertCount(7, $payload['weekly']);
        $this->assertSame(0, $payload['weekly'][0]['weekday']);
        $this->assertSame('Domenica', $payload['weekly'][0]['label']);
        $this->assertTrue($payload['weekly'][0]['closed']);
        $this->assertCount(7, $payload['days']);
        $monday = $payload['days'][0];
        $this->assertSame(1, $monday['weekday']);
        $this->assertTrue($monday['can_pickup']);
        $this->assertNotEmpty($monday['pickup_slots']);
        $this->assertSame(['start' => '09:00', 'end' => '09:30'], $monday['pickup_slots'][0]);
        $sunday = $payload['days'][6];
        $this->assertFalse($sunday['is_open']);
        $this->assertSame([], $sunday['pickup_slots']);
        $this->assertArrayHasKey('booking_window', $payload);
    }
}
