<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Calendar\CalendarService;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Soft/hard loan limit evaluation (SPEC §5.4). A `null` setting means the
 * check is skipped entirely (infinite).
 */
class LimitsEvaluator
{
    public function __construct(
        private SettingsRepository $settings,
        private CalendarService $calendar,
        private AvailabilityService $availability,
    ) {
    }

    /**
     * @param array<int,array{product_id:int, quantity:int, product?:object}> $items
     *        each entry SHOULD carry a loaded `product` object (loan_mode, max_loan_days).
     * @param array<int,array{capacity:int, available:int}>|null $availabilityByProduct
     *        pass precomputed availability to emit insufficient_availability.
     * @return array<int,array<string,mixed>> violation objects
     */
    public function evaluate(
        User $user,
        array $items,
        ?string $pickupDate,
        ?string $pickupTime,
        ?string $returnDate,
        ?string $returnTime,
        ?int $excludeOrderId = null,
        ?array $availabilityByProduct = null
    ): array {
        $violations = [];
        $allowExceeding = (bool) ($this->settings->get('booking.allow_exceeding_limits', true) ?? true);
        $soft = static fn (string $severity): string => $allowExceeding ? $severity : 'hard';

        $add = function (string $code, string $severity, string $message, $limit, $actual, array $productIds = []) use (&$violations): void {
            $violations[] = [
                'code' => $code,
                'severity' => $severity,
                'message' => $message,
                'limit' => $limit,
                'actual' => $actual,
                'product_ids' => array_values($productIds),
            ];
        };

        // ---- duration checks -------------------------------------------------
        if ($pickupDate !== null && $returnDate !== null && $returnDate >= $pickupDate) {
            $duration = Dates::inclusiveDays($pickupDate, $returnDate);

            $maxLoanDays = $this->settings->get('booking.max_loan_days', 7);
            if ($maxLoanDays !== null && $duration > (int) $maxLoanDays) {
                $add(
                    'max_loan_days_exceeded',
                    $soft('soft'),
                    "La durata richiesta ({$duration} giorni) supera il limite di {$maxLoanDays} giorni.",
                    (int) $maxLoanDays,
                    $duration
                );
            }

            // Per-product narrower caps win (SPEC test 50).
            $productCapped = [];
            $narrowest = null;
            foreach ($items as $item) {
                $product = $item['product'] ?? null;
                $cap = $product?->max_loan_days;
                if ($cap !== null && $duration > (int) $cap && ($maxLoanDays === null || (int) $cap < (int) $maxLoanDays)) {
                    $productCapped[] = (int) $item['product_id'];
                    $narrowest = $narrowest === null ? (int) $cap : min($narrowest, (int) $cap);
                }
            }
            if ($productCapped !== []) {
                $add(
                    'max_loan_days_exceeded',
                    $soft('soft'),
                    "La durata richiesta ({$duration} giorni) supera il limite di {$narrowest} giorni per alcuni prodotti.",
                    $narrowest,
                    $duration,
                    $productCapped
                );
            }

            $hardCap = $this->settings->get('booking.max_loan_days_hard_cap', 30);
            if ($hardCap !== null && $duration > (int) $hardCap) {
                $add(
                    'max_loan_days_hard_cap_exceeded',
                    'hard',
                    "La durata richiesta ({$duration} giorni) supera il limite invalicabile di {$hardCap} giorni.",
                    (int) $hardCap,
                    $duration
                );
            }

            // on_site_only products cannot span multiple days.
            $onSite = [];
            foreach ($items as $item) {
                $product = $item['product'] ?? null;
                if ($product !== null && $product->loan_mode === 'on_site_only' && $pickupDate !== $returnDate) {
                    $onSite[] = (int) $item['product_id'];
                }
            }
            if ($onSite !== []) {
                $add(
                    'on_site_only_multi_day',
                    'hard',
                    'Alcuni prodotti sono utilizzabili solo in sede e non possono essere prestati per più giorni.',
                    1,
                    Dates::inclusiveDays($pickupDate, $returnDate),
                    $onSite
                );
            }
        }

        // ---- monthly / yearly / active quotas -------------------------------
        if ($pickupDate !== null) {
            $countedStatuses = ['pending', 'approved', 'picked_up', 'overdue', 'returned', 'returned_late', 'no_show'];
            $maxMonth = $this->settings->get('booking.max_orders_per_month', 4);
            if ($maxMonth !== null) {
                $count = $this->countOrdersInPeriod($user, substr($pickupDate, 0, 7), 'month', $countedStatuses, $excludeOrderId);
                if ($count >= (int) $maxMonth) {
                    $add(
                        'max_orders_per_month_exceeded',
                        $soft('soft'),
                        "Hai già raggiunto il numero massimo di {$maxMonth} prestiti per questo mese.",
                        (int) $maxMonth,
                        $count + 1
                    );
                }
            }
            $maxYear = $this->settings->get('booking.max_orders_per_year');
            if ($maxYear !== null) {
                $count = $this->countOrdersInPeriod($user, substr($pickupDate, 0, 4), 'year', $countedStatuses, $excludeOrderId);
                if ($count >= (int) $maxYear) {
                    $add(
                        'max_orders_per_year_exceeded',
                        $soft('soft'),
                        "Hai già raggiunto il numero massimo di {$maxYear} prestiti per quest'anno.",
                        (int) $maxYear,
                        $count + 1
                    );
                }
            }
        }

        $maxActive = $this->settings->get('booking.max_active_orders', 2);
        if ($maxActive !== null) {
            $query = Capsule::table('orders')
                ->where('user_id', $user->id)
                ->whereIn('status', ['pending', 'approved', 'picked_up', 'overdue'])
                ->whereNull('deleted_at');
            if ($excludeOrderId !== null) {
                $query->where('id', '!=', $excludeOrderId);
            }
            $count = (int) $query->count();
            if ($count >= (int) $maxActive) {
                $add(
                    'max_active_orders_exceeded',
                    $soft('soft'),
                    "Hai già {$count} richieste attive: il massimo consentito è {$maxActive}.",
                    (int) $maxActive,
                    $count + 1
                );
            }
        }

        // ---- cart shape ------------------------------------------------------
        $maxItems = $this->settings->get('booking.max_items_per_order', 10);
        $distinct = count($items);
        if ($maxItems !== null && $distinct > (int) $maxItems) {
            $add(
                'max_items_per_order_exceeded',
                'hard',
                "La richiesta contiene {$distinct} prodotti: il massimo consentito è {$maxItems}.",
                (int) $maxItems,
                $distinct
            );
        }

        $maxQty = $this->settings->get('booking.max_quantity_per_product_per_order', 2);
        if ($maxQty !== null) {
            $over = [];
            $worst = 0;
            foreach ($items as $item) {
                if ((int) $item['quantity'] > (int) $maxQty) {
                    $over[] = (int) $item['product_id'];
                    $worst = max($worst, (int) $item['quantity']);
                }
            }
            if ($over !== []) {
                $add(
                    'max_quantity_per_product_exceeded',
                    'hard',
                    "La quantità massima per singolo prodotto è {$maxQty}.",
                    (int) $maxQty,
                    $worst,
                    $over
                );
            }
        }

        // ---- calendar checks -------------------------------------------------
        if ($pickupDate !== null) {
            if (!$this->calendar->withinBookingWindow($pickupDate)) {
                $window = $this->calendar->bookingWindow();
                $add(
                    'advance_window_violated',
                    'hard',
                    "La data di ritiro deve essere compresa tra {$window['min_date']} e {$window['max_date']}.",
                    null,
                    null
                );
            }
            if (!$this->calendar->isWeekdayOpen($pickupDate) || $this->calendar->closureOn($pickupDate, 'pickup') !== null) {
                $add('date_not_bookable', 'hard', 'La data di ritiro selezionata non è disponibile (laboratorio chiuso).', null, null);
            } elseif ($pickupTime !== null && !$this->slotExists($this->calendar->pickupSlots($pickupDate), $pickupTime)) {
                $add('slot_not_available', 'hard', 'L\'orario di ritiro selezionato non è disponibile.', null, null);
            }
        }
        if ($returnDate !== null) {
            if (!$this->calendar->isWeekdayOpen($returnDate) || $this->calendar->closureOn($returnDate, 'return') !== null) {
                $add('date_not_bookable', 'hard', 'La data di riconsegna selezionata non è disponibile (laboratorio chiuso).', null, null);
            } elseif ($returnTime !== null && !$this->slotExists($this->calendar->returnSlots($returnDate), $returnTime)) {
                $add('slot_not_available', 'hard', 'L\'orario di riconsegna selezionato non è disponibile.', null, null);
            }
        }

        // ---- stock -----------------------------------------------------------
        if ($availabilityByProduct !== null) {
            $short = [];
            foreach ($items as $item) {
                $pid = (int) $item['product_id'];
                $avail = $availabilityByProduct[$pid]['available'] ?? 0;
                if ((int) $item['quantity'] > $avail) {
                    $short[] = $pid;
                }
            }
            if ($short !== []) {
                $add(
                    'insufficient_availability',
                    'hard',
                    'La disponibilità non è sufficiente per alcuni prodotti nel periodo selezionato.',
                    null,
                    null,
                    $short
                );
            }
        }

        return $violations;
    }

    /** @param string[] $statuses */
    private function countOrdersInPeriod(User $user, string $prefix, string $period, array $statuses, ?int $excludeOrderId): int
    {
        $query = Capsule::table('orders')
            ->where('user_id', $user->id)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->whereNotNull('pickup_date');
        if ($excludeOrderId !== null) {
            $query->where('id', '!=', $excludeOrderId);
        }
        // Portable: fetch pickup dates, bucket in PHP (no DB date functions).
        $dates = $query->pluck('pickup_date');
        $count = 0;
        foreach ($dates as $date) {
            $d = (string) Dates::datePart($date);
            $key = $period === 'month' ? substr($d, 0, 7) : substr($d, 0, 4);
            if ($key === $prefix) {
                $count++;
            }
        }
        return $count;
    }

    /** @param array<int,array{start:string,end:string}> $slots */
    private function slotExists(array $slots, string $time): bool
    {
        foreach ($slots as $slot) {
            if ($slot['start'] === $time) {
                return true;
            }
        }
        return false;
    }

    public static function hasHard(array $violations): bool
    {
        foreach ($violations as $v) {
            if (($v['severity'] ?? '') === 'hard') {
                return true;
            }
        }
        return false;
    }

    public static function hasSoft(array $violations): bool
    {
        foreach ($violations as $v) {
            if (($v['severity'] ?? '') === 'soft') {
                return true;
            }
        }
        return false;
    }
}
