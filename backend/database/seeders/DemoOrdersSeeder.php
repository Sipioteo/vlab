<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\OrderItemUnit;
use App\Models\Product;
use App\Models\ProductLog;
use App\Models\ProductUnit;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Dev-only demo orders (SPEC §15.6): ~25 orders across every status over the
 * last 120 days, plus events, some unit assignments and 6 product logs.
 * Run via `bin/console seed --demo`. Idempotent (skips when demo orders exist).
 */
final class DemoOrdersSeeder
{
    public function run(?callable $out = null): void
    {
        if (Order::where('subject', 'like', 'Demo:%')->exists()) {
            if ($out !== null) {
                $out('DemoOrdersSeeder: dati demo già presenti, saltato.');
            }
            return;
        }
        $students = User::whereIn('ldap_uid', ['student1', 'student2'])->get()->all();
        $staff = User::where('ldap_uid', 'tecnico1')->first();
        $products = Product::where('status', 'available')->orderBy('id')->limit(12)->get()->all();
        if ($students === [] || $staff === null || count($products) < 3) {
            if ($out !== null) {
                $out('DemoOrdersSeeder: seed base mancante (utenti/prodotti), saltato.');
            }
            return;
        }

        $today = Dates::todayInTz('Europe/Rome');
        $plan = [
            // [days_ago_submitted, status, duration]
            [115, 'returned', 3], [110, 'returned', 2], [104, 'returned_late', 5],
            [98, 'returned', 4], [92, 'rejected', 3], [88, 'returned', 2],
            [82, 'cancelled', 3], [76, 'returned', 6], [70, 'returned', 3],
            [64, 'returned_late', 4], [58, 'no_show', 2], [52, 'returned', 3],
            [46, 'returned', 2], [40, 'returned', 5], [34, 'cancelled', 2],
            [30, 'returned', 3], [26, 'returned', 4], [22, 'rejected', 2],
            [18, 'returned', 3], [14, 'returned', 2], [10, 'overdue', 3],
            [6, 'picked_up', 5], [3, 'pending', 3], [2, 'approved', 4], [1, 'pending', 2],
        ];

        $year = (int) substr($today, 0, 4);
        $seq = (int) Capsule::table('orders')->where('code', 'like', "VL-{$year}-%")->max('year_sequence');
        $created = 0;

        foreach ($plan as $i => [$daysAgo, $status, $duration]) {
            $student = $students[$i % count($students)];
            $submitted = Dates::addDays($today, -$daysAgo);
            $pickup = Dates::addDays($submitted, 2);
            $return = Dates::addDays($pickup, $duration - 1);
            if ($status === 'approved' || $status === 'pending') {
                $pickup = Dates::addDays($today, 3 + ($i % 3));
                $return = Dates::addDays($pickup, $duration - 1);
            }
            if ($status === 'picked_up') {
                $pickup = Dates::addDays($today, -2);
                $return = Dates::addDays($today, 2);
            }
            if ($status === 'overdue') {
                $pickup = Dates::addDays($today, -8);
                $return = Dates::addDays($today, -4);
            }
            $seq++;
            $order = Order::create([
                'code' => sprintf('VL-%d-%04d', $year, $seq),
                'year_sequence' => $seq,
                'user_id' => $student->id,
                'status' => $status,
                'pickup_date' => $pickup,
                'pickup_time' => '09:30',
                'return_date' => $return,
                'return_time' => '14:00',
                'subject' => 'Demo: Laboratorio di Ripresa e Montaggio',
                'motivation' => 'Riprese di esercitazione per il corso (dati dimostrativi).',
                'submitted_at' => $submitted . ' 10:00:00',
            ]);
            $product = $products[$i % count($products)];
            $product2 = $products[($i + 5) % count($products)];
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'product_name_snapshot' => $product->name,
                'product_brand_snapshot' => $product->brand,
            ]);
            if ($i % 3 === 0 && $product2->id !== $product->id) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product2->id,
                    'quantity' => 1,
                    'product_name_snapshot' => $product2->name,
                    'product_brand_snapshot' => $product2->brand,
                ]);
            }
            $order->items_count = (int) OrderItem::where('order_id', $order->id)->sum('quantity');

            OrderEvent::create([
                'order_id' => $order->id, 'from_status' => 'draft', 'to_status' => 'pending',
                'action' => 'submit', 'actor_id' => $student->id, 'actor_type' => 'user', 'actor_role' => 'student',
                'created_at' => $submitted . ' 10:00:00',
            ]);
            if (in_array($status, ['approved', 'picked_up', 'overdue', 'returned', 'returned_late', 'no_show'], true)) {
                $order->decided_by = $staff->id;
                $order->decided_at = $submitted . ' 15:00:00';
                OrderEvent::create([
                    'order_id' => $order->id, 'from_status' => 'pending', 'to_status' => 'approved',
                    'action' => 'approve', 'actor_id' => $staff->id, 'actor_type' => 'user', 'actor_role' => 'technician',
                    'created_at' => $submitted . ' 15:00:00',
                ]);
            }
            if ($status === 'rejected') {
                $order->decided_by = $staff->id;
                $order->decided_at = $submitted . ' 15:00:00';
                $order->rejection_reason = 'Attrezzatura già impegnata nel periodo richiesto.';
                OrderEvent::create([
                    'order_id' => $order->id, 'from_status' => 'pending', 'to_status' => 'rejected',
                    'action' => 'reject', 'actor_id' => $staff->id, 'actor_type' => 'user', 'actor_role' => 'technician',
                ]);
            }
            if ($status === 'cancelled') {
                $order->cancelled_by = $student->id;
                $order->cancelled_at = $submitted . ' 18:00:00';
                OrderEvent::create([
                    'order_id' => $order->id, 'from_status' => 'pending', 'to_status' => 'cancelled',
                    'action' => 'cancel', 'actor_id' => $student->id, 'actor_type' => 'user', 'actor_role' => 'student',
                ]);
            }
            if ($status === 'no_show') {
                OrderEvent::create([
                    'order_id' => $order->id, 'from_status' => 'approved', 'to_status' => 'no_show',
                    'action' => 'mark_no_show', 'actor_id' => null, 'actor_type' => 'system',
                ]);
            }
            if (in_array($status, ['picked_up', 'overdue', 'returned', 'returned_late'], true)) {
                $order->picked_up_at = $pickup . ' 09:35:00';
                $order->handed_over_by = $staff->id;
                OrderEvent::create([
                    'order_id' => $order->id, 'from_status' => 'approved', 'to_status' => 'picked_up',
                    'action' => 'pickup', 'actor_id' => $staff->id, 'actor_type' => 'user', 'actor_role' => 'technician',
                ]);
                $unit = ProductUnit::where('product_id', $product->id)->orderBy('label')->first();
                if ($unit !== null) {
                    $item = OrderItem::where('order_id', $order->id)->where('product_id', $product->id)->first();
                    OrderItemUnit::create([
                        'order_item_id' => $item->id,
                        'product_unit_id' => $unit->id,
                        'assigned_at' => $pickup . ' 09:35:00',
                        'returned_at' => in_array($status, ['returned', 'returned_late'], true) ? $return . ' 14:00:00' : null,
                        'condition_out' => 'ok',
                        'condition_in' => in_array($status, ['returned', 'returned_late'], true) ? 'ok' : null,
                    ]);
                }
            }
            if ($status === 'overdue') {
                OrderEvent::create([
                    'order_id' => $order->id, 'from_status' => 'picked_up', 'to_status' => 'overdue',
                    'action' => 'mark_overdue', 'actor_id' => null, 'actor_type' => 'system',
                ]);
            }
            if (in_array($status, ['returned', 'returned_late'], true)) {
                $late = $status === 'returned_late' ? 2 : 0;
                $returnedAt = Dates::addDays($return, $late) . ' 14:10:00';
                $order->returned_at = $returnedAt;
                $order->received_by = $staff->id;
                $order->late_days = $late > 0 ? $late : null;
                foreach (OrderItem::where('order_id', $order->id)->get() as $item) {
                    $item->returned_quantity = (int) $item->quantity;
                    $item->save();
                }
                OrderEvent::create([
                    'order_id' => $order->id, 'from_status' => $late > 0 ? 'overdue' : 'picked_up', 'to_status' => $status,
                    'action' => 'return', 'actor_id' => $staff->id, 'actor_type' => 'user', 'actor_role' => 'technician',
                    'meta' => $late > 0 ? json_encode(['late_days' => $late]) : null,
                ]);
            }
            $order->save();
            $created++;
        }

        // 6 product logs.
        $logTypes = [
            ['damage', 'warning', 'Graffio sulla scocca'],
            ['maintenance', 'info', 'Pulizia e controllo generale'],
            ['inspection', 'info', 'Collaudo periodico superato'],
            ['note', 'info', 'Aggiornamento firmware eseguito'],
            ['repair', 'info', 'Sostituito cavo di alimentazione'],
            ['damage', 'critical', 'Connettore danneggiato'],
        ];
        foreach ($logTypes as $i => [$type, $severity, $title]) {
            $product = $products[$i % count($products)];
            ProductLog::create([
                'product_id' => $product->id,
                'product_unit_id' => ProductUnit::where('product_id', $product->id)->orderBy('label')->first()?->id,
                'user_id' => $staff->id,
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'body' => 'Voce dimostrativa generata dal seeder demo.',
                'occurred_at' => Dates::addDays($today, -($i * 9 + 4)) . ' 11:00:00',
                'is_public' => $i % 2 === 0,
            ]);
        }

        if ($out !== null) {
            $out("Ordini demo: {$created} creati, 6 voci di registro.");
        }
    }
}
