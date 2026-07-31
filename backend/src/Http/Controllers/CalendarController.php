<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendar\CalendarService;
use App\Http\Resources\ClosureResource;
use App\Models\Closure;
use App\Support\ApiException;
use App\Support\Dates;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CalendarController extends Controller
{
    private const WEEKDAY_LABELS = [
        0 => 'Domenica', 1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì',
        4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato',
    ];

    public function __construct(private CalendarService $calendar)
    {
    }

    /** GET /calendar/opening (SPEC §7.8 #33). */
    public function opening(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $from = isset($query['from']) && Dates::isValidDate((string) $query['from']) ? (string) $query['from'] : $this->calendar->today();
        $to = isset($query['to']) && Dates::isValidDate((string) $query['to']) ? (string) $query['to'] : Dates::addDays($from, 90);
        if ($to < $from) {
            throw ApiException::validation(['to' => ['to deve essere successiva o uguale a from.']]);
        }
        if (Dates::inclusiveDays($from, $to) > 366) {
            $to = Dates::addDays($from, 365);
        }

        $weekly = [];
        $weeklyMap = $this->calendar->weekly();
        for ($wd = 0; $wd <= 6; $wd++) {
            $entry = $weeklyMap[$wd] ?? ['weekday' => $wd, 'closed' => true, 'open' => null, 'close' => null];
            $weekly[] = [
                'weekday' => $wd,
                'label' => self::WEEKDAY_LABELS[$wd],
                'closed' => (bool) ($entry['closed'] ?? true),
                'open' => $entry['open'] ?? null,
                'close' => $entry['close'] ?? null,
            ];
        }

        $closures = [];
        foreach (Closure::orderBy('start_date')->get() as $closure) {
            // Emit closures relevant to the range (recurring ones matched by month-day).
            $covers = false;
            foreach (Dates::range($from, min($to, Dates::addDays($from, 365))) as $d) {
                if ($this->calendar->closureCovers($closure, $d)) {
                    $covers = true;
                    break;
                }
            }
            if ($covers) {
                $closures[] = ClosureResource::toArray($closure);
            }
        }

        $days = [];
        foreach (Dates::range($from, $to) as $date) {
            $isOpen = $this->calendar->isOpen($date);
            $canPickup = $this->calendar->canPickup($date, false);
            $canReturn = $this->calendar->canReturn($date);
            $closure = $this->calendar->closureOn($date, 'any');
            $days[] = [
                'date' => $date,
                'weekday' => Dates::weekday($date),
                'is_open' => $isOpen,
                'can_pickup' => $canPickup,
                'can_return' => $canReturn,
                'closure_id' => $closure !== null ? (int) $closure->id : null,
                'pickup_slots' => $canPickup ? $this->calendar->pickupSlots($date) : [],
                'return_slots' => $canReturn ? $this->calendar->returnSlots($date) : [],
            ];
        }

        return $this->json($response, [
            'timezone' => $this->calendar->timezone(),
            'weekly' => $weekly,
            'closures' => $closures,
            'days' => $days,
            'booking_window' => $this->calendar->bookingWindow(),
        ]);
    }
}
