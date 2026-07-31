<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Calendar\CalendarService;
use App\Domain\Settings\SettingsRepository;
use App\Models\Order;
use App\Models\User;
use App\Support\ApiException;
use App\Support\Dates;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The binding transition table of SPEC §8.2 plus per-viewer allowed_actions (§8.4).
 */
class OrderStateMachine
{
    /** Staff roles that operate orders. */
    private const STAFF = ['technician', 'assistant', 'admin'];

    /**
     * action => [from-statuses]. `return` target (returned|returned_late) and
     * reopen target are decided by the caller.
     */
    public const ACTION_FROM = [
        'submit' => ['draft'],
        'approve' => ['pending'],
        'reject' => ['pending'],
        'cancel' => ['pending', 'approved'],
        'pickup' => ['approved'],
        'return' => ['picked_up', 'overdue'],
        'mark_no_show' => ['approved'],
        'reopen' => ['rejected', 'cancelled', 'no_show', 'returned', 'returned_late'],
        'edit' => ['pending', 'approved'],
        'note' => ['draft', 'pending', 'approved', 'picked_up', 'overdue'],
    ];

    public function __construct(
        private SettingsRepository $settings,
        private CalendarService $calendar,
    ) {
    }

    /**
     * Guard used by every transition endpoint.
     *
     * 409 invalid_transition when the (status, action) pair is not in the table
     * or a guard (student cancellation deadline) fails; 403 when the pair is
     * valid but the viewer's role may not trigger it.
     */
    public function assertCan(Order $order, string $action, User $viewer): void
    {
        $from = (string) $order->status;
        $valid = in_array($from, self::ACTION_FROM[$action] ?? [], true);
        if (!$valid) {
            throw new ApiException(409, 'invalid_transition', 'Operazione non consentita nello stato attuale.', [
                'current_status' => $from,
                'action' => $action,
                'allowed_actions' => $this->allowedActions($order, $viewer),
            ]);
        }
        if (!$this->roleMay($order, $action, $viewer)) {
            throw new ApiException(403, 'forbidden', 'Non hai i permessi per questa operazione.');
        }
        if ($action === 'cancel' && $viewer->role === 'student' && $from === 'approved'
            && !$this->studentCanStillCancel($order)) {
            $hours = (int) ($this->settings->get('booking.cancellation_deadline_hours', 24) ?? 24);
            throw new ApiException(409, 'invalid_transition', "Non è più possibile annullare: il termine di {$hours} ore prima del ritiro è superato.", [
                'current_status' => $from,
                'action' => $action,
                'allowed_actions' => $this->allowedActions($order, $viewer),
            ]);
        }
    }

    /** Pure role/ownership check (no status considerations beyond ownership rules). */
    private function roleMay(Order $order, string $action, User $viewer): bool
    {
        $isStaff = in_array($viewer->role, self::STAFF, true);
        $isOwner = (int) $order->user_id === (int) $viewer->id;
        switch ($action) {
            case 'submit':
                return $isOwner && $viewer->role === 'student';
            case 'cancel':
                return $isStaff || ($isOwner && $viewer->role === 'student');
            case 'approve':
            case 'reject':
            case 'pickup':
            case 'return':
            case 'mark_no_show':
            case 'edit':
            case 'note':
                return $isStaff;
            case 'reopen':
                return $viewer->role === 'admin';
        }
        return false;
    }

    public function studentCanStillCancel(Order $order): bool
    {
        if ($order->pickup_date === null) {
            return true;
        }
        $hours = (int) ($this->settings->get('booking.cancellation_deadline_hours', 24) ?? 24);
        $tz = new DateTimeZone($this->calendar->timezone());
        $time = $order->pickup_time !== null ? (string) $order->pickup_time : '00:00';
        $pickupAt = new DateTimeImmutable(Dates::datePart($order->pickup_date) . ' ' . $time, $tz);
        $deadline = $pickupAt->modify("-{$hours} hours");
        return Dates::nowUtc() < $deadline;
    }

    /**
     * §8.4 — actions reachable for this viewer given the current status.
     *
     * @return string[]
     */
    public function allowedActions(Order $order, User $viewer): array
    {
        $out = [];
        foreach (array_keys(self::ACTION_FROM) as $action) {
            if (!in_array((string) $order->status, self::ACTION_FROM[$action], true)) {
                continue;
            }
            if (!$this->roleMay($order, $action, $viewer)) {
                continue;
            }
            if ($action === 'cancel' && $viewer->role === 'student' && $order->status === 'approved'
                && !$this->studentCanStillCancel($order)) {
                continue;
            }
            $out[] = $action;
        }
        return $out;
    }
}
