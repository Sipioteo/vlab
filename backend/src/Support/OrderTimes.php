<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Calendar\CalendarService;
use App\Domain\Settings\SettingsRepository;

/**
 * Display strings for the time-window model (SPEC v1.4 §5.3/§7.4).
 *
 * An order leg (pickup or return) can be, in order of precedence:
 *  - a custom range override:  time + time_end   → "10:15–11:30"
 *  - a precise override:       time alone        → "10:15"
 *  - the lab default (NULL):   the weekday's configured window → "09:00–12:30"
 *    (multiple ranges joined with " / "; null when the day has no window).
 */
final class OrderTimes
{
    private static ?CalendarService $calendar = null;

    /**
     * Shared CalendarService for static resource contexts (OrderResource).
     * Uses the request-cached SettingsRepository; reset() for tests that
     * change settings mid-run.
     */
    public static function calendar(): CalendarService
    {
        if (self::$calendar === null) {
            self::$calendar = new CalendarService(SettingsRepository::instance());
        }
        return self::$calendar;
    }

    public static function reset(): void
    {
        self::$calendar = null;
    }

    /**
     * The window/override display string for one leg.
     *
     * @param string $kind 'pickup' | 'return'
     */
    public static function display(
        ?string $date,
        ?string $time,
        ?string $timeEnd,
        string $kind,
        ?CalendarService $calendar = null
    ): ?string {
        if ($time !== null && $time !== '') {
            return $timeEnd !== null && $timeEnd !== '' ? $time . '–' . $timeEnd : $time;
        }
        if ($date === null) {
            return null;
        }
        return ($calendar ?? self::calendar())->windowLabel($date, $kind);
    }
}
