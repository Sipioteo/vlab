<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use App\Domain\Settings\SettingsRepository;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Dates;
use App\Support\OrderTimes;
use DateTimeImmutable;
use DateTimeZone;

/**
 * RFC 5545 feed generation for the per-user obfuscated calendar link.
 *
 * Design decisions (documented deliberately, they are not accidental):
 *
 * 1. **Two events per order, not one spanning event.** An order is not an
 *    all-day occupancy: it is two appointments at the counter — a pickup and a
 *    return — with their own slot times, while the days in between are simply
 *    "the gear is out". A single multi-day VEVENT would smear a fat block over
 *    every day grid; two short events land exactly where the person (or the
 *    lab) has to be somewhere. UIDs are therefore `order-{id}-pickup@vlab` and
 *    `order-{id}-return@vlab`, stable across regenerations of the feed.
 *
 * 2. **UTC instants, no VTIMEZONE.** Slot times are stored as local wall clock
 *    in the lab timezone; we convert them to UTC and emit `…Z` values. That is
 *    unambiguous for every consumer, needs no VTIMEZONE block, and survives DST
 *    because the conversion happens per event with the real offset in force.
 */
final class IcalService
{
    private const PRODID = '-//Visionary Lab//Prestito attrezzature//IT';

    /** Rolling window for the staff (lab-wide) feed, in days. */
    public const STAFF_PAST_DAYS = 30;
    public const STAFF_FUTURE_DAYS = 120;

    public function __construct(
        private SettingsRepository $settings,
        private CalendarService $calendar,
    ) {
    }

    /** Statuses that deserve a calendar entry at all. */
    private const ACTIVE_STATUSES = ['pending', 'approved', 'picked_up', 'overdue'];

    public function feedFor(User $user): string
    {
        return $user->isStaff() ? $this->staffFeed($user) : $this->studentFeed($user);
    }

    // ------------------------------------------------------------- feeds ---

    private function studentFeed(User $user): string
    {
        $orders = Order::with('user')
            ->where('user_id', $user->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderBy('pickup_date')
            ->get()
            ->all();

        return $this->render($orders, $this->labName() . ' — Le tue richieste', false);
    }

    private function staffFeed(User $user): string
    {
        $today = $this->calendar->today();
        $from = Dates::addDays($today, -self::STAFF_PAST_DAYS);
        $to = Dates::addDays($today, self::STAFF_FUTURE_DAYS);

        $orders = Order::with('user')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where(static function ($b) use ($from, $to) {
                $b->whereBetween('pickup_date', [$from, $to])
                    ->orWhereBetween('return_date', [$from, $to]);
            })
            ->orderBy('pickup_date')
            ->get()
            ->all();

        return $this->render($orders, $this->labName() . ' — Ritiri e riconsegne', true);
    }

    // ------------------------------------------------------------ render ---

    /**
     * @param array<int,Order> $orders
     */
    private function render(array $orders, string $calendarName, bool $withOwner): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escape($calendarName),
            'X-WR-TIMEZONE:' . $this->calendar->timezone(),
        ];

        foreach ($orders as $order) {
            foreach (['pickup', 'return'] as $kind) {
                $event = $this->event($order, $kind, $withOwner);
                if ($event !== null) {
                    foreach ($event as $line) {
                        $lines[] = $line;
                    }
                }
            }
        }

        $lines[] = 'END:VCALENDAR';

        $out = '';
        foreach ($lines as $line) {
            $out .= self::fold($line) . "\r\n";
        }
        return $out;
    }

    /**
     * One VEVENT as an array of unfolded content lines, or null when the order
     * has no date for that leg (or the leg is already behind us in a way that
     * makes it meaningless, e.g. a returned pickup on a cancelled order).
     *
     * @return array<int,string>|null
     */
    private function event(Order $order, string $kind, bool $withOwner): ?array
    {
        $date = $kind === 'pickup'
            ? Dates::datePart($order->pickup_date)
            : Dates::datePart($order->return_date);
        if ($date === null) {
            return null;
        }
        // `pending` orders have no confirmed pickup yet — still worth showing as
        // TENTATIVE so the student sees the date they asked for.
        $status = $order->status === 'pending' ? 'TENTATIVE' : 'CONFIRMED';

        [$start, $end] = $this->instants($order, $kind, $date);

        $code = (string) ($order->code ?? ('#' . $order->id));
        $count = (int) $order->items_count;
        $label = $kind === 'pickup' ? 'Ritiro' : 'Riconsegna';
        $summary = sprintf('%s %s · %s', $label, $code, self::plural($count));
        if ($withOwner) {
            $owner = $order->user?->displayName();
            if ($owner !== null && $owner !== '') {
                $summary .= ' · ' . $owner;
            }
        }

        $lines = [
            'BEGIN:VEVENT',
            'UID:' . self::uid($order, $kind),
            'DTSTAMP:' . self::utcStamp(Dates::nowUtc()),
            'DTSTART:' . self::utcStamp($start),
            'DTEND:' . self::utcStamp($end),
            'SUMMARY:' . self::escape($summary),
            'DESCRIPTION:' . self::escape($this->description($order, $kind, $withOwner)),
            'STATUS:' . $status,
            'TRANSP:OPAQUE',
            'SEQUENCE:' . (int) ($order->updated_at !== null ? 1 : 0),
            'CATEGORIES:' . self::escape($label),
        ];
        $location = $this->location();
        if ($location !== '') {
            $lines[] = 'LOCATION:' . self::escape($location);
        }
        $lines[] = 'END:VEVENT';
        return $lines;
    }

    /** UID stable per order + leg (SPEC: regenerating the feed must not duplicate). */
    public static function uid(Order $order, string $kind): string
    {
        return sprintf('vlab-order-%d-%s@visionarylab.polito.it', (int) $order->id, $kind);
    }

    /**
     * Wall-clock event bounds in the lab timezone → [start, end] UTC instants,
     * following the time-window model (SPEC v1.4 §5.3):
     *
     *  - custom range override (time + time_end): DTSTART..DTEND = the range;
     *  - precise override (time alone): DTSTART = the time, duration =
     *    `hours.slot_duration_minutes` — an appointment, not a window;
     *  - NULL time (the default): DTSTART..DTEND = the weekday's first
     *    configured window, so the event spans exactly when the lab expects
     *    the student ("dalle 11 alle 13"), with a 09:00 + slot-duration
     *    fallback when the day has no window at all.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    private function instants(Order $order, string $kind, string $date): array
    {
        $tz = new DateTimeZone($this->calendar->timezone());
        $duration = (int) ($this->settings->get('hours.slot_duration_minutes', 30) ?? 30);
        if ($duration <= 0) {
            $duration = 30;
        }

        $time = $kind === 'pickup' ? $order->pickup_time : $order->return_time;
        $timeEnd = $kind === 'pickup' ? $order->pickup_time_end : $order->return_time_end;
        $time = is_string($time) && Dates::isValidTime($time) ? $time : null;
        $timeEnd = is_string($timeEnd) && Dates::isValidTime($timeEnd) ? $timeEnd : null;

        if ($time !== null) {
            $start = new DateTimeImmutable($date . ' ' . $time . ':00', $tz);
            $end = $timeEnd !== null && $timeEnd > $time
                ? new DateTimeImmutable($date . ' ' . $timeEnd . ':00', $tz)
                : $start->modify('+' . $duration . ' minutes');
        } else {
            $window = $this->calendar->windowRanges($date, $kind)[0] ?? null;
            if ($window !== null) {
                $start = new DateTimeImmutable($date . ' ' . $window['from'] . ':00', $tz);
                $end = new DateTimeImmutable($date . ' ' . $window['to'] . ':00', $tz);
            } else {
                // No window configured: a 09:00 marker beats an all-day blob
                // that most clients render as a full-width banner.
                $start = new DateTimeImmutable($date . ' 09:00:00', $tz);
                $end = $start->modify('+' . $duration . ' minutes');
            }
        }
        return [
            $start->setTimezone(new DateTimeZone('UTC')),
            $end->setTimezone(new DateTimeZone('UTC')),
        ];
    }

    private function description(Order $order, string $kind, bool $withOwner): string
    {
        $parts = [];
        if ($withOwner) {
            $owner = $order->user?->displayName();
            if ($owner !== null && $owner !== '') {
                $parts[] = 'Richiedente: ' . $owner;
            }
        }
        $parts[] = 'Stato: ' . self::statusLabel((string) $order->status);
        $items = $this->itemLines($order);
        if ($items !== []) {
            $parts[] = 'Attrezzature:';
            foreach ($items as $line) {
                $parts[] = '- ' . $line;
            }
        }
        if ($kind === 'pickup' && $order->return_date !== null) {
            $window = OrderTimes::display((string) Dates::datePart($order->return_date), $order->return_time, $order->return_time_end, 'return', $this->calendar);
            $parts[] = 'Riconsegna prevista: ' . Dates::datePart($order->return_date)
                . ($window !== null ? ' · ' . $window : '');
        }
        if ($kind === 'return' && $order->pickup_date !== null) {
            $window = OrderTimes::display((string) Dates::datePart($order->pickup_date), $order->pickup_time, $order->pickup_time_end, 'pickup', $this->calendar);
            $parts[] = 'Ritirato il: ' . Dates::datePart($order->pickup_date)
                . ($window !== null ? ' · ' . $window : '');
        }
        return implode("\n", $parts);
    }

    /** @return array<int,string> */
    private function itemLines(Order $order): array
    {
        $out = [];
        $items = OrderItem::where('order_id', $order->id)->get();
        foreach ($items as $item) {
            $name = $item->product_name_snapshot;
            if ($name === null || $name === '') {
                $name = Product::withTrashed()->find($item->product_id)?->name;
            }
            if ($name === null || $name === '') {
                $name = 'Attrezzatura #' . (int) $item->product_id;
            }
            $qty = (int) $item->quantity;
            $out[] = $qty > 1 ? $name . ' ×' . $qty : (string) $name;
        }
        return $out;
    }

    private function labName(): string
    {
        $name = (string) ($this->settings->get('lab.name', 'Visionary Lab') ?? 'Visionary Lab');
        return $name !== '' ? $name : 'Visionary Lab';
    }

    private function location(): string
    {
        $room = trim((string) ($this->settings->get('lab.room', '') ?? ''));
        $address = trim((string) ($this->settings->get('lab.address', '') ?? ''));
        $parts = array_values(array_filter([$room, $address], static fn (string $s) => $s !== ''));
        return implode(', ', $parts);
    }

    private static function plural(int $count): string
    {
        return $count === 1 ? '1 articolo' : $count . ' articoli';
    }

    private static function statusLabel(string $status): string
    {
        return [
            'pending' => 'In attesa di approvazione',
            'approved' => 'Approvata',
            'picked_up' => 'Ritirata',
            'overdue' => 'In ritardo',
        ][$status] ?? $status;
    }

    // ----------------------------------------------------------- encoding ---

    public static function utcStamp(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * RFC 5545 §3.3.11 TEXT escaping. Backslash first, then the rest, so we
     * never double-escape our own escapes.
     */
    public static function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
        $value = str_replace(';', '\\;', $value);
        $value = str_replace(',', '\\,', $value);
        return $value;
    }

    /**
     * RFC 5545 §3.1 content line folding: no line longer than 75 **octets**,
     * continuations start with a single space. Multi-byte characters are never
     * split across a fold.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        $limit = 75;
        $length = mb_strlen($line, 'UTF-8');
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($line, $i, 1, 'UTF-8');
            if (strlen($current) + strlen($char) > $limit) {
                $out .= $current . "\r\n ";
                $current = '';
                // Continuation lines carry a leading space that counts toward 75.
                $limit = 74;
            }
            $current .= $char;
        }
        return $out . $current;
    }
}
