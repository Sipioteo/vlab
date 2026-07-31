<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Availability\AvailabilityService;
use App\Models\ProductUnit;
use Illuminate\Database\Capsule\Manager as Capsule;
use Tests\TestCase;

final class AvailabilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
    }

    private function service(): AvailabilityService
    {
        return $this->container()->get(AvailabilityService::class);
    }

    public function testCapacityCountsOnlyAvailableUnits(): void
    {
        $product = $this->seedProduct([], 2);
        foreach (['maintenance', 'missing', 'retired', 'internal_use'] as $i => $status) {
            ProductUnit::create([
                'product_id' => $product->id,
                'label' => sprintf('%02d', $i + 3),
                'status' => $status,
            ]);
        }
        $capacities = $this->service()->capacities([(int) $product->id]);
        $this->assertSame(2, $capacities[(int) $product->id]);
    }

    public function testProductStatusNotAvailableForcesCapacityZero(): void
    {
        $product = $this->seedProduct(['status' => 'maintenance'], 4);
        $this->assertSame(0, $this->service()->capacities([(int) $product->id])[(int) $product->id]);
        $retired = $this->seedProduct(['status' => 'retired'], 4);
        $this->assertSame(0, $this->service()->capacities([(int) $retired->id])[(int) $retired->id]);
    }

    public function testOverlappingApprovedOrderReducesAvailability(): void
    {
        $product = $this->seedProduct([], 5);
        $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-12',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-11', '2026-09-13');
        $this->assertSame(3, $info[(int) $product->id]['available']);
        $this->assertSame(5, $info[(int) $product->id]['capacity']);
    }

    public function testNonLockingStatusesDoNotReduceAvailability(): void
    {
        $product = $this->seedProduct([], 3);
        foreach (['draft', 'rejected', 'cancelled', 'no_show', 'returned', 'returned_late'] as $status) {
            $this->seedOrder([
                'status' => $status,
                'pickup_date' => '2026-09-10',
                'return_date' => '2026-09-12',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ]);
        }
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-10', '2026-09-12');
        $this->assertSame(3, $info[(int) $product->id]['available']);
    }

    public function testPendingLocksStockOnlyWhenSettingTrue(): void
    {
        $product = $this->seedProduct([], 3);
        $this->seedOrder([
            'status' => 'pending',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-12',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-10', '2026-09-12');
        $this->assertSame(2, $info[(int) $product->id]['available']);

        $this->setSetting('booking.pending_locks_stock', false);
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-10', '2026-09-12');
        $this->assertSame(3, $info[(int) $product->id]['available']);
    }

    public function testBottleneckDayGovernsNotTheSum(): void
    {
        $product = $this->seedProduct([], 5);
        // Two non-overlapping orders inside the requested range.
        $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-10',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-12',
            'return_date' => '2026-09-12',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ]);
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-09', '2026-09-13');
        // max reserved = 3 (on the 12th), NOT 5.
        $this->assertSame(2, $info[(int) $product->id]['available']);
        $this->assertSame('2026-09-12', $info[(int) $product->id]['bottleneck_date']);
    }

    public function testInclusiveBoundaries(): void
    {
        $product = $this->seedProduct([], 2);
        // Order ends exactly on the requested start date => overlaps.
        $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-08',
            'return_date' => '2026-09-10',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-10', '2026-09-12');
        $this->assertSame(1, $info[(int) $product->id]['available']);
        // The day after the return date is free again.
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-11', '2026-09-12');
        $this->assertSame(2, $info[(int) $product->id]['available']);
    }

    public function testBufferDaysExtendTheBlock(): void
    {
        $this->setSetting('booking.buffer_days_between_loans', 2);
        $product = $this->seedProduct([], 2);
        $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-08',
            'return_date' => '2026-09-10',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        // Sep 11 and 12 are still blocked by the buffer.
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-12', '2026-09-12');
        $this->assertSame(1, $info[(int) $product->id]['available']);
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-13', '2026-09-13');
        $this->assertSame(2, $info[(int) $product->id]['available']);
    }

    public function testExcludeOrderIdRemovesOwnReservation(): void
    {
        $product = $this->seedProduct([], 2);
        $order = $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-10',
            'return_date' => '2026-09-12',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-10', '2026-09-12');
        $this->assertSame(0, $info[(int) $product->id]['available']);
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-10', '2026-09-12', (int) $order->id);
        $this->assertSame(2, $info[(int) $product->id]['available']);
    }

    public function testAvailabilityNeverNegative(): void
    {
        $product = $this->seedProduct([], 1);
        foreach ([1, 2] as $q) {
            $this->seedOrder([
                'status' => 'approved',
                'pickup_date' => '2026-09-10',
                'return_date' => '2026-09-12',
                'items' => [['product_id' => $product->id, 'quantity' => $q]],
            ]);
        }
        $info = $this->service()->availableForRange([(int) $product->id], '2026-09-10', '2026-09-12');
        $this->assertSame(0, $info[(int) $product->id]['available']);
    }

    public function testDatesReportFirstAvailableWindowNullWhenNothingFits(): void
    {
        $product = $this->seedProduct(['status' => 'maintenance'], 2);
        $report = $this->service()->datesReport(
            [['product_id' => (int) $product->id, 'quantity' => 1]],
            '2026-09-07',
            '2026-09-18',
            2
        );
        $this->assertNull($report['first_available_window']);
        $this->assertSame([['product_id' => (int) $product->id, 'reason' => 'no_capacity']], $report['unavailable_products']);
        foreach ($report['windows'] as $window) {
            $this->assertFalse($window['all_available']);
            $this->assertContains((int) $product->id, $window['blocking_product_ids']);
        }
    }

    public function testDatesReportQueryBudget(): void
    {
        $products = [];
        for ($i = 0; $i < 20; $i++) {
            $category = $i === 0 ? $this->seedCategory() : null;
            static $catId = null;
            if ($category !== null) {
                $catId = (int) $category->id;
            }
            $products[] = $this->seedProduct(['category_id' => $catId], 2);
        }
        $items = array_map(static fn ($p) => ['product_id' => (int) $p->id, 'quantity' => 1], $products);

        // Warm the per-request caches (settings + closures), as in a real request.
        \App\Domain\Settings\SettingsRepository::instance()->all();
        $service = $this->service();
        $calendar = $this->container()->get(\App\Domain\Calendar\CalendarService::class);
        $calendar->closureOn('2026-09-01');

        $connection = Capsule::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $service->datesReport($items, '2026-09-02', '2027-02-28', 3); // ~180 days
        $count = count($connection->getQueryLog());
        $connection->disableQueryLog();
        $this->assertLessThanOrEqual(4, $count, "datesReport issued {$count} SQL queries (max 4)");
    }
}
