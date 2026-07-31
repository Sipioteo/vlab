<?php

declare(strict_types=1);

namespace App\Domain\Stats;

use App\Domain\Calendar\CalendarService;
use App\Domain\Settings\SettingsRepository;
use App\Models\Order;
use App\Models\User;
use App\Support\Dates;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Statistics endpoints (SPEC §7.12). All bucketing happens in PHP — no
 * DB-specific date functions (SPEC §1.3).
 */
class StatsService
{
    private const APPROVAL_SET = ['approved', 'picked_up', 'overdue', 'returned', 'returned_late'];

    public function __construct(
        private SettingsRepository $settings,
        private CalendarService $calendar,
    ) {
    }

    /** @return array{0:string,1:string} [from, to] resolved range */
    public function resolveRange(?string $from, ?string $to): array
    {
        $today = $this->calendar->today();
        $defaultDays = (int) ($this->settings->get('stats.default_range_days', 90) ?? 90);
        $to = ($to !== null && Dates::isValidDate($to)) ? $to : $today;
        $from = ($from !== null && Dates::isValidDate($from)) ? $from : Dates::addDays($to, -$defaultDays);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (Dates::inclusiveDays($from, $to) > 1830) {
            $from = Dates::addDays($to, -1829);
        }
        return [$from, $to];
    }

    private static function round3(float $value): float
    {
        return round($value, 3);
    }

    private static function ratio(float $num, float $den): float
    {
        return $den == 0.0 ? 0.0 : self::round3($num / $den);
    }

    /** Orders submitted in range, non-draft. @return array<int,object> */
    private function ordersInRange(string $from, string $to): array
    {
        $rows = Capsule::table('orders')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'draft')
            ->whereNotNull('submitted_at')
            ->get()->all();
        return array_values(array_filter($rows, static function ($o) use ($from, $to) {
            $d = substr((string) $o->submitted_at, 0, 10);
            return $d >= $from && $d <= $to;
        }));
    }

    /** @return array<string,mixed> */
    public function overview(string $from, string $to, bool $limited): array
    {
        $today = $this->calendar->today();

        $statusCounts = [];
        foreach (Capsule::table('orders')->whereNull('deleted_at')->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->get() as $row) {
            $statusCounts[$row->status] = (int) $row->cnt;
        }

        $pickupsToday = (int) Capsule::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('status', ['approved', 'picked_up'])
            ->where('pickup_date', $today)->count();
        $returnsToday = (int) Capsule::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('status', ['picked_up', 'overdue'])
            ->where('return_date', $today)->count();
        $returnsNext7 = (int) Capsule::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('status', ['approved', 'picked_up', 'overdue'])
            ->where('return_date', '>', $today)
            ->where('return_date', '<=', Dates::addDays($today, 7))
            ->count();

        $out = [
            'scope' => $limited ? 'limited' : 'full',
            'range' => ['from' => $from, 'to' => $to],
            'operational' => [
                'orders_pending' => $statusCounts['pending'] ?? 0,
                'orders_approved' => $statusCounts['approved'] ?? 0,
                'orders_picked_up' => $statusCounts['picked_up'] ?? 0,
                'orders_overdue' => $statusCounts['overdue'] ?? 0,
                'pickups_today' => $pickupsToday,
                'returns_today' => $returnsToday,
                'returns_next_7_days' => $returnsNext7,
            ],
        ];
        if ($limited) {
            return $out;
        }

        $orders = $this->ordersInRange($from, $to);
        $count = static fn (array $statuses): int => count(array_filter($orders, static fn ($o) => in_array($o->status, $statuses, true)));
        $ordersTotal = count($orders);
        $approvedCount = $count(self::APPROVAL_SET);
        $notCancelled = count(array_filter($orders, static fn ($o) => $o->status !== 'cancelled'));

        $orderIds = array_map(static fn ($o) => (int) $o->id, array_filter($orders, static fn ($o) => in_array($o->status, self::APPROVAL_SET, true)));
        $itemsLoaned = 0;
        if ($orderIds !== []) {
            $itemsLoaned = (int) Capsule::table('order_items')->whereIn('order_id', $orderIds)->sum('quantity');
        }
        $uniqueStudents = count(array_unique(array_map(static fn ($o) => (int) $o->user_id, $orders)));

        $durations = [];
        $approvalHours = [];
        foreach ($orders as $o) {
            if (in_array($o->status, self::APPROVAL_SET, true) && $o->pickup_date !== null && $o->return_date !== null) {
                $durations[] = Dates::inclusiveDays(substr((string) $o->pickup_date, 0, 10), substr((string) $o->return_date, 0, 10));
            }
            if ($o->decided_at !== null && $o->submitted_at !== null) {
                $approvalHours[] = (strtotime((string) $o->decided_at) - strtotime((string) $o->submitted_at)) / 3600;
            }
        }

        $unitCounts = [];
        foreach (Capsule::table('product_units')->whereNull('deleted_at')->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->get() as $row) {
            $unitCounts[$row->status] = (int) $row->cnt;
        }
        $unitsTotal = array_sum($unitCounts);
        $unitsAvailable = $unitCounts['available'] ?? 0;
        $unitsOnLoan = (int) Capsule::table('order_item_units')
            ->join('order_items', 'order_items.id', '=', 'order_item_units.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('order_item_units.returned_at')
            ->whereIn('orders.status', ['picked_up', 'overdue'])
            ->count();

        $out['totals'] = [
            'orders_total' => $ordersTotal,
            'orders_approved' => $approvedCount,
            'orders_rejected' => $count(['rejected']),
            'orders_cancelled' => $count(['cancelled']),
            'orders_no_show' => $count(['no_show']),
            'orders_returned_late' => $count(['returned_late']),
            'approval_rate' => self::ratio((float) $approvedCount, (float) $notCancelled),
            'late_rate' => self::ratio((float) $count(['returned_late', 'overdue']), (float) max(1, $approvedCount)),
            'items_loaned' => $itemsLoaned,
            'unique_students' => $uniqueStudents,
            'avg_loan_days' => $durations !== [] ? round(array_sum($durations) / count($durations), 1) : 0,
            'avg_approval_hours' => $approvalHours !== [] ? round(array_sum($approvalHours) / count($approvalHours), 1) : 0,
        ];
        $out['inventory'] = [
            'products_total' => (int) Capsule::table('products')->whereNull('deleted_at')->count(),
            'units_total' => $unitsTotal,
            'units_available' => $unitsAvailable,
            'units_maintenance' => $unitCounts['maintenance'] ?? 0,
            'units_missing' => $unitCounts['missing'] ?? 0,
            'units_retired' => $unitCounts['retired'] ?? 0,
            'units_on_loan_now' => $unitsOnLoan,
            'utilization_now' => self::ratio((float) $unitsOnLoan, (float) $unitsAvailable),
        ];
        return $out;
    }

    // ------------------------------------------------------------- bucketing

    /** @return array{key:string, start:string, end:string} */
    public function bucketFor(string $date, string $granularity): array
    {
        $dt = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
        if ($granularity === 'day') {
            return ['key' => $date, 'start' => $date, 'end' => $date];
        }
        if ($granularity === 'week') {
            $monday = $dt->modify('monday this week');
            return [
                'key' => $monday->format('o') . '-W' . $monday->format('W'),
                'start' => $monday->format('Y-m-d'),
                'end' => $monday->modify('+6 days')->format('Y-m-d'),
            ];
        }
        $first = $dt->modify('first day of this month');
        return [
            'key' => $first->format('Y-m'),
            'start' => $first->format('Y-m-d'),
            'end' => $first->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    /** Dense list of buckets covering [from, to]. @return array<string,array{key:string,start:string,end:string}> */
    public function denseBuckets(string $from, string $to, string $granularity): array
    {
        $out = [];
        $d = $from;
        while ($d <= $to) {
            $bucket = $this->bucketFor($d, $granularity);
            $out[$bucket['key']] = $bucket;
            $d = Dates::addDays($bucket['end'], 1);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function loansOverTime(string $from, string $to, string $granularity, ?int $categoryId, string $metric): array
    {
        $orders = $this->ordersInRange($from, $to);
        $orderIds = array_map(static fn ($o) => (int) $o->id, $orders);
        $itemRows = $orderIds === [] ? [] : Capsule::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_items.order_id', $orderIds)
            ->get(['order_items.order_id', 'order_items.quantity', 'products.category_id'])->all();
        $itemsByOrder = [];
        foreach ($itemRows as $row) {
            $itemsByOrder[(int) $row->order_id][] = $row;
        }
        if ($categoryId !== null) {
            $orders = array_values(array_filter($orders, static function ($o) use ($itemsByOrder, $categoryId) {
                foreach ($itemsByOrder[(int) $o->id] ?? [] as $row) {
                    if ((int) $row->category_id === $categoryId) {
                        return true;
                    }
                }
                return false;
            }));
        }

        $buckets = $this->denseBuckets($from, $to, $granularity);
        $series = [];
        foreach ($buckets as $key => $b) {
            $series[$key] = [
                'bucket' => $key,
                'bucket_start' => $b['start'],
                'bucket_end' => $b['end'],
                'submitted' => 0, 'approved' => 0, 'rejected' => 0,
                'cancelled' => 0, 'returned' => 0, 'returned_late' => 0,
            ];
        }
        $totals = ['submitted' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0, 'returned' => 0, 'returned_late' => 0];
        foreach ($orders as $o) {
            $day = substr((string) $o->submitted_at, 0, 10);
            $key = $this->bucketFor($day, $granularity)['key'];
            if (!isset($series[$key])) {
                continue;
            }
            $weight = 1;
            if ($metric === 'items') {
                $weight = 0;
                foreach ($itemsByOrder[(int) $o->id] ?? [] as $row) {
                    $weight += (int) $row->quantity;
                }
            }
            $series[$key]['submitted'] += $weight;
            $totals['submitted'] += $weight;
            $map = [
                'rejected' => 'rejected',
                'cancelled' => 'cancelled',
                'returned' => 'returned',
                'returned_late' => 'returned_late',
            ];
            if (in_array($o->status, self::APPROVAL_SET, true)) {
                $series[$key]['approved'] += $weight;
                $totals['approved'] += $weight;
            }
            if (isset($map[$o->status])) {
                $series[$key][$map[$o->status]] += $weight;
                $totals[$map[$o->status]] += $weight;
            }
        }
        return [
            'granularity' => $granularity,
            'metric' => $metric,
            'series' => array_values($series),
            'totals' => $totals,
        ];
    }

    /** @return array<string,mixed> */
    public function topProducts(string $from, string $to, int $limit, ?int $categoryId, string $metric): array
    {
        $orders = $this->ordersInRange($from, $to);
        $orders = array_values(array_filter($orders, static fn ($o) => $o->status !== 'cancelled' && $o->status !== 'rejected'));
        $byId = [];
        foreach ($orders as $o) {
            $byId[(int) $o->id] = $o;
        }
        $rows = $byId === [] ? [] : Capsule::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('order_items.order_id', array_keys($byId))
            ->get([
                'order_items.order_id', 'order_items.product_id', 'order_items.quantity',
                'products.name', 'products.slug', 'products.brand', 'products.image_url',
                'products.category_id', 'categories.name as category_name',
            ])->all();

        $agg = [];
        foreach ($rows as $row) {
            if ($categoryId !== null && (int) $row->category_id !== $categoryId) {
                continue;
            }
            $pid = (int) $row->product_id;
            if (!isset($agg[$pid])) {
                $agg[$pid] = [
                    'product_id' => $pid,
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'brand' => $row->brand,
                    'category' => $row->category_id !== null ? ['id' => (int) $row->category_id, 'name' => $row->category_name] : null,
                    'image_url' => $row->image_url,
                    'orders_count' => 0,
                    'quantity_total' => 0,
                    'loan_days_total' => 0,
                ];
            }
            $order = $byId[(int) $row->order_id];
            $agg[$pid]['orders_count']++;
            $agg[$pid]['quantity_total'] += (int) $row->quantity;
            if ($order->pickup_date !== null && $order->return_date !== null) {
                $days = Dates::inclusiveDays(substr((string) $order->pickup_date, 0, 10), substr((string) $order->return_date, 0, 10));
                $agg[$pid]['loan_days_total'] += $days * (int) $row->quantity;
            }
        }

        $unitTotals = [];
        if ($agg !== []) {
            foreach (Capsule::table('product_units')->selectRaw('product_id, COUNT(*) as cnt')->whereIn('product_id', array_keys($agg))->whereNull('deleted_at')->groupBy('product_id')->get() as $row) {
                $unitTotals[(int) $row->product_id] = (int) $row->cnt;
            }
        }
        $daysInRange = Dates::inclusiveDays($from, $to);
        foreach ($agg as $pid => &$entry) {
            $entry['units_total'] = $unitTotals[$pid] ?? 0;
            $entry['utilization'] = self::ratio((float) $entry['loan_days_total'], (float) ($entry['units_total'] * $daysInRange));
        }
        unset($entry);

        $sortKey = ['orders' => 'orders_count', 'quantity' => 'quantity_total', 'days' => 'loan_days_total'][$metric] ?? 'orders_count';
        usort($agg, static fn ($a, $b) => $b[$sortKey] <=> $a[$sortKey]);
        return ['metric' => $metric, 'data' => array_slice(array_values($agg), 0, $limit)];
    }

    /** @return array<string,mixed> */
    public function byCategory(string $from, string $to): array
    {
        $orders = $this->ordersInRange($from, $to);
        $orders = array_values(array_filter($orders, static fn ($o) => $o->status !== 'cancelled' && $o->status !== 'rejected'));
        $byId = [];
        foreach ($orders as $o) {
            $byId[(int) $o->id] = $o;
        }
        $rows = $byId === [] ? [] : Capsule::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_items.order_id', array_keys($byId))
            ->get(['order_items.order_id', 'order_items.quantity', 'products.category_id'])->all();

        $categories = Capsule::table('categories')->whereNull('deleted_at')->orderBy('position')->get();
        $agg = [];
        foreach ($categories as $cat) {
            $agg[(int) $cat->id] = [
                'category_id' => (int) $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'orders_count' => 0,
                'quantity_total' => 0,
                'loan_days_total' => 0,
            ];
        }
        $ordersPerCategory = [];
        foreach ($rows as $row) {
            $cid = (int) $row->category_id;
            if (!isset($agg[$cid])) {
                continue;
            }
            $ordersPerCategory[$cid][(int) $row->order_id] = true;
            $agg[$cid]['quantity_total'] += (int) $row->quantity;
            $order = $byId[(int) $row->order_id];
            if ($order->pickup_date !== null && $order->return_date !== null) {
                $days = Dates::inclusiveDays(substr((string) $order->pickup_date, 0, 10), substr((string) $order->return_date, 0, 10));
                $agg[$cid]['loan_days_total'] += $days * (int) $row->quantity;
            }
        }
        $totalOrders = 0;
        foreach ($agg as $cid => &$entry) {
            $entry['orders_count'] = count($ordersPerCategory[$cid] ?? []);
            $totalOrders += $entry['orders_count'];
        }
        unset($entry);

        $productCounts = [];
        foreach (Capsule::table('products')->selectRaw('category_id, COUNT(*) as cnt')->whereNull('deleted_at')->where('status', '!=', 'retired')->groupBy('category_id')->get() as $row) {
            $productCounts[(int) $row->category_id] = (int) $row->cnt;
        }
        $unitCounts = [];
        foreach (Capsule::table('product_units')
            ->join('products', 'products.id', '=', 'product_units.product_id')
            ->selectRaw('products.category_id as cid, COUNT(*) as cnt')
            ->whereNull('product_units.deleted_at')
            ->groupBy('products.category_id')->get() as $row) {
            $unitCounts[(int) $row->cid] = (int) $row->cnt;
        }
        $daysInRange = Dates::inclusiveDays($from, $to);
        $totQty = 0;
        $totDays = 0;
        foreach ($agg as $cid => &$entry) {
            $entry['products_count'] = $productCounts[$cid] ?? 0;
            $entry['units_total'] = $unitCounts[$cid] ?? 0;
            $entry['share'] = self::ratio((float) $entry['orders_count'], (float) $totalOrders);
            $entry['utilization'] = self::ratio((float) $entry['loan_days_total'], (float) ($entry['units_total'] * $daysInRange));
            $totQty += $entry['quantity_total'];
            $totDays += $entry['loan_days_total'];
        }
        unset($entry);
        return [
            'data' => array_values($agg),
            'totals' => ['orders_count' => $totalOrders, 'quantity_total' => $totQty, 'loan_days_total' => $totDays],
        ];
    }

    /**
     * @return array{0: array<int,array<string,mixed>>, 1: array<string,mixed>} [entries, summary]
     */
    public function lateReturns(string $from, string $to, int $minDays, bool $includeOpen): array
    {
        $today = $this->calendar->today();
        $rows = Capsule::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', ['returned_late', 'overdue'])
            ->get([
                'orders.id', 'orders.code', 'orders.status', 'orders.return_date', 'orders.returned_at',
                'orders.late_days', 'orders.items_count', 'orders.submitted_at', 'orders.user_id',
                'users.display_name', 'users.ldap_uid',
            ])->all();
        $entries = [];
        $currentlyOverdue = 0;
        foreach ($rows as $row) {
            $submitted = $row->submitted_at !== null ? substr((string) $row->submitted_at, 0, 10) : null;
            if ($submitted === null || $submitted < $from || $submitted > $to) {
                // Overdue "now" orders count regardless of range when include_open.
                if (!($row->status === 'overdue' && $includeOpen)) {
                    continue;
                }
            }
            if ($row->status === 'overdue') {
                if (!$includeOpen) {
                    continue;
                }
                $lateDays = max(0, Dates::diffDays(substr((string) $row->return_date, 0, 10), $today));
                $currentlyOverdue++;
            } else {
                $lateDays = (int) $row->late_days;
            }
            if ($lateDays < $minDays) {
                continue;
            }
            $entries[] = [
                'order_id' => (int) $row->id,
                'code' => $row->code,
                'status' => $row->status,
                'user' => [
                    'id' => (int) $row->user_id,
                    'display_name' => $row->display_name,
                    'ldap_uid' => $row->ldap_uid,
                ],
                'return_date' => $row->return_date !== null ? substr((string) $row->return_date, 0, 10) : null,
                'returned_at' => Dates::iso($row->returned_at),
                'late_days' => $lateDays,
                'items_count' => (int) $row->items_count,
            ];
        }
        usort($entries, static fn ($a, $b) => $b['late_days'] <=> $a['late_days']);
        $lateDaysTotal = array_sum(array_map(static fn ($e) => $e['late_days'], $entries));
        $students = count(array_unique(array_map(static fn ($e) => $e['user']['id'], $entries)));
        $summary = [
            'late_orders' => count($entries),
            'late_days_total' => $lateDaysTotal,
            'avg_late_days' => count($entries) > 0 ? round($lateDaysTotal / count($entries), 1) : 0,
            'students_involved' => $students,
            'currently_overdue' => $currentlyOverdue,
        ];
        return [$entries, $summary];
    }

    /** @return array<string,mixed> */
    public function utilization(string $from, string $to, string $granularity, ?int $categoryId, ?int $productId): array
    {
        $query = Capsule::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', ['picked_up', 'overdue', 'returned', 'returned_late'])
            ->whereNotNull('orders.pickup_date')
            ->whereNotNull('orders.return_date')
            ->where('orders.pickup_date', '<=', $to)
            ->where('orders.return_date', '>=', $from);
        if ($categoryId !== null) {
            $query->where('products.category_id', $categoryId);
        }
        if ($productId !== null) {
            $query->where('order_items.product_id', $productId);
        }
        $rows = $query->get(['order_items.quantity', 'orders.pickup_date', 'orders.return_date'])->all();

        $perDay = [];
        foreach (Dates::range($from, $to) as $d) {
            $perDay[$d] = 0;
        }
        foreach ($rows as $row) {
            $start = max(substr((string) $row->pickup_date, 0, 10), $from);
            $end = min(substr((string) $row->return_date, 0, 10), $to);
            for ($d = $start; $d <= $end; $d = Dates::addDays($d, 1)) {
                $perDay[$d] += (int) $row->quantity;
            }
        }

        $unitsQuery = Capsule::table('product_units')
            ->join('products', 'products.id', '=', 'product_units.product_id')
            ->whereNull('product_units.deleted_at')
            ->where('product_units.status', 'available');
        if ($categoryId !== null) {
            $unitsQuery->where('products.category_id', $categoryId);
        }
        if ($productId !== null) {
            $unitsQuery->where('product_units.product_id', $productId);
        }
        $unitsAvailable = (int) $unitsQuery->count();

        $buckets = $this->denseBuckets($from, $to, $granularity);
        $series = [];
        $peak = null;
        foreach ($buckets as $key => $b) {
            $days = Dates::range(max($b['start'], $from), min($b['end'], $to));
            $sum = 0;
            foreach ($days as $d) {
                $sum += $perDay[$d] ?? 0;
            }
            $avg = count($days) > 0 ? round($sum / count($days), 1) : 0.0;
            $util = self::ratio((float) $avg, (float) $unitsAvailable);
            $series[] = [
                'bucket' => $key,
                'bucket_start' => $b['start'],
                'units_on_loan_avg' => $avg,
                'units_available' => $unitsAvailable,
                'utilization' => $util,
            ];
            if ($peak === null || $util > $peak['utilization']) {
                $peak = ['bucket' => $key, 'utilization' => $util];
            }
        }
        return ['granularity' => $granularity, 'series' => $series, 'peak' => $peak];
    }

    /** @return array<string,mixed> */
    public function myActivity(User $user, string $from, string $to, string $granularity): array
    {
        $events = Capsule::table('order_events')
            ->where('actor_id', $user->id)
            ->get()->all();
        $inRange = array_values(array_filter($events, static function ($e) use ($from, $to) {
            $d = substr((string) $e->created_at, 0, 10);
            return $d >= $from && $d <= $to;
        }));
        $countAction = static fn (string $action): int => count(array_filter($inRange, static fn ($e) => $e->action === $action));

        $logsCreated = count(array_filter(
            Capsule::table('product_logs')->where('user_id', $user->id)->whereNull('deleted_at')->pluck('created_at')->all(),
            static function ($c) use ($from, $to) {
                $d = substr((string) $c, 0, 10);
                return $d >= $from && $d <= $to;
            }
        ));

        $isAssistant = $user->role === 'assistant';
        $auditCounts = ['product.create' => 0, 'product.update' => 0];
        if (!$isAssistant) {
            $auditRows = Capsule::table('audit_logs')
                ->where('user_id', $user->id)
                ->whereIn('action', ['product.create', 'product.update'])
                ->get(['action', 'created_at'])->all();
            foreach ($auditRows as $row) {
                $d = substr((string) $row->created_at, 0, 10);
                if ($d >= $from && $d <= $to) {
                    $auditCounts[$row->action]++;
                }
            }
        }

        $buckets = $this->denseBuckets($from, $to, $granularity);
        $series = [];
        foreach ($buckets as $key => $b) {
            $series[$key] = ['bucket' => $key, 'bucket_start' => $b['start'], 'actions' => 0];
        }
        foreach ($inRange as $e) {
            $key = $this->bucketFor(substr((string) $e->created_at, 0, 10), $granularity)['key'];
            if (isset($series[$key])) {
                $series[$key]['actions']++;
            }
        }

        return [
            'user_id' => (int) $user->id,
            'range' => ['from' => $from, 'to' => $to],
            'counts' => [
                'approved' => $countAction('approve'),
                'rejected' => $countAction('reject'),
                'pickups' => $countAction('pickup'),
                'returns' => $countAction('return'),
                'logs_created' => $logsCreated,
                'notes_added' => $countAction('note'),
                'products_created' => $auditCounts['product.create'],
                'products_updated' => $auditCounts['product.update'],
            ],
            'series' => array_values($series),
        ];
    }

    /** @return array{0:string,1:array<int,array<int,mixed>>} [header csv row is included in rows] */
    public function exportRows(string $dataset, string $from, string $to): array
    {
        $rows = [];
        switch ($dataset) {
            case 'orders':
                $rows[] = ['code', 'status', 'student_uid', 'student_name', 'subject', 'professor', 'pickup_date', 'pickup_time', 'return_date', 'return_time', 'picked_up_at', 'returned_at', 'late_days', 'items_count', 'exceeds_limits', 'decided_by', 'submitted_at'];
                $orders = Order::with('user')->where('status', '!=', 'draft')->whereNotNull('submitted_at')->orderBy('submitted_at')->get();
                foreach ($orders as $o) {
                    $d = substr((string) $o->submitted_at, 0, 10);
                    if ($d < $from || $d > $to) {
                        continue;
                    }
                    $decidedBy = $o->decided_by !== null ? User::find($o->decided_by)?->displayName() : null;
                    $rows[] = [
                        $o->code, $o->status, $o->user?->ldap_uid, $o->user?->displayName(),
                        $o->subject, $o->professor,
                        Dates::datePart($o->pickup_date), $o->pickup_time,
                        Dates::datePart($o->return_date), $o->return_time,
                        Dates::iso($o->picked_up_at), Dates::iso($o->returned_at),
                        $o->late_days, $o->items_count,
                        $o->exceeds_limits ? 'true' : 'false',
                        $decidedBy, Dates::iso($o->submitted_at),
                    ];
                }
                break;
            case 'products':
                $rows[] = ['id', 'slug', 'name', 'brand', 'model', 'category', 'status', 'loan_mode', 'units_total', 'units_available'];
                $products = Capsule::table('products')
                    ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                    ->whereNull('products.deleted_at')
                    ->orderBy('products.id')
                    ->get(['products.*', 'categories.name as category_name'])->all();
                $unitAgg = [];
                foreach (Capsule::table('product_units')->whereNull('deleted_at')->get(['product_id', 'status']) as $u) {
                    $unitAgg[(int) $u->product_id]['total'] = ($unitAgg[(int) $u->product_id]['total'] ?? 0) + 1;
                    if ($u->status === 'available') {
                        $unitAgg[(int) $u->product_id]['available'] = ($unitAgg[(int) $u->product_id]['available'] ?? 0) + 1;
                    }
                }
                foreach ($products as $p) {
                    $rows[] = [
                        $p->id, $p->slug, $p->name, $p->brand, $p->model, $p->category_name,
                        $p->status, $p->loan_mode,
                        $unitAgg[(int) $p->id]['total'] ?? 0,
                        $unitAgg[(int) $p->id]['available'] ?? 0,
                    ];
                }
                break;
            case 'late_returns':
                $rows[] = ['code', 'status', 'student_uid', 'student_name', 'return_date', 'returned_at', 'late_days', 'items_count'];
                [$entries] = $this->lateReturns($from, $to, 1, true);
                foreach ($entries as $e) {
                    $rows[] = [
                        $e['code'], $e['status'], $e['user']['ldap_uid'], $e['user']['display_name'],
                        $e['return_date'], $e['returned_at'], $e['late_days'], $e['items_count'],
                    ];
                }
                break;
            case 'logs':
                $rows[] = ['id', 'product', 'unit_label', 'type', 'severity', 'title', 'occurred_at', 'resolved_at', 'author', 'is_public'];
                $logs = Capsule::table('product_logs')
                    ->join('products', 'products.id', '=', 'product_logs.product_id')
                    ->leftJoin('product_units', 'product_units.id', '=', 'product_logs.product_unit_id')
                    ->leftJoin('users', 'users.id', '=', 'product_logs.user_id')
                    ->whereNull('product_logs.deleted_at')
                    ->orderBy('product_logs.occurred_at')
                    ->get([
                        'product_logs.*', 'products.name as product_name',
                        'product_units.label as unit_label', 'users.display_name as author_name',
                    ])->all();
                foreach ($logs as $log) {
                    $d = substr((string) $log->occurred_at, 0, 10);
                    if ($d < $from || $d > $to) {
                        continue;
                    }
                    $rows[] = [
                        $log->id, $log->product_name, $log->unit_label, $log->type, $log->severity,
                        $log->title, Dates::iso($log->occurred_at), Dates::iso($log->resolved_at),
                        $log->author_name, ((bool) $log->is_public) ? 'true' : 'false',
                    ];
                }
                break;
            default:
                throw \App\Support\ApiException::validation(['dataset' => ['Dataset non valido.']]);
        }
        return [$dataset, $rows];
    }
}
