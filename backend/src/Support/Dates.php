<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * All date arithmetic happens in PHP (SPEC §1.3). Timestamps are stored in UTC;
 * business-day computations happen in the lab timezone.
 */
final class Dates
{
    /** Test hook: freeze "now" (UTC). */
    public static ?DateTimeImmutable $frozenNow = null;

    public static function nowUtc(): DateTimeImmutable
    {
        if (self::$frozenNow !== null) {
            return self::$frozenNow;
        }
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function travelTo(?string $datetime): void
    {
        self::$frozenNow = $datetime === null
            ? null
            : new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
        // Keep Eloquent timestamps consistent with the frozen clock.
        if (class_exists(\Carbon\Carbon::class)) {
            \Carbon\Carbon::setTestNow($datetime === null ? null : \Carbon\Carbon::parse($datetime, 'UTC'));
        }
    }

    public static function nowInTz(string $tz): DateTimeImmutable
    {
        return self::nowUtc()->setTimezone(new DateTimeZone($tz));
    }

    /** Today's date (Y-m-d) in the given timezone. */
    public static function todayInTz(string $tz): string
    {
        return self::nowInTz($tz)->format('Y-m-d');
    }

    /** Format a stored datetime (UTC) as ISO-8601 with Z suffix, or null. */
    public static function iso($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            $dt = $value;
        } elseif ($value instanceof \DateTimeInterface) {
            $dt = DateTimeImmutable::createFromInterface($value);
        } else {
            $dt = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** DB storage format for the current UTC time. */
    public static function nowDb(): string
    {
        return self::nowUtc()->format('Y-m-d H:i:s');
    }

    /** Extract the plain date part (Y-m-d) from a date/datetime value, or null. */
    public static function datePart($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }

    public static function isValidDate(?string $value): bool
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }

    public static function isValidTime(?string $value): bool
    {
        return $value !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format('Y-m-d');
    }

    /** Inclusive day count between two Y-m-d dates (same day = 1). */
    public static function inclusiveDays(string $from, string $to): int
    {
        return self::diffDays($from, $to) + 1;
    }

    /** to - from in whole days (can be negative). */
    public static function diffDays(string $from, string $to): int
    {
        $a = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC'));
        $b = new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('UTC'));
        $diff = $a->diff($b);
        return $diff->invert === 1 ? -$diff->days : $diff->days;
    }

    /** List of all Y-m-d dates in the inclusive range. @return string[] */
    public static function range(string $from, string $to): array
    {
        $out = [];
        $d = $from;
        while ($d <= $to) {
            $out[] = $d;
            $d = self::addDays($d, 1);
        }
        return $out;
    }

    /** Weekday with Sunday = 0 (JS getDay numbering, SPEC §7.8). */
    public static function weekday(string $date): int
    {
        return (int) (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->format('w');
    }
}
