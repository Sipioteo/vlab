<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Calendar\CalendarService;
use App\Domain\Settings\SettingsRepository;
use App\Models\Closure;
use App\Support\Dates;
use Tests\TestCase;

final class CalendarServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Tue 2026-09-01, lab timezone Europe/Rome.
        $this->travelTo('2026-09-01 08:00:00');
    }

    private function calendar(): CalendarService
    {
        return new CalendarService(SettingsRepository::instance());
    }

    public function testClosedWeekdayIsNotBookable(): void
    {
        $calendar = $this->calendar();
        // 2026-09-06 is a Sunday (closed by default), 2026-09-07 a Monday.
        $this->assertFalse($calendar->canPickup('2026-09-06'));
        $this->assertFalse($calendar->canReturn('2026-09-06'));
        $this->assertTrue($calendar->canPickup('2026-09-07'));
        $this->assertTrue($calendar->canReturn('2026-09-07'));
    }

    public function testClosureBlockingOnlyPickup(): void
    {
        Closure::create([
            'title' => 'Inventario',
            'start_date' => '2026-09-09',
            'end_date' => '2026-09-10',
            'blocks_pickup' => true,
            'blocks_return' => false,
        ]);
        $calendar = $this->calendar();
        $this->assertFalse($calendar->canPickup('2026-09-09'));
        $this->assertTrue($calendar->canReturn('2026-09-09'));
    }

    public function testRecurringYearlyClosureMatchesOtherYears(): void
    {
        Closure::create([
            'title' => 'Natale',
            'start_date' => '2020-12-24',
            'end_date' => '2021-01-06',
            'blocks_pickup' => true,
            'blocks_return' => true,
            'is_recurring_yearly' => true,
        ]);
        $calendar = $this->calendar();
        $this->assertNotNull($calendar->closureOn('2026-12-25', 'pickup'));
        $this->assertNotNull($calendar->closureOn('2027-01-05', 'pickup'));
        $this->assertNull($calendar->closureOn('2026-11-30', 'pickup'));
        $this->assertNull($calendar->closureOn('2027-01-07', 'pickup'));
    }

    public function testSlotGenerationRespectsDurationAndMultipleWindows(): void
    {
        $this->setSetting('hours.slot_duration_minutes', 60);
        $this->setSetting('hours.pickup_windows', [
            ['weekday' => 1, 'from' => '09:00', 'to' => '11:00'],
            ['weekday' => 1, 'from' => '14:00', 'to' => '15:00'],
        ]);
        $slots = $this->calendar()->pickupSlots('2026-09-07'); // Monday
        $this->assertSame([
            ['start' => '09:00', 'end' => '10:00'],
            ['start' => '10:00', 'end' => '11:00'],
            ['start' => '14:00', 'end' => '15:00'],
        ], $slots);
    }

    public function testEmptyWindowsFallBackToWeeklyOpenClose(): void
    {
        $this->setSetting('hours.pickup_windows', []);
        $this->setSetting('hours.slot_duration_minutes', 120);
        $slots = $this->calendar()->pickupSlots('2026-09-07'); // Monday 09:00-17:00
        $this->assertSame([
            ['start' => '09:00', 'end' => '11:00'],
            ['start' => '11:00', 'end' => '13:00'],
            ['start' => '13:00', 'end' => '15:00'],
            ['start' => '15:00', 'end' => '17:00'],
        ], $slots);
        // Weekday absent from a non-empty windows array => no slots that day (§10.2).
        $this->setSetting('hours.pickup_windows', [['weekday' => 2, 'from' => '09:00', 'to' => '10:00']]);
        $this->assertSame([], $this->calendar()->pickupSlots('2026-09-07'));
    }

    public function testAdvanceWindowBoundsBooking(): void
    {
        $this->setSetting('booking.min_advance_days', 2);
        $this->setSetting('booking.max_advance_days', 10);
        $calendar = $this->calendar();
        $window = $calendar->bookingWindow();
        $this->assertSame('2026-09-03', $window['min_date']);
        $this->assertSame('2026-09-11', $window['max_date']);
        $this->assertFalse($calendar->canPickup('2026-09-02')); // too soon
        $this->assertTrue($calendar->canPickup('2026-09-03'));  // Thursday, open
        $this->assertFalse($calendar->canPickup('2026-09-14')); // beyond max
    }

    public function testDstBoundaryRangeHasCorrectDayCount(): void
    {
        // Last Sunday of March 2026 is the 29th (DST change in Europe/Rome).
        $days = Dates::range('2026-03-27', '2026-03-31');
        $this->assertCount(5, $days);
        $this->assertSame(['2026-03-27', '2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31'], $days);
        $this->assertSame(5, Dates::inclusiveDays('2026-03-27', '2026-03-31'));
    }

    public function testSuggestionsSkipClosedDays(): void
    {
        // 2026-09-05 is a Saturday: suggestions from there start Monday the 7th.
        $suggestions = $this->calendar()->suggestPickupDates('2026-09-05', 3);
        $this->assertSame(['2026-09-07', '2026-09-08', '2026-09-09'], $suggestions);
    }
}
