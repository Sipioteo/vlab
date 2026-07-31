<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Orders\OrderStateMachine;
use App\Models\User;
use App\Support\ApiException;
use App\Support\Enums;
use Tests\TestCase;

final class OrderStateMachineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
    }

    private function machine(): OrderStateMachine
    {
        return $this->container()->get(OrderStateMachine::class);
    }

    private function user(string $role): User
    {
        $map = ['student' => 'student1', 'technician' => 'tecnico1', 'assistant' => 'borsista1', 'admin' => 'admin1'];
        return User::where('ldap_uid', $map[$role])->first();
    }

    /**
     * §8.4: allowed_actions per role across all 10 statuses. `student` here is
     * the ORDER OWNER (student1) with the cancellation deadline satisfied.
     */
    public function testAllowedActionsMatrix(): void
    {
        $expected = [
            // status => [role => actions]
            'draft' => ['student' => ['submit'], 'technician' => ['note'], 'assistant' => ['note'], 'admin' => ['note']],
            'pending' => [
                'student' => ['cancel'],
                'technician' => ['approve', 'reject', 'cancel', 'edit', 'note'],
                'assistant' => ['approve', 'reject', 'cancel', 'edit', 'note'],
                'admin' => ['approve', 'reject', 'cancel', 'edit', 'note', 'change_dates'],
            ],
            'approved' => [
                'student' => ['cancel'],
                'technician' => ['cancel', 'pickup', 'mark_no_show', 'edit', 'note'],
                'assistant' => ['cancel', 'pickup', 'mark_no_show', 'edit', 'note'],
                'admin' => ['cancel', 'pickup', 'mark_no_show', 'edit', 'note', 'change_dates'],
            ],
            'picked_up' => [
                'student' => [],
                'technician' => ['return', 'note'],
                'assistant' => ['return', 'note'],
                'admin' => ['return', 'note', 'change_dates'],
            ],
            'overdue' => [
                'student' => [],
                'technician' => ['return', 'note'],
                'assistant' => ['return', 'note'],
                'admin' => ['return', 'note', 'change_dates'],
            ],
            'rejected' => ['student' => [], 'technician' => [], 'assistant' => [], 'admin' => ['reopen', 'change_dates']],
            'cancelled' => ['student' => [], 'technician' => [], 'assistant' => [], 'admin' => ['reopen', 'change_dates']],
            'no_show' => ['student' => [], 'technician' => [], 'assistant' => [], 'admin' => ['reopen', 'change_dates']],
            'returned' => ['student' => [], 'technician' => [], 'assistant' => [], 'admin' => ['reopen', 'change_dates']],
            'returned_late' => ['student' => [], 'technician' => [], 'assistant' => [], 'admin' => ['reopen', 'change_dates']],
        ];
        foreach ($expected as $status => $byRole) {
            $order = $this->seedOrder([
                'status' => $status,
                'pickup_date' => '2026-09-10',
                'return_date' => '2026-09-12',
            ]);
            foreach ($byRole as $role => $actions) {
                $actual = $this->machine()->allowedActions($order, $this->user($role));
                sort($actions);
                sort($actual);
                $this->assertSame($actions, $actual, "status={$status} role={$role}");
            }
        }
    }

    public function testAnotherStudentsOrderYieldsNoActions(): void
    {
        $order = $this->seedOrder(['status' => 'pending']);
        $other = User::where('ldap_uid', 'student2')->first();
        $this->assertSame([], $this->machine()->allowedActions($order, $other));
    }

    public function testStudentCancelActionDisappearsInsideDeadline(): void
    {
        // pickup tomorrow 09:30, deadline 24h => already inside the deadline window.
        $order = $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-02',
            'pickup_time' => '09:30',
            'return_date' => '2026-09-03',
        ]);
        $this->assertSame([], $this->machine()->allowedActions($order, $this->user('student')));
        // Far-future pickup keeps the action.
        $order2 = $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-20',
            'pickup_time' => '09:30',
            'return_date' => '2026-09-21',
        ]);
        $this->assertSame(['cancel'], $this->machine()->allowedActions($order2, $this->user('student')));
    }

    /**
     * SPEC test #54 (unit half): every (from-status, action) pair NOT in §8.2
     * raises 409 invalid_transition even for an admin.
     */
    public function testDisallowedTransitionsRaise409(): void
    {
        $admin = $this->user('admin');
        $actions = ['submit', 'approve', 'reject', 'cancel', 'pickup', 'return', 'mark_no_show', 'reopen', 'edit'];
        $checked = 0;
        foreach (Enums::ORDER_STATUSES as $status) {
            $order = $this->seedOrder([
                'status' => $status,
                'pickup_date' => '2026-09-20',
                'return_date' => '2026-09-22',
            ]);
            foreach ($actions as $action) {
                if (in_array($status, OrderStateMachine::ACTION_FROM[$action], true)) {
                    continue; // allowed pair
                }
                try {
                    $this->machine()->assertCan($order, $action, $admin);
                    $this->fail("Expected invalid_transition for status={$status} action={$action}");
                } catch (ApiException $e) {
                    $this->assertSame(409, $e->getStatus(), "status={$status} action={$action}");
                    $this->assertSame('invalid_transition', $e->getErrorCode());
                    $details = $e->getDetails();
                    $this->assertSame($status, $details['current_status']);
                    $this->assertSame($action, $details['action']);
                    $this->assertArrayHasKey('allowed_actions', $details);
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(50, $checked);
    }

    public function testValidPairWithWrongRoleRaises403(): void
    {
        $order = $this->seedOrder(['status' => 'pending']);
        try {
            $this->machine()->assertCan($order, 'approve', $this->user('student'));
            $this->fail('Expected 403');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatus());
        }
        // Assistant/technician may not reopen a terminal order.
        $terminal = $this->seedOrder(['status' => 'returned']);
        foreach (['assistant', 'technician'] as $role) {
            try {
                $this->machine()->assertCan($terminal, 'reopen', $this->user($role));
                $this->fail('Expected 403 for ' . $role);
            } catch (ApiException $e) {
                $this->assertSame(403, $e->getStatus(), $role);
            }
        }
        // Admin passes the same guard.
        $this->machine()->assertCan($terminal, 'reopen', $this->user('admin'));
        $this->assertTrue(true);
    }
}
