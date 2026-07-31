<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Orders\LimitsEvaluator;
use App\Models\User;
use Tests\TestCase;

final class LimitsEvaluatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
    }

    private function evaluator(): LimitsEvaluator
    {
        return $this->container()->get(LimitsEvaluator::class);
    }

    private function student(): User
    {
        return User::where('ldap_uid', 'student1')->first();
    }

    /** @return array<int,array<string,mixed>> */
    private function items(int $quantity = 1, array $productOverrides = []): array
    {
        $product = $this->seedProduct($productOverrides, 5);
        return [['product_id' => (int) $product->id, 'quantity' => $quantity, 'product' => $product]];
    }

    private function codes(array $violations): array
    {
        return array_map(static fn ($v) => $v['code'], $violations);
    }

    private function find(array $violations, string $code): ?array
    {
        foreach ($violations as $v) {
            if ($v['code'] === $code) {
                return $v;
            }
        }
        return null;
    }

    public function testDurationOverMaxLoanDaysIsSoft(): void
    {
        // 10 days: Mon 7 Sep -> Wed 16 Sep.
        $violations = $this->evaluator()->evaluate($this->student(), $this->items(), '2026-09-07', '09:30', '2026-09-16', '14:00');
        $v = $this->find($violations, 'max_loan_days_exceeded');
        $this->assertNotNull($v);
        $this->assertSame('soft', $v['severity']);
        $this->assertSame(7, $v['limit']);
        $this->assertSame(10, $v['actual']);
    }

    public function testDurationOverHardCapIsHard(): void
    {
        $this->setSetting('booking.max_advance_days', 120);
        // 32 days.
        $violations = $this->evaluator()->evaluate($this->student(), $this->items(), '2026-09-07', '09:30', '2026-10-08', '14:00');
        $v = $this->find($violations, 'max_loan_days_hard_cap_exceeded');
        $this->assertNotNull($v);
        $this->assertSame('hard', $v['severity']);
        $this->assertSame(30, $v['limit']);
    }

    public function testNullMonthlyLimitSkipsCheckEntirely(): void
    {
        $this->setSetting('booking.max_orders_per_month', null);
        foreach (range(1, 6) as $i) {
            $this->seedOrder(['pickup_date' => '2026-09-0' . $i, 'return_date' => '2026-09-0' . $i]);
        }
        $violations = $this->evaluator()->evaluate($this->student(), $this->items(), '2026-09-07', '09:30', '2026-09-08', '14:00');
        $this->assertNotContains('max_orders_per_month_exceeded', $this->codes($violations));
    }

    public function testMonthlyLimitCountsOnlySameMonthAndRealStatuses(): void
    {
        $this->setSetting('booking.max_orders_per_month', 2);
        $this->setSetting('booking.max_active_orders', null);
        // Two counting orders in September.
        $this->seedOrder(['status' => 'returned', 'pickup_date' => '2026-09-02', 'return_date' => '2026-09-03']);
        $this->seedOrder(['status' => 'pending', 'pickup_date' => '2026-09-04', 'return_date' => '2026-09-05']);
        // Not counting: other month, cancelled, rejected.
        $this->seedOrder(['status' => 'returned', 'pickup_date' => '2026-08-10', 'return_date' => '2026-08-11']);
        $this->seedOrder(['status' => 'cancelled', 'pickup_date' => '2026-09-10', 'return_date' => '2026-09-11']);
        $this->seedOrder(['status' => 'rejected', 'pickup_date' => '2026-09-10', 'return_date' => '2026-09-11']);

        $violations = $this->evaluator()->evaluate($this->student(), $this->items(), '2026-09-07', '09:30', '2026-09-08', '14:00');
        $v = $this->find($violations, 'max_orders_per_month_exceeded');
        $this->assertNotNull($v);
        $this->assertSame('soft', $v['severity']);
        $this->assertSame(2, $v['limit']);

        // A pickup in October is unaffected.
        $violations = $this->evaluator()->evaluate($this->student(), $this->items(), '2026-10-05', '09:30', '2026-10-06', '14:00');
        $this->assertNotContains('max_orders_per_month_exceeded', $this->codes($violations));
    }

    public function testYearlyLimitBehavesAnalogously(): void
    {
        $this->setSetting('booking.max_orders_per_year', 2);
        $this->setSetting('booking.max_orders_per_month', null);
        $this->setSetting('booking.max_active_orders', null);
        $this->seedOrder(['status' => 'returned', 'pickup_date' => '2026-03-02', 'return_date' => '2026-03-03']);
        $this->seedOrder(['status' => 'returned', 'pickup_date' => '2026-05-04', 'return_date' => '2026-05-05']);
        $violations = $this->evaluator()->evaluate($this->student(), $this->items(), '2026-09-07', '09:30', '2026-09-08', '14:00');
        $this->assertContains('max_orders_per_year_exceeded', $this->codes($violations));
        $this->assertSame('soft', $this->find($violations, 'max_orders_per_year_exceeded')['severity']);
    }

    public function testActiveOrdersCountsOnlyActiveStatuses(): void
    {
        $this->setSetting('booking.max_active_orders', 2);
        $this->setSetting('booking.max_orders_per_month', null);
        $this->seedOrder(['status' => 'pending', 'pickup_date' => '2026-09-02', 'return_date' => '2026-09-03']);
        $this->seedOrder(['status' => 'picked_up', 'pickup_date' => '2026-08-27', 'return_date' => '2026-09-03']);
        // Non-active statuses do not count.
        $this->seedOrder(['status' => 'returned', 'pickup_date' => '2026-08-01', 'return_date' => '2026-08-02']);
        $this->seedOrder(['status' => 'rejected', 'pickup_date' => '2026-09-01', 'return_date' => '2026-09-02']);
        $violations = $this->evaluator()->evaluate($this->student(), $this->items(), '2026-09-07', '09:30', '2026-09-08', '14:00');
        $v = $this->find($violations, 'max_active_orders_exceeded');
        $this->assertNotNull($v);
        $this->assertSame('soft', $v['severity']);
    }

    public function testCartShapeLimitsAreHard(): void
    {
        $this->setSetting('booking.max_items_per_order', 2);
        $items = [];
        foreach (range(1, 3) as $i) {
            $product = $this->seedProduct([], 5);
            $items[] = ['product_id' => (int) $product->id, 'quantity' => 1, 'product' => $product];
        }
        $violations = $this->evaluator()->evaluate($this->student(), $items, '2026-09-07', '09:30', '2026-09-08', '14:00');
        $v = $this->find($violations, 'max_items_per_order_exceeded');
        $this->assertNotNull($v);
        $this->assertSame('hard', $v['severity']);

        $violations = $this->evaluator()->evaluate($this->student(), $this->items(5), '2026-09-07', '09:30', '2026-09-08', '14:00');
        $v = $this->find($violations, 'max_quantity_per_product_exceeded');
        $this->assertNotNull($v);
        $this->assertSame('hard', $v['severity']);
        $this->assertSame(2, $v['limit']);
    }

    public function testAllowExceedingFalseTurnsSoftIntoHard(): void
    {
        $this->setSetting('booking.allow_exceeding_limits', false);
        $violations = $this->evaluator()->evaluate($this->student(), $this->items(), '2026-09-07', '09:30', '2026-09-16', '14:00');
        $v = $this->find($violations, 'max_loan_days_exceeded');
        $this->assertNotNull($v);
        $this->assertSame('hard', $v['severity']);
    }

    public function testProductLevelNarrowerMaxLoanDaysWins(): void
    {
        // Product cap 2 days < global 7: a 3-day loan violates with limit 2.
        $items = $this->items(1, ['max_loan_days' => 2]);
        $violations = $this->evaluator()->evaluate($this->student(), $items, '2026-09-07', '09:30', '2026-09-09', '14:00');
        $v = $this->find($violations, 'max_loan_days_exceeded');
        $this->assertNotNull($v);
        $this->assertSame(2, $v['limit']);
        $this->assertSame([$items[0]['product_id']], $v['product_ids']);
    }

    public function testOnSiteOnlyMultiDayIsHard(): void
    {
        $items = $this->items(1, ['loan_mode' => 'on_site_only']);
        $violations = $this->evaluator()->evaluate($this->student(), $items, '2026-09-07', '09:30', '2026-09-08', '14:00');
        $v = $this->find($violations, 'on_site_only_multi_day');
        $this->assertNotNull($v);
        $this->assertSame('hard', $v['severity']);
        // Same-day loan is fine.
        $violations = $this->evaluator()->evaluate($this->student(), $items, '2026-09-07', '09:30', '2026-09-07', '14:00');
        $this->assertNotContains('on_site_only_multi_day', $this->codes($violations));
    }

    public function testCalendarViolations(): void
    {
        // Sunday pickup -> date_not_bookable.
        $violations = $this->evaluator()->evaluate($this->student(), $this->items(), '2026-09-06', '09:30', '2026-09-07', '14:00');
        $this->assertContains('date_not_bookable', $this->codes($violations));
        // Outside advance window -> advance_window_violated.
        $violations = $this->evaluator()->evaluate($this->student(), $this->items(), '2027-08-02', '09:30', '2027-08-03', '14:00');
        $this->assertContains('advance_window_violated', $this->codes($violations));
        // Bad slot time -> slot_not_available.
        $violations = $this->evaluator()->evaluate($this->student(), $this->items(), '2026-09-07', '13:13', '2026-09-08', '14:00');
        $this->assertContains('slot_not_available', $this->codes($violations));
    }

    public function testInsufficientAvailabilityFromPrecomputedInfo(): void
    {
        $items = $this->items(2);
        $availability = [$items[0]['product_id'] => ['capacity' => 5, 'available' => 1]];
        $violations = $this->evaluator()->evaluate($this->student(), $items, '2026-09-07', '09:30', '2026-09-08', '14:00', null, $availability);
        $v = $this->find($violations, 'insufficient_availability');
        $this->assertNotNull($v);
        $this->assertSame('hard', $v['severity']);
        $this->assertSame([$items[0]['product_id']], $v['product_ids']);
    }
}
