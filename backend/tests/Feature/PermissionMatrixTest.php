<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductLog;
use App\Models\ProductUnit;
use App\Models\Regulation;
use App\Models\User;
use Tests\TestCase;

/**
 * SPEC test #105: table-driven role × endpoint matrix over the §7.5 index.
 * "allowed" means anything but 401/403; denied roles must receive exactly
 * 403 (authenticated) or 401 (anonymous).
 */
final class PermissionMatrixTest extends TestCase
{
    private const ALL = ['anon', 'student', 'assistant', 'technician', 'admin'];
    private const AUTH = ['student', 'assistant', 'technician', 'admin'];
    private const STAFF = ['assistant', 'technician', 'admin'];
    private const TECH_ADMIN = ['technician', 'admin'];
    private const ADMIN = ['admin'];
    private const STUDENT = ['student'];

    public function testRoleEndpointMatrix(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $this->setSetting('regulations.enforce_global_acceptance', false);

        // Rebuild the fixture for every (endpoint, role) probe so mutations never leak.
        $rows = $this->matrix();
        foreach ($rows as $label => [$method, $pathFactory, $body, $allowedRoles]) {
            foreach (self::ALL as $role) {
                $fixture = $this->buildFixture();
                $path = $pathFactory($fixture);
                if ($role === 'anon') {
                    $this->anonymous();
                } else {
                    $this->actingAs($role);
                }
                [$status, $payload] = $this->json($method, $path, $body);
                $case = "{$label} [{$method} {$path}] as {$role} -> {$status}";
                if (in_array($role, $allowedRoles, true)) {
                    $this->assertNotContains($status, [401, 403], "expected access: {$case} " . json_encode($payload));
                } else {
                    $expected = $role === 'anon' ? 401 : 403;
                    $this->assertSame($expected, $status, "expected denial: {$case}");
                }
            }
        }
    }

    /** @return array<string,mixed> ids of freshly-created fixture rows */
    private function buildFixture(): array
    {
        $category = $this->seedCategory();
        $product = $this->seedProduct(['category_id' => $category->id], 2);
        $emptyCategory = $this->seedCategory();
        $student = User::where('ldap_uid', 'student1')->first();
        $order = $this->seedOrder([
            'user_id' => $student->id,
            'status' => 'pending',
            'pickup_date' => '2026-09-20',
            'return_date' => '2026-09-21',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $terminalOrder = $this->seedOrder([
            'user_id' => $student->id,
            'status' => 'cancelled',
            'pickup_date' => '2026-09-22',
            'return_date' => '2026-09-23',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $unit = ProductUnit::where('product_id', $product->id)->first();
        // Authored by the assistant so PUT /logs/{id} ("own or T/AD") allows all staff.
        $assistant = User::where('ldap_uid', 'borsista1')->first();
        $log = ProductLog::create([
            'product_id' => $product->id, 'user_id' => $assistant->id, 'type' => 'note',
            'severity' => 'info', 'title' => 'Nota matrice', 'occurred_at' => '2026-08-01 10:00:00', 'is_public' => true,
        ]);
        static $regSeq = 0;
        $regSeq++;
        $regulation = Regulation::create([
            'slug' => 'matrix-reg-' . $regSeq . '-' . \App\Support\Str::randomHex(6),
            'title' => 'Regolamento matrice', 'scope' => 'global', 'content_type' => 'markdown',
            'body' => 'x', 'requires_acceptance' => true, 'is_active' => true,
            'version' => 1, 'published_at' => '2026-01-01 00:00:00',
        ]);
        $closure = \App\Models\Closure::create([
            'title' => 'Chiusura matrice', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02',
        ]);
        return [
            'category' => $category->id,
            'empty_category' => $emptyCategory->id,
            'product' => $product->id,
            'order' => $order->id,
            'terminal_order' => $terminalOrder->id,
            'unit' => $unit->id,
            'log' => $log->id,
            'regulation' => $regulation->id,
            'closure' => $closure->id,
            'student' => $student->id,
        ];
    }

    /**
     * @return array<string, array{0:string, 1:callable, 2:?array, 3:array<int,string>}>
     */
    private function matrix(): array
    {
        $p = static fn (string $template) => static fn (array $f) => preg_replace_callback(
            '/\{(\w+)\}/',
            static fn ($m) => (string) $f[$m[1]],
            $template
        );
        $orderBody = [
            'from_cart' => false,
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'pickup_date' => '2026-09-07', 'pickup_time' => '09:30',
            'return_date' => '2026-09-08', 'return_time' => '14:00',
            'subject' => 'Materia', 'motivation' => 'Motivazione abbastanza lunga per superare il minimo.',
        ];
        return [
            '#1 health' => ['GET', $p('/api/v1/health'), null, self::ALL],
            '#2 enums' => ['GET', $p('/api/v1/meta/enums'), null, self::ALL],
            '#5 logout' => ['POST', $p('/api/v1/auth/logout'), [], self::AUTH],
            '#6 me' => ['GET', $p('/api/v1/auth/me'), null, self::AUTH],
            '#7 patch me' => ['PATCH', $p('/api/v1/auth/me'), ['phone' => '333'], self::AUTH],
            '#8 settings public' => ['GET', $p('/api/v1/settings/public'), null, self::ALL],
            '#9 categories' => ['GET', $p('/api/v1/categories'), null, self::ALL],
            '#10 category show' => ['GET', $p('/api/v1/categories/{category}'), null, self::ALL],
            '#11 category create' => ['POST', $p('/api/v1/categories'), ['name' => 'Nuova categoria'], self::TECH_ADMIN],
            '#12 category update' => ['PUT', $p('/api/v1/categories/{category}'), ['name' => 'Rinominata'], self::TECH_ADMIN],
            '#13 category delete' => ['DELETE', $p('/api/v1/categories/{empty_category}'), null, self::TECH_ADMIN],
            '#14 products' => ['GET', $p('/api/v1/products'), null, self::ALL],
            '#15 product show' => ['GET', $p('/api/v1/products/{product}'), null, self::ALL],
            '#16 product create' => ['POST', $p('/api/v1/products'), ['name' => 'Nuovo prodotto', 'category_id' => 1], self::TECH_ADMIN],
            '#17 product update' => ['PUT', $p('/api/v1/products/{product}'), ['position' => 5], self::TECH_ADMIN],
            '#18 product delete' => ['DELETE', $p('/api/v1/products/{product}'), null, self::TECH_ADMIN],
            '#19 units list' => ['GET', $p('/api/v1/products/{product}/units'), null, self::STAFF],
            '#20 unit create' => ['POST', $p('/api/v1/products/{product}/units'), ['count' => 1], self::TECH_ADMIN],
            '#21 unit update' => ['PUT', $p('/api/v1/units/{unit}'), ['location' => 'Armadio A'], self::TECH_ADMIN],
            '#22 unit delete' => ['DELETE', $p('/api/v1/units/{unit}'), null, self::TECH_ADMIN],
            '#23 product logs' => ['GET', $p('/api/v1/products/{product}/logs'), null, self::ALL],
            '#24 log create' => ['POST', $p('/api/v1/products/{product}/logs'), ['type' => 'note', 'title' => 'Voce'], self::STAFF],
            '#25 log update' => ['PUT', $p('/api/v1/logs/{log}'), ['title' => 'Aggiornata'], self::STAFF],
            '#26 log delete' => ['DELETE', $p('/api/v1/logs/{log}'), null, self::TECH_ADMIN],
            '#27 recommended' => ['PUT', $p('/api/v1/products/{product}/recommended'), ['items' => []], self::TECH_ADMIN],
            '#28 product availability' => ['GET', $p('/api/v1/products/{product}/availability?from=2026-09-07&to=2026-09-10'), null, self::ALL],
            '#29 brands' => ['GET', $p('/api/v1/brands'), null, self::ALL],
            '#30 availability products' => ['GET', $p('/api/v1/availability/products?start_date=2026-09-07&end_date=2026-09-08'), null, self::ALL],
            '#31 availability dates' => ['POST', $p('/api/v1/availability/dates'), ['items' => [['product_id' => 1, 'quantity' => 1]]], self::ALL],
            '#32 availability check' => ['POST', $p('/api/v1/availability/check'), ['items' => [['product_id' => 1, 'quantity' => 1]]], self::AUTH],
            '#33 calendar opening' => ['GET', $p('/api/v1/calendar/opening'), null, self::ALL],
            '#34 cart' => ['GET', $p('/api/v1/cart'), null, self::STUDENT],
            '#35 cart add' => ['POST', $p('/api/v1/cart/items'), ['product_id' => 1, 'quantity' => 1], self::STUDENT],
            '#36 cart patch' => ['PATCH', $p('/api/v1/cart/items/999999'), ['quantity' => 1], self::STUDENT],
            '#37 cart delete item' => ['DELETE', $p('/api/v1/cart/items/999999'), null, self::STUDENT],
            '#38 cart dates' => ['PUT', $p('/api/v1/cart/dates'), ['pickup_date' => '2026-09-07'], self::STUDENT],
            '#39 cart clear' => ['DELETE', $p('/api/v1/cart'), null, self::STUDENT],
            '#40 checkout' => ['POST', $p('/api/v1/orders'), $orderBody, self::STUDENT],
            '#41 orders list' => ['GET', $p('/api/v1/orders'), null, self::AUTH],
            '#42 order show (own)' => ['GET', $p('/api/v1/orders/{order}'), null, self::AUTH],
            '#43 order edit' => ['PUT', $p('/api/v1/orders/{order}'), ['subject' => 'Cambiata'], self::STAFF],
            '#44 approve' => ['POST', $p('/api/v1/orders/{order}/approve'), [], self::STAFF],
            '#45 reject' => ['POST', $p('/api/v1/orders/{order}/reject'), ['reason' => 'Non disponibile.'], self::STAFF],
            '#46 cancel (owner student1 or staff)' => ['POST', $p('/api/v1/orders/{order}/cancel'), [], array_merge(self::STUDENT, self::STAFF)],
            '#47 pickup' => ['POST', $p('/api/v1/orders/{order}/pickup'), [], self::STAFF],
            '#48 return' => ['POST', $p('/api/v1/orders/{order}/return'), [], self::STAFF],
            '#49 no-show' => ['POST', $p('/api/v1/orders/{order}/no-show'), [], self::STAFF],
            '#50 reopen' => ['POST', $p('/api/v1/orders/{terminal_order}/reopen'), ['to_status' => 'pending', 'reason' => 'x'], self::ADMIN],
            '#51 notes' => ['POST', $p('/api/v1/orders/{order}/notes'), ['staff_notes' => 'Nota interna'], self::STAFF],
            '#52 events (own)' => ['GET', $p('/api/v1/orders/{order}/events'), null, self::AUTH],
            '#53 orders calendar' => ['GET', $p('/api/v1/orders/calendar?from=2026-09-01&to=2026-09-30'), null, self::STAFF],
            '#54 regulations list' => ['GET', $p('/api/v1/regulations'), null, self::ALL],
            '#55 regulation show' => ['GET', $p('/api/v1/regulations/{regulation}'), null, self::ALL],
            '#56 regulation file' => ['GET', $p('/api/v1/regulations/{regulation}/file'), null, self::ALL],
            '#57 regulation create' => ['POST', $p('/api/v1/regulations'), ['title' => 'Nuovo', 'scope' => 'global'], self::TECH_ADMIN],
            '#58 regulation update' => ['PUT', $p('/api/v1/regulations/{regulation}'), ['summary' => 'agg.'], self::TECH_ADMIN],
            '#60 regulation publish' => ['POST', $p('/api/v1/regulations/{regulation}/publish'), [], self::TECH_ADMIN],
            '#61 regulation delete' => ['DELETE', $p('/api/v1/regulations/{regulation}'), null, self::ADMIN],
            '#62 acceptances' => ['GET', $p('/api/v1/regulations/{regulation}/acceptances'), null, self::STAFF],
            '#63 pending mine' => ['GET', $p('/api/v1/me/regulations/pending'), null, self::AUTH],
            '#64 accept' => ['POST', $p('/api/v1/me/regulations/{regulation}/accept'), ['version' => 1], self::AUTH],
            '#65 settings' => ['GET', $p('/api/v1/settings'), null, self::STAFF],
            '#66 settings bulk' => ['PUT', $p('/api/v1/settings'), ['settings' => ['booking.max_loan_days' => 7]], self::ADMIN],
            '#67 setting one' => ['PUT', $p('/api/v1/settings/booking.max_loan_days'), ['value' => 7], self::ADMIN],
            '#68 ldap test' => ['POST', $p('/api/v1/settings/ldap/test'), [], self::ADMIN],
            '#69 closures' => ['GET', $p('/api/v1/closures'), null, self::ALL],
            '#70 closure create' => ['POST', $p('/api/v1/closures'), ['title' => 'Nuova', 'start_date' => '2026-12-10', 'end_date' => '2026-12-11'], self::TECH_ADMIN],
            '#71 closure update' => ['PUT', $p('/api/v1/closures/{closure}'), ['title' => 'Rinominata'], self::TECH_ADMIN],
            '#72 closure delete' => ['DELETE', $p('/api/v1/closures/{closure}'), null, self::TECH_ADMIN],
            '#73 users' => ['GET', $p('/api/v1/users'), null, self::STAFF],
            '#74 user show' => ['GET', $p('/api/v1/users/{student}'), null, self::STAFF],
            '#75 user update' => ['PUT', $p('/api/v1/users/{student}'), ['notes' => 'ok'], self::ADMIN],
            '#76 user orders' => ['GET', $p('/api/v1/users/{student}/orders'), null, self::STAFF],
            '#77 stats overview' => ['GET', $p('/api/v1/stats/overview'), null, self::STAFF],
            '#78 stats loans-over-time' => ['GET', $p('/api/v1/stats/loans-over-time'), null, self::TECH_ADMIN],
            '#79 stats top-products' => ['GET', $p('/api/v1/stats/top-products'), null, self::TECH_ADMIN],
            '#80 stats by-category' => ['GET', $p('/api/v1/stats/by-category'), null, self::TECH_ADMIN],
            '#81 stats late-returns' => ['GET', $p('/api/v1/stats/late-returns'), null, self::STAFF],
            '#82 stats utilization' => ['GET', $p('/api/v1/stats/utilization'), null, self::TECH_ADMIN],
            '#83 stats my-activity' => ['GET', $p('/api/v1/stats/my-activity'), null, self::STAFF],
            '#84 stats export' => ['GET', $p('/api/v1/stats/export?dataset=orders'), null, self::TECH_ADMIN],
            '#85 audit logs' => ['GET', $p('/api/v1/audit-logs'), null, self::ADMIN],
            '#86 logs feed' => ['GET', $p('/api/v1/logs'), null, self::STAFF],
        ];
    }
}
