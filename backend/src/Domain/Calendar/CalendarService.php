<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use App\Domain\Settings\SettingsRepository;
use App\Models\Closure;
use App\Support\Dates;

/**
 * Opening hours, closures and time slots (SPEC §5.3).
 */
class CalendarService
{
    /** @var array<int,array<string,mixed>>|null */
    private ?array $closureCache = null;

    public function __construct(private SettingsRepository $settings)
    {
    }

    public function timezone(): string
    {
        return (string) ($this->settings->get('hours.timezone', 'Europe/Rome') ?? 'Europe/Rome');
    }

    public function today(): string
    {
        return Dates::todayInTz($this->timezone());
    }

    /** @return array<int,array<string,mixed>> weekday(0..6) => entry */
    public function weekly(): array
    {
        $raw = $this->settings->get('hours.weekly', []);
        $out = [];
        foreach ((array) $raw as $entry) {
            if (is_array($entry) && isset($entry['weekday'])) {
                $out[(int) $entry['weekday']] = $entry;
            }
        }
        return $out;
    }

    public function isWeekdayOpen(string $date): bool
    {
        $weekly = $this->weekly();
        $entry = $weekly[Dates::weekday($date)] ?? null;
        return $entry !== null && ($entry['closed'] ?? true) === false;
    }

    /** @return array<int,Closure> all non-deleted closures (cached per instance) */
    private function closures(): array
    {
        if ($this->closureCache === null) {
            $this->closureCache = Closure::all()->all();
        }
        return $this->closureCache;
    }

    public function invalidateClosures(): void
    {
        $this->closureCache = null;
    }

    /**
     * First closure covering the date, filtered by blocking type.
     *
     * @param string $type 'pickup' | 'return' | 'any'
     */
    public function closureOn(string $date, string $type = 'any'): ?Closure
    {
        foreach ($this->closures() as $closure) {
            if ($type === 'pickup' && !$closure->blocks_pickup) {
                continue;
            }
            if ($type === 'return' && !$closure->blocks_return) {
                continue;
            }
            if ($this->closureCovers($closure, $date)) {
                return $closure;
            }
        }
        return null;
    }

    public function closureCovers(Closure $closure, string $date): bool
    {
        $start = Dates::datePart($closure->start_date);
        $end = Dates::datePart($closure->end_date);
        if (!$closure->is_recurring_yearly) {
            return $date >= $start && $date <= $end;
        }
        // Recurring: compare month-day, handling ranges that wrap the new year.
        $md = substr($date, 5);
        $smd = substr((string) $start, 5);
        $emd = substr((string) $end, 5);
        if ($smd <= $emd) {
            return $md >= $smd && $md <= $emd;
        }
        return $md >= $smd || $md <= $emd;
    }

    /** @return array{min_date:string, max_date:string} */
    public function bookingWindow(): array
    {
        $min = (int) ($this->settings->get('booking.min_advance_days', 1) ?? 1);
        $max = (int) ($this->settings->get('booking.max_advance_days', 90) ?? 90);
        $today = $this->today();
        return [
            'min_date' => Dates::addDays($today, $min),
            'max_date' => Dates::addDays($today, $max),
        ];
    }

    public function withinBookingWindow(string $date): bool
    {
        $window = $this->bookingWindow();
        return $date >= $window['min_date'] && $date <= $window['max_date'];
    }

    /**
     * Bookable-as-pickup per §5.3 (all four conditions). Set $checkAdvance=false
     * for pure day-level calendar rendering.
     */
    public function canPickup(string $date, bool $checkAdvance = true): bool
    {
        if ($checkAdvance && !$this->withinBookingWindow($date)) {
            return false;
        }
        return $this->isWeekdayOpen($date) && $this->closureOn($date, 'pickup') === null;
    }

    /** Bookable-as-return per §5.3 (conditions 3+4; pickup ordering checked by caller). */
    public function canReturn(string $date, ?string $pickupDate = null): bool
    {
        if ($pickupDate !== null && $date < $pickupDate) {
            return false;
        }
        return $this->isWeekdayOpen($date) && $this->closureOn($date, 'return') === null;
    }

    public function isOpen(string $date): bool
    {
        return $this->isWeekdayOpen($date) && $this->closureOn($date, 'any') === null;
    }

    /** @return array<int,array{start:string,end:string}> */
    public function pickupSlots(string $date): array
    {
        return $this->slots($date, 'hours.pickup_windows');
    }

    /** @return array<int,array{start:string,end:string}> */
    public function returnSlots(string $date): array
    {
        return $this->slots($date, 'hours.return_windows');
    }

    /** @return array<int,array{start:string,end:string}> */
    private function slots(string $date, string $windowsKey): array
    {
        $weekday = Dates::weekday($date);
        $windows = (array) ($this->settings->get($windowsKey, []) ?? []);
        $ranges = [];
        if ($windows === []) {
            // Empty array => fall back to hours.weekly open/close (SPEC §10.2).
            $entry = $this->weekly()[$weekday] ?? null;
            if ($entry !== null && ($entry['closed'] ?? true) === false) {
                $ranges[] = ['from' => (string) $entry['open'], 'to' => (string) $entry['close']];
            }
        } else {
            foreach ($windows as $w) {
                if (is_array($w) && (int) ($w['weekday'] ?? -1) === $weekday) {
                    $ranges[] = ['from' => (string) $w['from'], 'to' => (string) $w['to']];
                }
            }
        }
        $duration = (int) ($this->settings->get('hours.slot_duration_minutes', 30) ?? 30);
        if ($duration <= 0) {
            $duration = 30;
        }
        $slots = [];
        foreach ($ranges as $range) {
            $start = $this->toMinutes($range['from']);
            $end = $this->toMinutes($range['to']);
            for ($m = $start; $m + $duration <= $end; $m += $duration) {
                $slots[] = [
                    'start' => $this->toTime($m),
                    'end' => $this->toTime($m + $duration),
                ];
            }
        }
        usort($slots, static fn (array $a, array $b) => strcmp($a['start'], $b['start']));
        return $slots;
    }

    /** Nearest valid pickup dates after (and around) an invalid one (SPEC §5.3 auto-shift rule). */
    public function suggestPickupDates(string $nearDate, int $count = 3): array
    {
        $out = [];
        $window = $this->bookingWindow();
        $d = max($nearDate, $window['min_date']);
        $limit = 366;
        while (count($out) < $count && $limit-- > 0 && $d <= $window['max_date']) {
            if ($this->canPickup($d)) {
                $out[] = $d;
            }
            $d = Dates::addDays($d, 1);
        }
        return $out;
    }

    private function toMinutes(string $time): int
    {
        [$h, $m] = explode(':', $time);
        return ((int) $h) * 60 + (int) $m;
    }

    private function toTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
