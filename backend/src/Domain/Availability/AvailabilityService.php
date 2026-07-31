<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Domain\Calendar\CalendarService;
use App\Domain\Settings\SettingsRepository;
use App\Support\Dates;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Bottleneck-day availability engine (SPEC §5.2). All day arithmetic is done
 * in PHP; a single query loads the overlapping locking order items.
 */
class AvailabilityService
{
    public function __construct(
        private SettingsRepository $settings,
        private CalendarService $calendar,
    ) {
    }

    /** @return string[] statuses that lock stock right now */
    public function lockingStatuses(): array
    {
        $locking = ['approved', 'picked_up', 'overdue'];
        if ((bool) ($this->settings->get('booking.pending_locks_stock', true) ?? true)) {
            array_unshift($locking, 'pending');
        }
        return $locking;
    }

    public function bufferDays(): int
    {
        return (int) ($this->settings->get('booking.buffer_days_between_loans', 0) ?? 0);
    }

    /**
     * capacity(product) per §5.2; products with status != available => 0.
     *
     * @param int[] $productIds
     * @return array<int,int> product_id => capacity
     */
    public function capacities(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $productStatuses = Capsule::table('products')
            ->whereIn('id', $productIds)
            ->whereNull('deleted_at')
            ->pluck('status', 'id');
        $unitCounts = Capsule::table('product_units')
            ->selectRaw('product_id, COUNT(*) as cnt')
            ->whereIn('product_id', $productIds)
            ->where('status', 'available')
            ->whereNull('deleted_at')
            ->groupBy('product_id')
            ->pluck('cnt', 'product_id');
        $out = [];
        foreach ($productIds as $id) {
            $status = $productStatuses[$id] ?? null;
            $out[$id] = ($status === 'available') ? (int) ($unitCounts[$id] ?? 0) : 0;
        }
        return $out;
    }

    /**
     * reserved(P, d) for every product and day in range, computed in PHP from
     * ONE SQL query (SPEC §5.2 implementation note).
     *
     * @param int[] $productIds
     * @return array<int,array<string,int>> product_id => [date => reserved]
     */
    public function reservedPerDay(array $productIds, string $from, string $to, ?int $excludeOrderId = null): array
    {
        $out = [];
        foreach ($productIds as $id) {
            $out[$id] = [];
        }
        if ($productIds === [] || $from > $to) {
            return $out;
        }
        $buffer = $this->bufferDays();
        // Overlap: pickup <= to AND (return + B) >= from  <=>  return >= from - B
        $minReturn = Dates::addDays($from, -$buffer);
        $query = Capsule::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.product_id', $productIds)
            ->whereIn('orders.status', $this->lockingStatuses())
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.pickup_date')
            ->whereNotNull('orders.return_date')
            ->where('orders.pickup_date', '<=', $to)
            ->where('orders.return_date', '>=', $minReturn);
        if ($excludeOrderId !== null) {
            $query->where('orders.id', '!=', $excludeOrderId);
        }
        $rows = $query->get([
            'order_items.product_id',
            'order_items.quantity',
            'orders.pickup_date',
            'orders.return_date',
        ]);
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            $qty = (int) $row->quantity;
            $start = max((string) Dates::datePart($row->pickup_date), $from);
            $end = min(Dates::addDays((string) Dates::datePart($row->return_date), $buffer), $to);
            for ($d = $start; $d <= $end; $d = Dates::addDays($d, 1)) {
                $out[$pid][$d] = ($out[$pid][$d] ?? 0) + $qty;
            }
        }
        return $out;
    }

    /**
     * available(P, [s,e]) = capacity - MAX reserved over the range, clamped >= 0.
     *
     * @param int[] $productIds
     * @return array<int,array{capacity:int, available:int, max_reserved:int, bottleneck_date:?string}>
     */
    public function availableForRange(array $productIds, string $from, string $to, ?int $excludeOrderId = null): array
    {
        $capacities = $this->capacities($productIds);
        $reserved = $this->reservedPerDay($productIds, $from, $to, $excludeOrderId);
        $out = [];
        foreach ($productIds as $id) {
            $capacity = $capacities[$id] ?? 0;
            $maxReserved = 0;
            $bottleneck = null;
            foreach ($reserved[$id] ?? [] as $date => $qty) {
                if ($qty > $maxReserved) {
                    $maxReserved = $qty;
                    $bottleneck = $date;
                }
            }
            $out[$id] = [
                'capacity' => $capacity,
                'available' => max(0, $capacity - $maxReserved),
                'max_reserved' => $maxReserved,
                'bottleneck_date' => $bottleneck,
            ];
        }
        return $out;
    }

    /**
     * Per-day availability report for one product (GET /products/{id}/availability).
     *
     * @return array<string,mixed>
     */
    public function productDays(int $productId, string $from, string $to, ?int $excludeOrderId = null): array
    {
        $capacity = $this->capacities([$productId])[$productId] ?? 0;
        $reserved = $this->reservedPerDay([$productId], $from, $to, $excludeOrderId)[$productId] ?? [];
        $days = [];
        foreach (Dates::range($from, $to) as $date) {
            $r = $reserved[$date] ?? 0;
            $closure = $this->calendar->closureOn($date, 'any');
            $days[] = [
                'date' => $date,
                'available' => max(0, $capacity - $r),
                'reserved' => $r,
                'is_open' => $this->calendar->isOpen($date),
                'can_pickup' => $this->calendar->canPickup($date, false),
                'can_return' => $this->calendar->canReturn($date),
                'closure_id' => $closure !== null ? (int) $closure->id : null,
            ];
        }
        return [
            'product_id' => $productId,
            'capacity' => $capacity,
            'range' => ['from' => $from, 'to' => $to],
            'days' => $days,
        ];
    }

    /**
     * POST /api/v1/availability/dates report (SPEC §7.8 #31).
     *
     * @param array<int,array{product_id:int, quantity:int}> $items
     * @return array<string,mixed>
     */
    public function datesReport(array $items, string $from, string $to, int $durationDays, ?int $excludeOrderId = null): array
    {
        $productIds = array_values(array_unique(array_map(static fn ($i) => (int) $i['product_id'], $items)));
        $requested = [];
        foreach ($items as $item) {
            $requested[(int) $item['product_id']] = ($requested[(int) $item['product_id']] ?? 0) + (int) $item['quantity'];
        }
        $capacities = $this->capacities($productIds);
        // Reservations must cover windows that start at `to` (end at to+duration-1).
        $horizonEnd = Dates::addDays($to, max(0, $durationDays - 1));
        $reserved = $this->reservedPerDay($productIds, $from, $horizonEnd, $excludeOrderId);

        $unavailable = [];
        foreach ($productIds as $id) {
            if (($capacities[$id] ?? 0) === 0) {
                $unavailable[] = ['product_id' => $id, 'reason' => 'no_capacity'];
            }
        }

        $availOn = function (string $date) use ($productIds, $capacities, $reserved): array {
            $out = [];
            foreach ($productIds as $id) {
                $out[$id] = max(0, ($capacities[$id] ?? 0) - ($reserved[$id][$date] ?? 0));
            }
            return $out;
        };

        $days = [];
        $sufficientByDate = [];
        foreach (Dates::range($from, $horizonEnd) as $date) {
            $avail = $availOn($date);
            $perProduct = [];
            $allOk = true;
            foreach ($productIds as $id) {
                $ok = $avail[$id] >= ($requested[$id] ?? 1);
                $perProduct[] = [
                    'product_id' => $id,
                    'requested' => $requested[$id] ?? 1,
                    'available' => $avail[$id],
                    'sufficient' => $ok,
                ];
                if (!$ok) {
                    $allOk = false;
                }
            }
            $sufficientByDate[$date] = ['per_product' => $perProduct, 'all' => $allOk, 'avail' => $avail];
            if ($date <= $to) {
                $closure = $this->calendar->closureOn($date, 'any');
                $days[] = [
                    'date' => $date,
                    'all_available' => $allOk,
                    'is_open' => $this->calendar->isOpen($date),
                    'can_pickup' => $this->calendar->canPickup($date, false),
                    'can_return' => $this->calendar->canReturn($date),
                    'closure_id' => $closure !== null ? (int) $closure->id : null,
                    'per_product' => $perProduct,
                ];
            }
        }

        $windows = [];
        $firstAvailable = null;
        foreach (Dates::range($from, $to) as $pickupDate) {
            if (count($windows) >= 400) {
                break;
            }
            $returnDate = Dates::addDays($pickupDate, $durationDays - 1);
            // Windows whose pickup or return day is closed are omitted entirely.
            if (!$this->calendar->canPickup($pickupDate)) {
                continue;
            }
            if (!$this->calendar->canReturn($returnDate, $pickupDate)) {
                continue;
            }
            $blocking = [];
            for ($d = $pickupDate; $d <= $returnDate; $d = Dates::addDays($d, 1)) {
                $info = $sufficientByDate[$d] ?? null;
                if ($info === null) {
                    continue;
                }
                foreach ($productIds as $id) {
                    if (($info['avail'][$id] ?? 0) < ($requested[$id] ?? 1) && !in_array($id, $blocking, true)) {
                        $blocking[] = $id;
                    }
                }
            }
            $window = [
                'pickup_date' => $pickupDate,
                'return_date' => $returnDate,
                'days' => $durationDays,
                'all_available' => $blocking === [],
                'blocking_product_ids' => $blocking,
            ];
            $windows[] = $window;
            if ($firstAvailable === null && $blocking === []) {
                $firstAvailable = [
                    'pickup_date' => $pickupDate,
                    'return_date' => $returnDate,
                    'days' => $durationDays,
                ];
            }
        }

        return [
            'range' => ['from' => $from, 'to' => $to],
            'duration_days' => $durationDays,
            'days' => $days,
            'windows' => $windows,
            'first_available_window' => $firstAvailable,
            'unavailable_products' => $unavailable,
        ];
    }
}
