<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

final class StatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
    }

    private function seedStatsFixture(): array
    {
        $category = $this->seedCategory();
        $product = $this->seedProduct(['category_id' => $category->id], 4);
        $student = User::where('ldap_uid', 'student1')->first();
        // Two returned orders, one returned_late, one rejected, one overdue (all submitted in range).
        $this->seedOrder(['status' => 'returned', 'pickup_date' => '2026-08-03', 'return_date' => '2026-08-05',
            'submitted_at' => '2026-08-01 10:00:00', 'items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        $this->seedOrder(['status' => 'returned', 'pickup_date' => '2026-08-10', 'return_date' => '2026-08-12',
            'submitted_at' => '2026-08-08 10:00:00', 'items' => [['product_id' => $product->id, 'quantity' => 2]]]);
        $this->seedOrder(['status' => 'returned_late', 'pickup_date' => '2026-08-17', 'return_date' => '2026-08-18',
            'submitted_at' => '2026-08-15 10:00:00', 'late_days' => 4, 'returned_at' => '2026-08-22 10:00:00',
            'items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        $this->seedOrder(['status' => 'rejected', 'pickup_date' => '2026-08-20', 'return_date' => '2026-08-21',
            'submitted_at' => '2026-08-18 10:00:00', 'items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        $this->seedOrder(['status' => 'overdue', 'pickup_date' => '2026-08-24', 'return_date' => '2026-08-28',
            'submitted_at' => '2026-08-22 10:00:00', 'items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        return ['category' => $category, 'product' => $product, 'student' => $student];
    }

    public function testOverviewFullVsLimited(): void
    {
        $this->seedStatsFixture();
        $this->actingAs('technician');
        [$status, $payload] = $this->json('GET', '/api/v1/stats/overview');
        $this->assertSame(200, $status);
        $this->assertSame('full', $payload['scope']);
        $this->assertArrayHasKey('operational', $payload);
        $this->assertArrayHasKey('totals', $payload);
        $this->assertArrayHasKey('inventory', $payload);
        $this->assertSame(5, $payload['totals']['orders_total']);
        $this->assertSame(1, $payload['totals']['orders_rejected']);
        $this->assertSame(1, $payload['operational']['orders_overdue']);
        $this->assertIsFloat($payload['totals']['approval_rate'] + 0.0);

        $this->actingAs('assistant');
        [$status, $payload] = $this->json('GET', '/api/v1/stats/overview');
        $this->assertSame(200, $status);
        $this->assertSame('limited', $payload['scope']);
        $this->assertArrayHasKey('operational', $payload);
        $this->assertArrayNotHasKey('totals', $payload);
        $this->assertArrayNotHasKey('inventory', $payload);
    }

    public function testLoansOverTimeDenseBuckets(): void
    {
        $this->seedStatsFixture();
        $this->actingAs('technician');
        [$status, $payload] = $this->json('GET', '/api/v1/stats/loans-over-time?from=2026-08-01&to=2026-08-31&granularity=week');
        $this->assertSame(200, $status);
        $this->assertSame('week', $payload['granularity']);
        // Dense series: every week from W31 to W36 present.
        $keys = array_map(static fn ($s) => $s['bucket'], $payload['series']);
        $this->assertContains('2026-W32', $keys);
        $this->assertGreaterThanOrEqual(5, count($keys));
        foreach ($payload['series'] as $bucket) {
            $this->assertMatchesRegularExpression('/^\d{4}-W\d{2}$/', $bucket['bucket']);
            $this->assertArrayHasKey('submitted', $bucket);
        }
        $this->assertSame(5, $payload['totals']['submitted']);
        // Day buckets.
        [, $payload] = $this->json('GET', '/api/v1/stats/loans-over-time?from=2026-08-01&to=2026-08-03&granularity=day');
        $this->assertCount(3, $payload['series']);
        $this->assertSame('2026-08-01', $payload['series'][0]['bucket']);
        $this->assertSame(1, $payload['series'][0]['submitted']);
        $this->assertSame(0, $payload['series'][1]['submitted']); // zero-activity bucket emitted
        // Month buckets.
        [, $payload] = $this->json('GET', '/api/v1/stats/loans-over-time?from=2026-07-01&to=2026-08-31&granularity=month');
        $keys = array_map(static fn ($s) => $s['bucket'], $payload['series']);
        $this->assertSame(['2026-07', '2026-08'], $keys);
    }

    public function testTopProductsRespectsLimitAndMetric(): void
    {
        $fixture = $this->seedStatsFixture();
        $other = $this->seedProduct(['category_id' => $fixture['category']->id], 2);
        $this->seedOrder(['status' => 'returned', 'pickup_date' => '2026-08-03', 'return_date' => '2026-08-04',
            'submitted_at' => '2026-08-02 10:00:00', 'items' => [['product_id' => $other->id, 'quantity' => 1]]]);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('GET', '/api/v1/stats/top-products?from=2026-08-01&to=2026-08-31&limit=1&metric=orders');
        $this->assertSame(200, $status);
        $this->assertCount(1, $payload['data']);
        $top = $payload['data'][0];
        $this->assertSame($fixture['product']->id, $top['product_id']);
        // 4 non-cancelled/rejected orders x qty -> orders_count 4, quantity_total 5.
        $this->assertSame(4, $top['orders_count']);
        $this->assertSame(5, $top['quantity_total']);
        $this->assertSame(4, $top['units_total']);
        // utilization = loan_days_total / (units_total * days_in_range), 3 decimals.
        $expectedUtil = round($top['loan_days_total'] / (4 * 31), 3);
        $this->assertSame($expectedUtil, $top['utilization']);
    }

    public function testByCategoryShareSumsToOne(): void
    {
        $fixture = $this->seedStatsFixture();
        $categoryB = $this->seedCategory();
        $productB = $this->seedProduct(['category_id' => $categoryB->id], 2);
        $this->seedOrder(['status' => 'returned', 'pickup_date' => '2026-08-03', 'return_date' => '2026-08-04',
            'submitted_at' => '2026-08-02 10:00:00', 'items' => [['product_id' => $productB->id, 'quantity' => 1]]]);
        $this->actingAs('admin');
        [$status, $payload] = $this->json('GET', '/api/v1/stats/by-category?from=2026-08-01&to=2026-08-31');
        $this->assertSame(200, $status);
        $shareSum = array_sum(array_map(static fn ($c) => $c['share'], $payload['data']));
        $this->assertEqualsWithDelta(1.0, $shareSum, 0.005);
        $this->assertGreaterThan(0, $payload['totals']['orders_count']);
    }

    public function testByCategoryEmptyDataReturnsZeros(): void
    {
        $this->seedCategory();
        $this->actingAs('technician');
        [$status, $payload] = $this->json('GET', '/api/v1/stats/by-category');
        $this->assertSame(200, $status);
        $this->assertSame(0, $payload['totals']['orders_count']);
        foreach ($payload['data'] as $row) {
            $this->assertSame(0, $row['orders_count']);
            $this->assertSame(0.0, $row['share'] + 0.0);
        }
    }

    public function testLateReturnsCountsReturnedLateAndOverdue(): void
    {
        $this->seedStatsFixture();
        $this->actingAs('assistant');
        [$status, $payload] = $this->json('GET', '/api/v1/stats/late-returns?from=2026-08-01&to=2026-08-31');
        $this->assertSame(200, $status);
        $this->assertSame(2, $payload['summary']['late_orders']);
        $this->assertSame(1, $payload['summary']['currently_overdue']);
        // overdue late_days = today - return_date = 4 (Aug 28 -> Sep 1).
        $lateDays = array_map(static fn ($e) => $e['late_days'], $payload['data']);
        rsort($lateDays);
        $this->assertSame([4, 4], $lateDays);
        $this->assertSame(4.0, $payload['summary']['avg_late_days'] + 0.0);
        // include_open=false drops the overdue one.
        [, $payload] = $this->json('GET', '/api/v1/stats/late-returns?from=2026-08-01&to=2026-08-31&include_open=false');
        $this->assertSame(1, $payload['summary']['late_orders']);
        $this->assertSame(0, $payload['summary']['currently_overdue']);
    }

    public function testMyActivityOnlyReflectsCaller(): void
    {
        $this->setSetting('booking.max_orders_per_month', null);
        $this->setSetting('booking.max_active_orders', null);
        $order1 = $this->seedOrder(['status' => 'pending', 'pickup_date' => '2026-09-10', 'return_date' => '2026-09-11']);
        $order2 = $this->seedOrder(['status' => 'pending', 'pickup_date' => '2026-09-14', 'return_date' => '2026-09-15']);
        $this->actingAs('technician');
        $this->json('POST', "/api/v1/orders/{$order1->id}/approve", []);
        $this->actingAs('assistant');
        $this->json('POST', "/api/v1/orders/{$order2->id}/reject", ['reason' => 'Non disponibile.']);

        $this->actingAs('technician');
        [$status, $payload] = $this->json('GET', '/api/v1/stats/my-activity');
        $this->assertSame(200, $status);
        $this->assertSame(1, $payload['counts']['approved']);
        $this->assertSame(0, $payload['counts']['rejected']); // the rejection was borsista1's
        $this->assertNotEmpty($payload['recent_events']);
        $this->assertSame('approve', $payload['recent_events'][0]['action']);
        $this->assertSame($order1->id, $payload['recent_events'][0]['order']['id']);

        $this->actingAs('assistant');
        [, $payload] = $this->json('GET', '/api/v1/stats/my-activity');
        $this->assertSame(1, $payload['counts']['rejected']);
        $this->assertSame(0, $payload['counts']['approved']);
        $this->assertSame(0, $payload['counts']['products_created']);
    }

    public function testAssistantForbiddenOnFullStats(): void
    {
        $this->actingAs('assistant');
        foreach (['loans-over-time', 'top-products', 'by-category', 'utilization', 'export?dataset=orders'] as $endpoint) {
            [$status, $payload] = $this->json('GET', '/api/v1/stats/' . $endpoint);
            $this->assertSame(403, $status, $endpoint);
            $this->assertErrorEnvelope($payload);
        }
    }

    public function testExportOrdersCsvFormat(): void
    {
        $this->seedStatsFixture();
        $this->actingAs('technician');
        [$status, , $response] = $this->json('GET', '/api/v1/stats/export?dataset=orders&from=2026-08-01&to=2026-08-31');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="vlab-orders-2026-08-01_2026-08-31.csv"', $response->getHeaderLine('Content-Disposition'));
        $csv = (string) $response->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("\r\n", $csv);
        $firstLine = explode("\r\n", substr($csv, 3))[0];
        $this->assertSame(
            '"code","status","student_uid","student_name","subject","professor","pickup_date","pickup_time","return_date","return_time","picked_up_at","returned_at","late_days","items_count","exceeds_limits","decided_by","submitted_at"',
            $firstLine
        );
        // 5 orders + header.
        $lines = array_filter(explode("\r\n", trim(substr($csv, 3))));
        $this->assertCount(6, $lines);
    }

    public function testUtilizationSeries(): void
    {
        $this->seedStatsFixture();
        $this->actingAs('technician');
        [$status, $payload] = $this->json('GET', '/api/v1/stats/utilization?from=2026-08-01&to=2026-08-31&granularity=week');
        $this->assertSame(200, $status);
        $this->assertNotEmpty($payload['series']);
        $this->assertArrayHasKey('units_on_loan_avg', $payload['series'][0]);
        $this->assertArrayHasKey('utilization', $payload['series'][0]);
        $this->assertNotNull($payload['peak']);
    }
}
