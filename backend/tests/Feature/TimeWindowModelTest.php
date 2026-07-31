<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\OrderPdfService;
use App\Domain\Settings\SettingsRepository;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

/**
 * Time-window model (SPEC v1.4 §5.3/§7.4).
 *
 * NULL `pickup_time`/`return_time` = "the lab's window for that weekday"
 * (hours.pickup_windows / hours.return_windows, weekly fallback). Overrides
 * are staff-only: precise (`*_time`) or custom range (`*_time` + `*_time_end`).
 * Students never choose times — checkout tolerates but IGNORES them.
 */
final class TimeWindowModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 2026-09-01 is a Tuesday; 2026-09-07/08 Monday/Tuesday.
        $this->travelTo('2026-09-01 08:00:00');
    }

    /** @return array{0:int,1:array} [status, payload] of a student checkout */
    private function checkout(array $overrides = []): array
    {
        $product = $this->seedProduct([], 3);
        $this->actingAs('student');
        return $this->json('POST', '/api/v1/orders', $overrides + [
            'from_cart' => false,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'pickup_date' => '2026-09-07',
            'return_date' => '2026-09-08',
            'subject' => 'Materia finestra',
            'motivation' => 'Motivazione sufficientemente lunga per il test.',
        ]);
    }

    // -------------------------------------------------- student side (ignore)

    public function testCheckoutWithoutTimesStoresNullAndFallsBackToWindow(): void
    {
        [$status, $payload] = $this->checkout();
        $this->assertSame(201, $status, json_encode($payload));
        $this->assertNull($payload['pickup_time']);
        $this->assertNull($payload['return_time']);
        $this->assertNull($payload['pickup_time_end']);
        $this->assertNull($payload['return_time_end']);
        // Seeded windows: pickup Mon 09:00–12:30, return Tue 14:00–17:00.
        $this->assertSame('09:00–12:30', $payload['pickup_window']);
        $this->assertSame('14:00–17:00', $payload['return_window']);
    }

    public function testCheckoutToleratesButIgnoresStudentTimes(): void
    {
        [$status, $payload] = $this->checkout([
            'pickup_time' => '10:00',
            'return_time' => '15:00',
        ]);
        $this->assertSame(201, $status, json_encode($payload));
        $this->assertNull($payload['pickup_time'], 'student times are ignored, not honored');
        $this->assertNull($payload['return_time']);
        $this->assertSame('09:00–12:30', $payload['pickup_window']);
    }

    public function testWindowFallsBackToWeeklyOpeningWhenNoWindowsConfigured(): void
    {
        $this->setSetting('hours.pickup_windows', []);
        [$status, $payload] = $this->checkout();
        $this->assertSame(201, $status);
        $this->assertSame('09:00–17:00', $payload['pickup_window'], 'hours.weekly open/close fallback');
    }

    public function testAvailabilityCheckReportsWindowStrings(): void
    {
        $product = $this->seedProduct([], 3);
        $this->actingAs('student');
        [$status, $payload] = $this->json('POST', '/api/v1/availability/check', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'pickup_date' => '2026-09-07',
            'return_date' => '2026-09-08',
        ]);
        $this->assertSame(200, $status);
        $this->assertSame('09:00–12:30', $payload['pickup_window']);
        $this->assertSame('14:00–17:00', $payload['return_window']);
    }

    // ---------------------------------------------------- staff side (honor)

    public function testManualCreateWithoutTimesUsesWindow(): void
    {
        $product = $this->seedProduct([], 2);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', [
            'username' => 'student1',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-08',
        ]);
        $this->assertSame(201, $status, json_encode($payload));
        $this->assertNull($payload['pickup_time']);
        $this->assertSame('09:00–12:30', $payload['pickup_window']);
    }

    public function testManualCreateHonorsPreciseAndRangeOverrides(): void
    {
        $product = $this->seedProduct([], 2);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', [
            'username' => 'student1',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-08',
            'pickup_time' => '10:15',                       // precise
            'return_time' => '15:00', 'return_time_end' => '16:30', // range
        ]);
        $this->assertSame(201, $status, json_encode($payload));
        $this->assertSame('10:15', $payload['pickup_time']);
        $this->assertNull($payload['pickup_time_end']);
        $this->assertSame('10:15', $payload['pickup_window'], 'precise override wins over the window');
        $this->assertSame('15:00', $payload['return_time']);
        $this->assertSame('16:30', $payload['return_time_end']);
        $this->assertSame('15:00–16:30', $payload['return_window']);
    }

    public function testManualCreateRejectsEndWithoutStartAndInvertedRange(): void
    {
        $product = $this->seedProduct([], 2);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', [
            'username' => 'student1',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'start_date' => '2026-09-07', 'end_date' => '2026-09-08',
            'pickup_time_end' => '11:00',
        ]);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('pickup_time_end', $payload['error']['details']);

        [$status2, $payload2] = $this->json('POST', '/api/v1/orders/manual', [
            'username' => 'student1',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'start_date' => '2026-09-07', 'end_date' => '2026-09-08',
            'pickup_time' => '11:00', 'pickup_time_end' => '10:00',
        ]);
        $this->assertSame(422, $status2);
        $this->assertArrayHasKey('pickup_time_end', $payload2['error']['details']);
    }

    public function testOverrideOutsideOpeningHoursBlocksUnlessAdminForces(): void
    {
        $product = $this->seedProduct([], 2);
        $body = [
            'username' => 'student1',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'start_date' => '2026-09-07', 'end_date' => '2026-09-08',
            'pickup_time' => '18:30', // Monday closes at 17:00
        ];
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/orders/manual', $body);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('pickup_time', $payload['error']['details']);

        // Admin with force: warn-not-block — the order is created and the
        // warning stays on record among the limit violations.
        $this->actingAs('admin');
        [$status2, $payload2] = $this->json('POST', '/api/v1/orders/manual', $body + ['force' => true]);
        $this->assertSame(201, $status2, json_encode($payload2));
        $this->assertSame('18:30', $payload2['pickup_time']);
        $codes = array_column($payload2['limit_violations'], 'code');
        $this->assertContains('time_outside_opening_hours', $codes);
    }

    // ------------------------------------------------------------ admin edit

    public function testAdminEditSetsAndClearsOverrides(): void
    {
        $order = $this->seedOrder(['status' => 'approved', 'pickup_time' => null, 'return_time' => null]);
        $this->actingAs('admin');

        // Set a range override on pickup.
        [$status, $payload] = $this->json('PUT', "/api/v1/orders/{$order->id}", [
            'pickup_time' => '10:00', 'pickup_time_end' => '11:00',
        ]);
        $this->assertSame(200, $status, json_encode($payload));
        $this->assertSame('10:00–11:00', $payload['pickup_window']);

        // Clear it: back to the weekday window.
        [$status2, $payload2] = $this->json('PUT', "/api/v1/orders/{$order->id}", [
            'pickup_time' => null,
        ]);
        $this->assertSame(200, $status2, json_encode($payload2));
        $this->assertNull($payload2['pickup_time']);
        $this->assertNull($payload2['pickup_time_end'], 'clearing the start drops the dangling end');
        $this->assertNotNull($payload2['pickup_window']);
        $this->assertStringContainsString('–', (string) $payload2['pickup_window']);
    }

    public function testAdminEditOfUntouchedLegsNeverRevalidatesOldTimes(): void
    {
        // Pre-existing odd override (e.g. before an opening-hours change).
        $order = $this->seedOrder(['status' => 'approved', 'pickup_time' => '20:00', 'return_time' => null]);
        $this->actingAs('admin');
        [$status] = $this->json('PUT', "/api/v1/orders/{$order->id}", [
            'subject' => 'Solo la materia cambia',
        ]);
        $this->assertSame(200, $status, 'items/fields edits must not trip on legacy times');
    }

    // ------------------------------------------------------- PDF & iCal

    public function testPdfPrintsWindowTextOrOverride(): void
    {
        $pdf = new OrderPdfService(SettingsRepository::instance());

        $windowOrder = $this->seedOrder(['status' => 'approved', 'pickup_date' => '2026-09-07', 'return_date' => '2026-09-08', 'pickup_time' => null, 'return_time' => null]);
        $data = $pdf->data($windowOrder->fresh());
        $this->assertSame('09:00–12:30', $data['order']['pickup_time']);
        $this->assertSame('14:00–17:00', $data['order']['return_time']);
        $this->assertStringContainsString('09:00–12:30', $pdf->html($windowOrder->fresh()));

        $overrideOrder = $this->seedOrder(['status' => 'approved', 'pickup_date' => '2026-09-07', 'return_date' => '2026-09-08', 'pickup_time' => '10:15', 'pickup_time_end' => '11:30', 'return_time' => '16:00']);
        $data2 = $pdf->data($overrideOrder->fresh());
        $this->assertSame('10:15–11:30', $data2['order']['pickup_time']);
        $this->assertSame('16:00', $data2['order']['return_time']);
    }

    public function testIcalUsesWindowForNullTimesAndOverridesWhenSet(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();

        // NULL times → DTSTART/DTEND span the weekday window (Europe/Rome,
        // 2026-09-07 is CEST = UTC+2: 09:00 local → 07:00Z, 12:30 → 10:30Z).
        $this->seedOrder([
            'user_id' => $student->id, 'status' => 'approved',
            'pickup_date' => '2026-09-07', 'return_date' => '2026-09-08',
            'pickup_time' => null, 'return_time' => null,
        ]);
        $this->actingAs('student');
        [, $me] = $this->json('GET', '/api/v1/me/ical');
        $this->anonymous();
        [, , $response] = $this->json('GET', '/api/v1/ical/' . $me['token'] . '.ics');
        $body = (string) $response->getBody();
        $this->assertStringContainsString('DTSTART:20260907T070000Z', $body);
        $this->assertStringContainsString('DTEND:20260907T103000Z', $body);
        // Return window Tue 14:00–17:00 local → 12:00Z–15:00Z.
        $this->assertStringContainsString('DTSTART:20260908T120000Z', $body);
        $this->assertStringContainsString('DTEND:20260908T150000Z', $body);

        // Overrides: precise pickup 10:15 (+slot duration 30'), range return.
        Order::where('user_id', $student->id)->forceDelete();
        $this->seedOrder([
            'user_id' => $student->id, 'status' => 'approved',
            'pickup_date' => '2026-09-07', 'return_date' => '2026-09-08',
            'pickup_time' => '10:15', 'return_time' => '15:00', 'return_time_end' => '16:30',
        ]);
        $body2 = (string) $this->json('GET', '/api/v1/ical/' . $me['token'] . '.ics')[2]->getBody();
        $this->assertStringContainsString('DTSTART:20260907T081500Z', $body2);
        $this->assertStringContainsString('DTEND:20260907T084500Z', $body2);
        $this->assertStringContainsString('DTSTART:20260908T130000Z', $body2);
        $this->assertStringContainsString('DTEND:20260908T143000Z', $body2);
    }
}
