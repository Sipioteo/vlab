<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

/**
 * Admin full order editing (`orders.edit_full`) + POST /orders/{id}/change-dates.
 *
 * Owner requirements: the admin can edit any order in any state (past, present,
 * future) with an availability re-check on the new configuration and a `force`
 * override; on the student side a submitted order is FROZEN — no date editing,
 * not even while pending.
 */
final class OrderEditFullTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
        $this->setSetting('booking.max_orders_per_month', null);
        $this->setSetting('booking.max_active_orders', null);
    }

    // ------------------------------------------------------------ full edit --

    public function testAdminEditsItemsAndDatesOnReturnedPastOrderWithAuditTrail(): void
    {
        $productA = $this->seedProduct([], 2);
        $productB = $this->seedProduct([], 2);
        $order = $this->seedOrder([
            'status' => 'returned',
            'pickup_date' => '2026-08-10',
            'return_date' => '2026-08-12',
            'items' => [['product_id' => $productA->id, 'quantity' => 1]],
        ]);
        $this->actingAs('admin');
        [$status, $payload] = $this->json('PUT', "/api/v1/orders/{$order->id}", [
            'pickup_date' => '2026-08-11',
            'return_date' => '2026-08-13',
            'pickup_time' => '10:30',
            'subject' => 'Materia corretta',
            'professor' => 'Prof. Verdi',
            'motivation' => 'Motivazione corretta dopo la riconsegna, come avvenuta davvero.',
            'notes' => 'Nota studente corretta.',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 2],
                ['product_id' => $productB->id, 'quantity' => 1],
            ],
        ]);
        $this->assertSame(200, $status, json_encode($payload));
        $this->assertSame('returned', $payload['status'], 'status is never touched by the edit');
        $this->assertSame('2026-08-11', $payload['pickup_date']);
        $this->assertSame('2026-08-13', $payload['return_date']);
        $this->assertSame('10:30', $payload['pickup_time']);
        $this->assertSame('Materia corretta', $payload['subject']);
        $this->assertSame('Prof. Verdi', $payload['professor']);
        $this->assertSame('Nota studente corretta.', $payload['notes']);
        $this->assertSame(3, $payload['items_count']);
        $this->assertArrayNotHasKey('forced_overbook', $payload);

        // Order event records the old→new summary.
        $event = OrderEvent::where('order_id', $order->id)->where('action', 'edit')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $meta = json_decode((string) $event->meta, true);
        $this->assertTrue($meta['edit_full']);
        $this->assertSame('2026-08-10', $meta['changes']['pickup_date']['before']);
        $this->assertSame('2026-08-11', $meta['changes']['pickup_date']['after']);
        $this->assertArrayHasKey('items', $meta['changes']);

        // Audit log entry with before/after.
        $audit = AuditLog::where('action', 'order.edit_full')->where('entity_id', (string) $order->id)->first();
        $this->assertNotNull($audit);
        $auditChanges = json_decode((string) $audit->changes, true);
        $this->assertSame('Materia corretta', $auditChanges['after']['subject']);
    }

    public function testAdminEditPreservesItemRowsAndRemovesDroppedOnes(): void
    {
        $productA = $this->seedProduct([], 2);
        $productB = $this->seedProduct([], 2);
        $order = $this->seedOrder([
            'status' => 'approved',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 1],
                ['product_id' => $productB->id, 'quantity' => 1],
            ],
        ]);
        $keptItemId = (int) OrderItem::where('order_id', $order->id)->where('product_id', $productA->id)->value('id');
        $this->actingAs('admin');
        [$status] = $this->json('PUT', "/api/v1/orders/{$order->id}", [
            'items' => [['product_id' => $productA->id, 'quantity' => 2]],
        ]);
        $this->assertSame(200, $status);
        $rows = OrderItem::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($keptItemId, (int) $rows[0]->id, 'existing row is kept, not recreated');
        $this->assertSame(2, (int) $rows[0]->quantity);
    }

    public function testAvailabilityConflictReturns422WithProductDetails(): void
    {
        $product = $this->seedProduct([], 1); // capacity 1
        $order = $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-12',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        // A second locking order takes the only unit on 2026-09-15..16.
        $other = User::where('ldap_uid', 'student2')->first();
        $this->seedOrder([
            'user_id' => $other->id,
            'status' => 'approved',
            'pickup_date' => '2026-09-15',
            'return_date' => '2026-09-16',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->actingAs('admin');
        [$status, $payload] = $this->json('PUT', "/api/v1/orders/{$order->id}", [
            'pickup_date' => '2026-09-15',
            'return_date' => '2026-09-16',
        ]);
        $this->assertSame(422, $status, json_encode($payload));
        $this->assertErrorEnvelope($payload, 'insufficient_availability');
        $products = $payload['error']['details']['products'];
        $this->assertCount(1, $products);
        $this->assertSame($product->id, $products[0]['product_id']);
        $this->assertSame(1, $products[0]['requested']);
        $this->assertSame(0, $products[0]['available']);
        // Nothing was saved.
        $this->assertSame('2026-09-10', (string) \App\Support\Dates::datePart($order->refresh()->pickup_date));
    }

    public function testForceOverridesConflictAndFlagsTheOverbooking(): void
    {
        $product = $this->seedProduct([], 1);
        $order = $this->seedOrder([
            'status' => 'returned',
            'pickup_date' => '2026-08-10',
            'return_date' => '2026-08-11',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $other = User::where('ldap_uid', 'student2')->first();
        $this->seedOrder([
            'user_id' => $other->id,
            'status' => 'approved',
            'pickup_date' => '2026-09-15',
            'return_date' => '2026-09-16',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->actingAs('admin');
        [$status, $payload] = $this->json('PUT', "/api/v1/orders/{$order->id}", [
            'pickup_date' => '2026-09-15',
            'return_date' => '2026-09-16',
            'force' => true,
        ]);
        $this->assertSame(200, $status, json_encode($payload));
        $this->assertTrue($payload['forced_overbook']);
        $this->assertSame($product->id, $payload['overbooked_products'][0]['product_id']);

        $event = OrderEvent::where('order_id', $order->id)->where('action', 'edit')->orderByDesc('id')->first();
        $meta = json_decode((string) $event->meta, true);
        $this->assertTrue($meta['forced']);
        $this->assertNotEmpty($meta['overbooked_products']);

        $audit = AuditLog::where('action', 'order.edit_full')->where('entity_id', (string) $order->id)->first();
        $this->assertNotNull($audit);
        $this->assertNotEmpty(json_decode((string) $audit->changes, true)['forced_overbook']);
    }

    public function testAdminEditReevaluatesLimitsAndFlagsSoftViolations(): void
    {
        $this->setSetting('booking.max_loan_days', 7);
        $order = $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-11',
        ]);
        $this->actingAs('admin');
        [$status, $payload] = $this->json('PUT', "/api/v1/orders/{$order->id}", [
            'return_date' => '2026-09-25',
        ]);
        $this->assertSame(200, $status, json_encode($payload));
        $this->assertTrue($payload['exceeds_limits']);
        $codes = array_column($payload['limit_violations'], 'code');
        $this->assertContains('max_loan_days_exceeded', $codes);
    }

    // ------------------------------------------------- non-admin exclusions --

    public function testTechnicianAndAssistantAreForbiddenFromFullEdit(): void
    {
        foreach (['technician', 'assistant'] as $role) {
            $order = $this->seedOrder(['status' => 'pending']);
            $this->actingAs($role);
            // Full-edit-only field on an editable order → 403.
            [$status, $payload] = $this->json('PUT', "/api/v1/orders/{$order->id}", [
                'motivation' => 'Tentativo di modifica completa.',
            ]);
            $this->assertSame(403, $status, $role);
            $this->assertErrorEnvelope($payload, 'forbidden');
            // `force` is admin territory too.
            [$status] = $this->json('PUT', "/api/v1/orders/{$order->id}", ['force' => true]);
            $this->assertSame(403, $status, $role);
            // Editing outside pending/approved stays refused (unchanged 409).
            $returned = $this->seedOrder(['status' => 'returned']);
            [$status] = $this->json('PUT', "/api/v1/orders/{$returned->id}", ['subject' => 'X']);
            $this->assertSame(409, $status, $role);
            // change-dates endpoint is admin-only.
            [$status] = $this->json('POST', "/api/v1/orders/{$order->id}/change-dates", ['pickup_date' => '2026-09-10']);
            $this->assertSame(403, $status, $role);
        }
    }

    public function testTechnicianLegacyEditStillWorks(): void
    {
        $order = $this->seedOrder(['status' => 'pending']);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('PUT', "/api/v1/orders/{$order->id}", [
            'subject' => 'Materia aggiornata',
            'staff_notes' => 'Nota staff.',
        ]);
        $this->assertSame(200, $status, json_encode($payload));
        $this->assertSame('Materia aggiornata', $payload['subject']);
        $this->assertSame('Nota staff.', $payload['staff_notes']);
    }

    /** A submitted order is frozen on the student side — even while pending. */
    public function testOwnerStudentCannotChangeDatesEvenWhilePending(): void
    {
        $order = $this->seedOrder(['status' => 'pending']);
        $this->actingAs('student'); // student1 owns seeded orders
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/change-dates", [
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-11',
        ]);
        $this->assertSame(403, $status, json_encode($payload));
        [$status] = $this->json('PUT', "/api/v1/orders/{$order->id}", ['pickup_date' => '2026-09-10']);
        $this->assertSame(403, $status);
        // And on an approved order as well.
        $approved = $this->seedOrder(['status' => 'approved']);
        [$status] = $this->json('POST', "/api/v1/orders/{$approved->id}/change-dates", ['pickup_date' => '2026-09-10']);
        $this->assertSame(403, $status);
    }

    // ----------------------------------------------------------- change-dates --

    public function testAdminChangeDatesEndpointUpdatesDatesWithAvailabilityCheck(): void
    {
        $product = $this->seedProduct([], 2);
        $order = $this->seedOrder([
            'status' => 'pending',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-11',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->actingAs('admin');
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/change-dates", [
            'pickup_date' => '2026-09-14',
            'return_date' => '2026-09-16',
            'subject' => 'IGNORED', // not part of the dates-only surface
        ]);
        $this->assertSame(200, $status, json_encode($payload));
        $this->assertSame('2026-09-14', $payload['pickup_date']);
        $this->assertSame('2026-09-16', $payload['return_date']);
        $this->assertSame('Materia di test', $payload['subject'], 'non-date fields are ignored');
    }

    public function testChangeDatesConflictReturns422(): void
    {
        $product = $this->seedProduct([], 1);
        $order = $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-11',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $other = User::where('ldap_uid', 'student2')->first();
        $this->seedOrder([
            'user_id' => $other->id,
            'status' => 'picked_up',
            'pickup_date' => '2026-09-21',
            'return_date' => '2026-09-22',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->actingAs('admin');
        [$status, $payload] = $this->json('POST', "/api/v1/orders/{$order->id}/change-dates", [
            'pickup_date' => '2026-09-21',
            'return_date' => '2026-09-22',
        ]);
        $this->assertSame(422, $status, json_encode($payload));
        $this->assertErrorEnvelope($payload, 'insufficient_availability');
        $this->assertSame($product->id, $payload['error']['details']['products'][0]['product_id']);
    }

    // -------------------------------------------------------- allowed_actions --

    public function testAllowedActionsContainChangeDatesOnlyForAdmin(): void
    {
        foreach (['pending', 'approved', 'returned', 'picked_up'] as $st) {
            $order = $this->seedOrder(['status' => $st]);
            $this->actingAs('admin');
            [, $payload] = $this->json('GET', "/api/v1/orders/{$order->id}");
            $this->assertContains('change_dates', $payload['allowed_actions'], "admin {$st}");
            foreach (['technician', 'assistant'] as $role) {
                $this->actingAs($role);
                [, $payload] = $this->json('GET', "/api/v1/orders/{$order->id}");
                $this->assertNotContains('change_dates', $payload['allowed_actions'], "{$role} {$st}");
            }
        }
        // The owner never sees it — the order is frozen student-side.
        $order = $this->seedOrder(['status' => 'pending']);
        $this->actingAs('student');
        [, $payload] = $this->json('GET', "/api/v1/orders/{$order->id}");
        $this->assertNotContains('change_dates', $payload['allowed_actions']);
    }

    public function testEditFullPermissionExposedInAuthMe(): void
    {
        $expected = ['admin' => true, 'technician' => false, 'assistant' => false, 'student' => false];
        foreach ($expected as $role => $value) {
            $this->actingAs($role);
            [, $payload] = $this->json('GET', '/api/v1/auth/me');
            $this->assertSame($value, $payload['permissions']['orders.edit_full'], $role);
        }
    }
}
