<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\OrderItemUnit;
use App\Models\ProductUnit;
use App\Models\User;
use Tests\TestCase;

final class OrderTransitionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
        // Keep transition tests focused: no soft-limit noise.
        $this->setSetting('booking.max_orders_per_month', null);
        $this->setSetting('booking.max_active_orders', null);
    }

    /**
     * SPEC test #53: every allowed staff/admin transition of §8.2 succeeds for
     * every permitted role (table-driven).
     */
    public function testAllowedTransitionsSucceedForEveryPermittedRole(): void
    {
        $table = [
            // [fromStatus, endpoint, body, expectedStatus, roles]
            ['pending', 'approve', ['comment' => 'ok'], 'approved', ['technician', 'assistant', 'admin']],
            ['pending', 'reject', ['reason' => 'Non disponibile.'], 'rejected', ['technician', 'assistant', 'admin']],
            ['pending', 'cancel', [], 'cancelled', ['technician', 'assistant', 'admin']],
            ['approved', 'cancel', [], 'cancelled', ['technician', 'assistant', 'admin']],
            ['approved', 'pickup', [], 'picked_up', ['technician', 'assistant', 'admin']],
            ['approved', 'no-show', ['comment' => 'assente'], 'no_show', ['technician', 'assistant', 'admin']],
            ['picked_up', 'return', [], 'returned', ['technician', 'assistant', 'admin']],
            ['overdue', 'return', [], 'returned_late', ['technician', 'assistant', 'admin']],
        ];
        foreach ($table as [$from, $endpoint, $body, $expected, $roles]) {
            foreach ($roles as $role) {
                $order = $this->seedOrder([
                    'status' => $from,
                    'pickup_date' => '2026-09-10',
                    'return_date' => '2026-09-12',
                ]);
                $this->actingAs($role);
                [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/{$endpoint}", $body);
                $this->assertSame(200, $status, "{$from} -{$endpoint}-> as {$role}: " . json_encode($payload));
                $this->assertSame($expected, $payload['status'], "{$from} -{$endpoint}-> as {$role}");
            }
        }
        // Student cancel of own pending order.
        $order = $this->seedOrder(['status' => 'pending']);
        $this->actingAs('student');
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/cancel", ['reason' => 'Non serve più.']);
        $this->assertSame(200, $status);
        $this->assertSame('cancelled', $payload['status']);
        // Admin reopen from every terminal status.
        foreach (['rejected', 'cancelled', 'no_show', 'returned', 'returned_late'] as $terminal) {
            $order = $this->seedOrder(['status' => $terminal, 'pickup_date' => '2026-09-15', 'return_date' => '2026-09-16']);
            $this->actingAs('admin');
            [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/reopen", ['to_status' => 'pending', 'reason' => 'Errore.']);
            $this->assertSame(200, $status, "reopen from {$terminal}: " . json_encode($payload));
            $this->assertSame('pending', $payload['status']);
        }
    }

    /**
     * SPEC test #54: table-driven complement — invalid (status, action) pairs
     * return 409 invalid_transition (as admin, so role is never the blocker).
     */
    public function testInvalidTransitionsReturn409(): void
    {
        $endpoints = [
            'approve' => ['pending'],
            'reject' => ['pending'],
            'cancel' => ['pending', 'approved'],
            'pickup' => ['approved'],
            'return' => ['picked_up', 'overdue'],
            'no-show' => ['approved'],
            'reopen' => ['rejected', 'cancelled', 'no_show', 'returned', 'returned_late'],
        ];
        $this->actingAs('admin');
        foreach (\App\Support\Enums::ORDER_STATUSES as $from) {
            foreach ($endpoints as $endpoint => $validFrom) {
                if (in_array($from, $validFrom, true)) {
                    continue;
                }
                $order = $this->seedOrder(['status' => $from, 'pickup_date' => '2026-09-15', 'return_date' => '2026-09-16']);
                $body = $endpoint === 'reject' ? ['reason' => 'x y z'] : ($endpoint === 'reopen' ? ['to_status' => 'pending', 'reason' => 'x'] : []);
                [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/{$endpoint}", $body);
                $this->assertSame(409, $status, "{$from} -> {$endpoint}");
                $this->assertErrorEnvelope($payload, 'invalid_transition');
                $this->assertSame($from, $payload['error']['details']['current_status']);
            }
        }
    }

    public function testStudentForbiddenFromStaffTransitions(): void
    {
        $this->actingAs('student');
        $map = [
            'approve' => 'pending', 'reject' => 'pending', 'pickup' => 'approved',
            'return' => 'picked_up', 'no-show' => 'approved', 'reopen' => 'returned',
        ];
        foreach ($map as $endpoint => $from) {
            $order = $this->seedOrder(['status' => $from]);
            $body = $endpoint === 'reject' ? ['reason' => 'abc'] : ($endpoint === 'reopen' ? ['to_status' => 'pending', 'reason' => 'x'] : []);
            [$status] = $this->json('POST', "/api/v1/orders/{$order->id}/{$endpoint}", $body);
            $this->assertSame(403, $status, $endpoint);
        }
    }

    public function testAssistantCanOperateButNotReopen(): void
    {
        $this->actingAs('assistant');
        $order = $this->seedOrder(['status' => 'pending']);
        [$status] = $this->json('POST', "/api/v1/orders/{$order->id}/approve", []);
        $this->assertSame(200, $status);
        $terminal = $this->seedOrder(['status' => 'returned']);
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$terminal->id}/reopen", ['to_status' => 'pending', 'reason' => 'x']);
        $this->assertSame(403, $status);
        $this->assertErrorEnvelope($payload);
    }

    public function testTechnicianCannotReopenAdminCan(): void
    {
        $terminal = $this->seedOrder(['status' => 'cancelled', 'pickup_date' => '2026-09-20', 'return_date' => '2026-09-21']);
        $this->actingAs('technician');
        [$status] = $this->json('POST', "/api/v1/orders/{$terminal->id}/reopen", ['to_status' => 'approved', 'reason' => 'x']);
        $this->assertSame(403, $status);
        $this->actingAs('admin');
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$terminal->id}/reopen", ['to_status' => 'approved', 'reason' => 'Errore di registrazione.']);
        $this->assertSame(200, $status);
        $this->assertSame('approved', $payload['status']);
    }

    public function testStudentCannotCancelSomeoneElsesOrder(): void
    {
        $owner = User::where('ldap_uid', 'student1')->first();
        $order = $this->seedOrder(['status' => 'pending', 'user_id' => $owner->id]);
        $this->actingAs(User::where('ldap_uid', 'student2')->first());
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/cancel", []);
        $this->assertSame(403, $status);
        $this->assertErrorEnvelope($payload);
    }

    public function testStudentCancellationDeadline(): void
    {
        $this->actingAs('student');
        // Inside deadline (pickup tomorrow 09:30, deadline 24h).
        $order = $this->seedOrder(['status' => 'approved', 'pickup_date' => '2026-09-02', 'pickup_time' => '09:30', 'return_date' => '2026-09-03']);
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/cancel", []);
        $this->assertSame(409, $status);
        $this->assertErrorEnvelope($payload, 'invalid_transition');
        // Outside deadline.
        $order2 = $this->seedOrder(['status' => 'approved', 'pickup_date' => '2026-09-20', 'pickup_time' => '09:30', 'return_date' => '2026-09-21']);
        [$status] = $this->json('POST', "/api/v1/orders/{$order2->id}/cancel", []);
        $this->assertSame(200, $status);
    }

    public function testReturnOnTimeVsLate(): void
    {
        $this->actingAs('technician');
        $order = $this->seedOrder(['status' => 'picked_up', 'pickup_date' => '2026-08-28', 'return_date' => '2026-09-02']);
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/return", []);
        $this->assertSame(200, $status);
        $this->assertSame('returned', $payload['status']);
        $this->assertNull($payload['late_days']);

        $order2 = $this->seedOrder(['status' => 'picked_up', 'pickup_date' => '2026-08-20', 'return_date' => '2026-08-25']);
        // Prevent the lazy overdue sweep from firing first.
        $this->setSetting('booking.overdue_grace_hours', 24 * 30);
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order2->id}/return", []);
        $this->assertSame(200, $status);
        $this->assertSame('returned_late', $payload['status']);
        $this->assertSame(7, $payload['late_days']);
    }

    public function testReturnFromOverdueYieldsReturnedLate(): void
    {
        $this->actingAs('assistant');
        $order = $this->seedOrder(['status' => 'overdue', 'pickup_date' => '2026-08-20', 'return_date' => '2026-08-28']);
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/return", []);
        $this->assertSame(200, $status);
        $this->assertSame('returned_late', $payload['status']);
        $this->assertSame(4, $payload['late_days']);
    }

    public function testRefreshOverdueMovesPastDuePickedUpOrders(): void
    {
        $order = $this->seedOrder(['status' => 'picked_up', 'pickup_date' => '2026-08-20', 'return_date' => '2026-08-25']);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('GET', "/api/v1/orders/{$order->id}");
        $this->assertSame(200, $status);
        $this->assertSame('overdue', $payload['status']);
        $event = OrderEvent::where('order_id', $order->id)->where('action', 'mark_overdue')->first();
        $this->assertNotNull($event);
        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->actor_id);
    }

    public function testNoShowGraceMovesUncollectedApprovedOrders(): void
    {
        // Approved, pickup 5 days ago, grace 48h -> no_show.
        $order = $this->seedOrder(['status' => 'approved', 'pickup_date' => '2026-08-27', 'return_date' => '2026-09-05']);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('GET', '/api/v1/orders?status=no_show');
        $this->assertSame(200, $status);
        $order->refresh();
        $this->assertSame('no_show', $order->status);
        $event = OrderEvent::where('order_id', $order->id)->where('action', 'mark_no_show')->first();
        $this->assertNotNull($event);
        $this->assertSame('system', $event->actor_type);
    }

    public function testEveryTransitionWritesExactlyOneEvent(): void
    {
        $order = $this->seedOrder(['status' => 'pending', 'pickup_date' => '2026-09-10', 'return_date' => '2026-09-12']);
        $tech = $this->actingAs('technician');
        $this->json('POST', "/api/v1/orders/{$order->id}/approve", ['comment' => 'ok']);
        $events = OrderEvent::where('order_id', $order->id)->get();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('pending', $event->from_status);
        $this->assertSame('approved', $event->to_status);
        $this->assertSame('approve', $event->action);
        $this->assertSame((int) $tech->id, (int) $event->actor_id);
        $this->assertSame('technician', $event->actor_role);
        $this->assertSame('user', $event->actor_type);

        $this->json('POST', "/api/v1/orders/{$order->id}/pickup", []);
        $this->assertSame(2, OrderEvent::where('order_id', $order->id)->count());
        $this->json('POST', "/api/v1/orders/{$order->id}/return", []);
        $this->assertSame(3, OrderEvent::where('order_id', $order->id)->count());
    }

    public function testAllowedActionsMatchStateMachinePerRole(): void
    {
        $machine = $this->container()->get(\App\Domain\Orders\OrderStateMachine::class);
        foreach (['pending', 'approved', 'picked_up', 'returned'] as $from) {
            $order = $this->seedOrder(['status' => $from, 'pickup_date' => '2026-09-20', 'return_date' => '2026-09-21']);
            foreach (['student', 'assistant', 'technician', 'admin'] as $role) {
                $viewer = $this->actingAs($role);
                [$status, $payload] = $this->json('GET', "/api/v1/orders/{$order->id}");
                $this->assertSame(200, $status);
                $expected = $machine->allowedActions($order->refresh(), $viewer);
                sort($expected);
                $actual = $payload['allowed_actions'];
                sort($actual);
                $this->assertSame($expected, $actual, "status={$from} role={$role}");
            }
        }
    }

    public function testPickupWithExplicitAssignments(): void
    {
        $product = $this->seedProduct([], 3);
        $order = $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-02',
            'return_date' => '2026-09-04',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $item = OrderItem::where('order_id', $order->id)->first();
        $units = ProductUnit::where('product_id', $product->id)->orderBy('label')->get();
        $this->actingAs('technician');

        // Wrong unit count -> 422.
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/pickup", [
            'assignments' => [['order_item_id' => $item->id, 'product_unit_ids' => [$units[0]->id], 'condition_out' => 'ok']],
        ]);
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'validation_failed');

        // Correct assignment succeeds.
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/pickup", [
            'assignments' => [['order_item_id' => $item->id, 'product_unit_ids' => [$units[0]->id, $units[1]->id], 'condition_out' => 'ok']],
        ]);
        $this->assertSame(200, $status, json_encode($payload));
        $this->assertCount(2, $payload['items'][0]['assigned_units']);

        // A unit already out on an active order -> 409 unit_in_use for another order.
        $order2 = $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-02',
            'return_date' => '2026-09-04',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $item2 = OrderItem::where('order_id', $order2->id)->first();
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order2->id}/pickup", [
            'assignments' => [['order_item_id' => $item2->id, 'product_unit_ids' => [$units[0]->id], 'condition_out' => 'ok']],
        ]);
        $this->assertSame(409, $status);
        $this->assertErrorEnvelope($payload, 'unit_in_use');
    }

    public function testPickupAutoAssignsLowestLabels(): void
    {
        $product = $this->seedProduct([], 4);
        $order = $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-02',
            'return_date' => '2026-09-04',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/pickup", []);
        $this->assertSame(200, $status);
        $labels = array_map(static fn ($u) => $u['unit_label'], $payload['items'][0]['assigned_units']);
        sort($labels);
        $this->assertSame(['01', '02'], $labels);
    }

    public function testReturnConditionsFlipUnitStatus(): void
    {
        $product = $this->seedProduct([], 2);
        $order = $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-02',
            'return_date' => '2026-09-04',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $this->actingAs('technician');
        [, $picked] = $this->json('POST', "/api/v1/orders/{$order->id}/pickup", []);
        $assigned = $picked['items'][0]['assigned_units'];
        $item = OrderItem::where('order_id', $order->id)->first();
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/return", [
            'returns' => [[
                'order_item_id' => $item->id,
                'returned_quantity' => 2,
                'units' => [
                    ['product_unit_id' => $assigned[0]['product_unit_id'], 'condition_in' => 'damaged', 'note' => 'Cinturino lento'],
                    ['product_unit_id' => $assigned[1]['product_unit_id'], 'condition_in' => 'missing'],
                ],
            ]],
            'logs' => [[
                'product_id' => $product->id,
                'product_unit_id' => $assigned[0]['product_unit_id'],
                'type' => 'damage',
                'severity' => 'warning',
                'title' => 'Cinturino lento',
                'is_public' => true,
            ]],
        ]);
        $this->assertSame(200, $status, json_encode($payload));
        $this->assertSame('maintenance', ProductUnit::find($assigned[0]['product_unit_id'])->status);
        $this->assertSame('missing', ProductUnit::find($assigned[1]['product_unit_id'])->status);
        // SPEC test #69: return-created logs carry order_id.
        $log = \App\Models\ProductLog::where('product_id', $product->id)->where('title', 'Cinturino lento')->first();
        $this->assertNotNull($log);
        $this->assertSame((int) $order->id, (int) $log->order_id);
    }

    public function testOrderCodeFormatAndYearlySequence(): void
    {
        $this->setSetting('regulations.enforce_global_acceptance', false);
        $product = $this->seedProduct([], 5);
        $this->actingAs('student');
        $pickup = '2026-09-07';
        $return = '2026-09-08';
        $first = null;
        foreach ([1, 2] as $i) {
            [$status, $payload] = $this->json('POST', '/api/v1/orders', [
                'from_cart' => false,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'pickup_date' => $pickup, 'pickup_time' => '09:30',
                'return_date' => $return, 'return_time' => '14:00',
                'subject' => 'Materia', 'motivation' => 'Motivazione lunga a sufficienza per il test.',
            ]);
            $this->assertSame(201, $status, json_encode($payload));
            $this->assertMatchesRegularExpression('/^VL-2026-\d{4}$/', $payload['code']);
            if ($first === null) {
                $first = $payload['code'];
            } else {
                $firstSeq = (int) substr($first, -4);
                $this->assertSame(sprintf('VL-2026-%04d', $firstSeq + 1), $payload['code']);
            }
        }
    }

    public function testApproveRevalidatesAvailability(): void
    {
        $product = $this->seedProduct([], 1);
        $order = $this->seedOrder([
            'status' => 'pending',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-12',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        // A competing approved order consumes the only unit.
        $this->seedOrder([
            'status' => 'approved',
            'user_id' => User::where('ldap_uid', 'student2')->first()->id,
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-12',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/approve", []);
        $this->assertSame(409, $status);
        $this->assertErrorEnvelope($payload, 'insufficient_availability');
        $this->assertNotEmpty($payload['error']['details']['products']);
    }

    public function testStaffEditRevalidatesAndRefreshesLimits(): void
    {
        $product = $this->seedProduct([], 3);
        $order = $this->seedOrder([
            'status' => 'pending',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-11',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->actingAs('technician');
        // Extend to 10 days -> soft violation recorded on the order.
        [$status, $payload] = $this->json('PUT', "/api/v1/orders/{$order->id}", [
            'return_date' => '2026-09-19',
            'staff_notes' => 'Esteso su richiesta.',
        ]);
        $this->assertSame(200, $status, json_encode($payload));
        $this->assertTrue($payload['exceeds_limits']);
        $this->assertNotEmpty($payload['limit_violations']);
        $this->assertSame('Esteso su richiesta.', $payload['staff_notes']);
        // Editing a picked_up order is refused.
        $picked = $this->seedOrder(['status' => 'picked_up']);
        [$status] = $this->json('PUT', "/api/v1/orders/{$picked->id}", ['subject' => 'X']);
        $this->assertSame(409, $status);
    }
}
