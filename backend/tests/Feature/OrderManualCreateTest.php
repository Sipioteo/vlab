<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Regulation;
use App\Models\User;
use Tests\TestCase;

/**
 * Staff manual loan creation — POST /api/v1/orders/manual
 * (`orders.create_manual`: technician + admin).
 *
 * Covers the owner's gap: today orders are born only from the student cart
 * checkout; the walk-in at the counter, the phone booking and the
 * after-the-fact correction need a staff-side entry point.
 */
final class OrderManualCreateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
        $this->setSetting('booking.max_orders_per_month', null);
        $this->setSetting('booking.max_active_orders', null);
    }

    /** @return array<string,mixed> */
    private function payload(int $productId, array $overrides = []): array
    {
        return $overrides + [
            'username' => 'student1',
            'items' => [['product_id' => $productId, 'quantity' => 1]],
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-12',
            'pickup_time' => '09:30',
            'return_time' => '16:00',
            'subject' => 'Laboratorio di Ripresa',
            'motivation' => 'Prestito registrato allo sportello dal tecnico.',
        ];
    }

    // ---------------------------------------------------------- happy paths --

    public function testTechnicianCreatesApprovedManualOrderWithStaffActorEvents(): void
    {
        $product = $this->seedProduct([], 2);
        $tech = $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $this->payload($product->id, [
            'staff_notes' => 'Consegna immediata allo sportello.',
        ]));
        $this->assertSame(201, $status, json_encode($payload));

        // Normal order-creation side effects: code, snapshots, counters.
        $this->assertMatchesRegularExpression('/^VL-2026-\d{4}$/', $payload['code']);
        $this->assertSame('approved', $payload['status'], 'walk-in default is approved');
        $student = User::where('ldap_uid', 'student1')->first();
        $this->assertSame((int) $student->id, $payload['user']['id']);
        $this->assertSame(1, $payload['items_count']);
        $this->assertSame($product->name, $payload['items'][0]['product_name_snapshot']);
        $this->assertSame('Consegna immediata allo sportello.', $payload['staff_notes']);
        $this->assertSame((int) $tech->id, $payload['decided_by']['id'], 'approval actor is the staff member');

        // Events: manual `create` by staff, then a coherent state-machine approval.
        $orderId = (int) $payload['id'];
        $create = OrderEvent::where('order_id', $orderId)->where('action', 'create')->first();
        $this->assertNotNull($create);
        $this->assertNull($create->from_status);
        $this->assertSame('pending', $create->to_status);
        $this->assertSame((int) $tech->id, (int) $create->actor_id);
        $this->assertSame('technician', $create->actor_role);
        $meta = json_decode((string) $create->meta, true);
        $this->assertTrue($meta['manual']);
        $this->assertSame((int) $tech->id, $meta['created_by']);

        $approve = OrderEvent::where('order_id', $orderId)->where('action', 'approve')->first();
        $this->assertNotNull($approve, 'approved initial status goes through the state machine');
        $this->assertSame('pending', $approve->from_status);
        $this->assertSame('approved', $approve->to_status);
        $this->assertSame((int) $tech->id, (int) $approve->actor_id);

        // Audit trail.
        $audit = AuditLog::where('action', 'order.create_manual')->where('entity_id', (string) $orderId)->first();
        $this->assertNotNull($audit);
        $changes = json_decode((string) $audit->changes, true);
        $this->assertSame('student1', $changes['after']['user_ldap_uid']);
        $this->assertSame('approved', $changes['after']['initial_status']);
    }

    public function testPendingVariantSkipsApproval(): void
    {
        $product = $this->seedProduct([], 2);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $this->payload($product->id, [
            'initial_status' => 'pending',
        ]));
        $this->assertSame(201, $status, json_encode($payload));
        $this->assertSame('pending', $payload['status']);
        $this->assertNull($payload['decided_by']);
        $this->assertContains('approve', $payload['allowed_actions']);
        $orderId = (int) $payload['id'];
        $this->assertNull(OrderEvent::where('order_id', $orderId)->where('action', 'approve')->first());
        $this->assertNotNull(OrderEvent::where('order_id', $orderId)->where('action', 'create')->first());
    }

    public function testResolvesTargetByUserIdToo(): void
    {
        $product = $this->seedProduct([], 2);
        $student = User::where('ldap_uid', 'student2')->first();
        $this->actingAs('admin');
        $body = $this->payload($product->id, ['user_id' => (int) $student->id]);
        unset($body['username']);
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $body);
        $this->assertSame(201, $status, json_encode($payload));
        $this->assertSame((int) $student->id, $payload['user']['id']);
    }

    // ------------------------------------------------------- user resolution --

    public function testUnknownUsernameReturns422UserNotFound(): void
    {
        $product = $this->seedProduct([], 2);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $this->payload($product->id, [
            'username' => 'ghost-user',
        ]));
        $this->assertSame(422, $status, json_encode($payload));
        $this->assertErrorEnvelope($payload, 'user_not_found');
    }

    public function testUsernameKnownToDirectoryButNotLocalIsProvisionedLikeFirstLogin(): void
    {
        $product = $this->seedProduct([], 2);
        // student2 exists in the fake LDAP directory; drop the local row to
        // simulate a student that never logged into the platform.
        User::where('ldap_uid', 'student2')->forceDelete();
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $this->payload($product->id, [
            'username' => 'student2',
        ]));
        $this->assertSame(201, $status, json_encode($payload));
        $provisioned = User::where('ldap_uid', 'student2')->first();
        $this->assertNotNull($provisioned, 'user provisioned from the directory');
        $this->assertSame('student', $provisioned->role);
        $this->assertSame('ldap', $provisioned->role_source);
        $this->assertSame('Giulia Bianchi', $provisioned->display_name);
        $this->assertNull($provisioned->last_login_at, 'no fake login is recorded');
        $this->assertSame((int) $provisioned->id, $payload['user']['id']);
    }

    // -------------------------------------------------------------- security --

    public function testAssistantAndStudentGet403(): void
    {
        $product = $this->seedProduct([], 2);
        foreach (['assistant', 'student'] as $role) {
            $this->actingAs($role);
            [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $this->payload($product->id));
            $this->assertSame(403, $status, $role);
            $this->assertErrorEnvelope($payload, 'role_required');
        }
    }

    public function testTechnicianCannotForce(): void
    {
        $product = $this->seedProduct([], 1);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $this->payload($product->id, [
            'force' => true,
        ]));
        $this->assertSame(403, $status, json_encode($payload));
        $this->assertErrorEnvelope($payload, 'forbidden');
    }

    public function testPermissionExposedInAuthMe(): void
    {
        $expected = ['admin' => true, 'technician' => true, 'assistant' => false, 'student' => false];
        foreach ($expected as $role => $value) {
            $this->actingAs($role);
            [, $payload] = $this->json('GET', '/api/v1/auth/me');
            $this->assertSame($value, $payload['permissions']['orders.create_manual'], $role);
        }
    }

    // ---------------------------------------------------------- availability --

    public function testAvailabilityConflictReturns422WithProductDetails(): void
    {
        $product = $this->seedProduct([], 1); // capacity 1
        $other = User::where('ldap_uid', 'student2')->first();
        $this->seedOrder([
            'user_id' => $other->id,
            'status' => 'approved',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-12',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $this->payload($product->id));
        $this->assertSame(422, $status, json_encode($payload));
        $this->assertErrorEnvelope($payload, 'insufficient_availability');
        $products = $payload['error']['details']['products'];
        $this->assertCount(1, $products);
        $this->assertSame($product->id, $products[0]['product_id']);
        $this->assertSame(1, $products[0]['requested']);
        $this->assertSame(0, $products[0]['available']);
        $this->assertSame($product->name, $products[0]['name']);
        // Nothing was persisted for the target student.
        $student = User::where('ldap_uid', 'student1')->first();
        $this->assertSame(0, \App\Models\Order::where('user_id', $student->id)->count());
    }

    public function testAdminForceOverridesConflictAndFlagsOverbooking(): void
    {
        $product = $this->seedProduct([], 1);
        $other = User::where('ldap_uid', 'student2')->first();
        $this->seedOrder([
            'user_id' => $other->id,
            'status' => 'approved',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-12',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $admin = $this->actingAs('admin');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $this->payload($product->id, [
            'force' => true,
        ]));
        $this->assertSame(201, $status, json_encode($payload));
        $this->assertSame('approved', $payload['status'], 'force also carries the pending→approved transition');
        $this->assertTrue($payload['forced_overbook']);
        $this->assertSame($product->id, $payload['overbooked_products'][0]['product_id']);

        $orderId = (int) $payload['id'];
        $create = OrderEvent::where('order_id', $orderId)->where('action', 'create')->first();
        $meta = json_decode((string) $create->meta, true);
        $this->assertTrue($meta['manual']);
        $this->assertTrue($meta['forced']);
        $this->assertNotEmpty($meta['overbooked_products']);
        $this->assertSame((int) $admin->id, $meta['created_by']);

        $audit = AuditLog::where('action', 'order.create_manual')->where('entity_id', (string) $orderId)->first();
        $this->assertNotNull($audit);
        $this->assertNotEmpty(json_decode((string) $audit->changes, true)['forced_overbook']);
    }

    // ---------------------------------------------------------------- limits --

    public function testLimitsAreRecordedButNeverBlockStaff(): void
    {
        $this->setSetting('booking.max_loan_days', 3);
        $product = $this->seedProduct([], 2);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $this->payload($product->id, [
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-25', // 16 days > max 3
        ]));
        $this->assertSame(201, $status, json_encode($payload));
        $this->assertTrue($payload['exceeds_limits']);
        $codes = array_column($payload['limit_violations'], 'code');
        $this->assertContains('max_loan_days_exceeded', $codes);
    }

    // ------------------------------------------------------------ regulations --

    public function testRegulationsAreNotEnforcedButReportedAsPending(): void
    {
        $product = $this->seedProduct([], 2);
        Regulation::create([
            'slug' => 'regolamento-generale-test', 'title' => 'Regolamento generale', 'scope' => 'global',
            'content_type' => 'markdown', 'body' => 'x', 'requires_acceptance' => true,
            'is_active' => true, 'version' => 1, 'published_at' => '2026-01-01 00:00:00',
        ]);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $this->payload($product->id));
        $this->assertSame(201, $status, json_encode($payload), 'acceptance is NOT enforced server-side');
        $slugs = array_column($payload['pending_regulations'], 'slug');
        $this->assertContains('regolamento-generale-test', $slugs, 'operator is told what the student has not accepted');
        $this->assertTrue($payload['pending_regulations'][0]['blocking']);
    }

    // ------------------------------------------------------------- validation --

    public function testMissingFieldsFailValidation(): void
    {
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', ['username' => 'student1']);
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'validation_failed');
        foreach (['start_date', 'end_date', 'pickup_time', 'return_time', 'items'] as $field) {
            $this->assertArrayHasKey($field, $payload['error']['details'], $field);
        }
        // Neither user_id nor username → validation error as well.
        [$status2, $payload2] = $this->json('POST', '/api/v1/orders/manual', []);
        $this->assertSame(422, $status2);
        $this->assertArrayHasKey('username', $payload2['error']['details']);
    }
}
