<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Regulations\RegulationService;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Dates;
use App\Support\Enums;
use App\Support\OrderTimes;

final class OrderResource
{
    /** @return array<string,mixed> */
    public static function summary(Order $order, ?User $viewer = null): array
    {
        $user = $order->user;
        $pickupDate = Dates::datePart($order->pickup_date);
        $returnDate = Dates::datePart($order->return_date);
        return [
            'id' => (int) $order->id,
            'code' => $order->code,
            'status' => $order->status,
            'status_label' => Enums::ORDER_STATUS_LABELS[$order->status] ?? $order->status,
            'user' => $user !== null ? UserResource::mini($user) : null,
            'pickup_date' => $pickupDate,
            'pickup_time' => $order->pickup_time,
            'pickup_time_end' => $order->pickup_time_end,
            'return_date' => $returnDate,
            'return_time' => $order->return_time,
            'return_time_end' => $order->return_time_end,
            // Computed display strings (SPEC v1.4 §7.4): the frontend never
            // recomputes settings math. NULL times = the weekday's lab window.
            'pickup_window' => OrderTimes::display($pickupDate, $order->pickup_time, $order->pickup_time_end, 'pickup'),
            'return_window' => OrderTimes::display($returnDate, $order->return_time, $order->return_time_end, 'return'),
            'items_count' => (int) $order->items_count,
            'distinct_products' => (int) OrderItem::where('order_id', $order->id)->count(),
            'exceeds_limits' => (bool) $order->exceeds_limits,
            'is_late' => in_array($order->status, ['overdue', 'returned_late'], true),
            'late_days' => $order->late_days !== null ? (int) $order->late_days : null,
            'submitted_at' => Dates::iso($order->submitted_at),
            'created_at' => Dates::iso($order->created_at),
        ];
    }

    /** @return array<string,mixed> */
    public static function detail(
        Order $order,
        User $viewer,
        OrderStateMachine $machine,
        RegulationService $regulations
    ): array {
        $out = self::summary($order, $viewer);
        $isStaff = $viewer->isStaff();

        $items = [];
        foreach (OrderItem::where('order_id', $order->id)->orderBy('id')->get() as $item) {
            $items[] = self::item($item, $isStaff);
        }

        $events = [];
        foreach ($order->events()->orderBy('created_at')->orderBy('id')->get() as $event) {
            $events[] = OrderEventResource::toArray($event);
        }

        $owner = $order->user;
        $requiredRegs = [];
        if ($owner !== null) {
            $normalized = [];
            foreach (OrderItem::where('order_id', $order->id)->get() as $item) {
                $normalized[] = ['product_id' => (int) $item->product_id, 'product' => $item->product];
            }
            foreach ($regulations->requiredForItems($normalized) as $reg) {
                $acceptance = $regulations->acceptance($owner, $reg);
                $requiredRegs[] = [
                    'id' => (int) $reg->id,
                    'slug' => $reg->slug,
                    'title' => $reg->title,
                    'version' => (int) $reg->version,
                    'accepted' => $acceptance !== null,
                    'accepted_at' => $acceptance !== null ? Dates::iso($acceptance->accepted_at) : null,
                ];
            }
        }

        $miniUser = static function ($userId) {
            if ($userId === null) {
                return null;
            }
            $u = User::find($userId);
            return $u !== null ? ['id' => (int) $u->id, 'display_name' => $u->displayName()] : null;
        };

        $out += [
            'subject' => $order->subject,
            'motivation' => $order->motivation,
            'professor' => $order->professor,
            'notes' => $order->notes,
            'rejection_reason' => $order->rejection_reason,
            'limit_violations' => $order->limitViolationsArray(),
            'picked_up_at' => Dates::iso($order->picked_up_at),
            'returned_at' => Dates::iso($order->returned_at),
            'decided_by' => $miniUser($order->decided_by),
            'decided_at' => Dates::iso($order->decided_at),
            'handed_over_by' => $miniUser($order->handed_over_by),
            'received_by' => $miniUser($order->received_by),
            'cancelled_at' => Dates::iso($order->cancelled_at),
            'items' => $items,
            'events' => $events,
            'required_regulations' => $requiredRegs,
            'allowed_actions' => $machine->allowedActions($order, $viewer),
            'updated_at' => Dates::iso($order->updated_at),
        ];
        if ($isStaff) {
            $out['staff_notes'] = $order->staff_notes;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function item(OrderItem $item, bool $staffView): array
    {
        $product = $item->product;
        $out = [
            'id' => (int) $item->id,
            'product_id' => (int) $item->product_id,
            'quantity' => (int) $item->quantity,
            'notes' => $item->notes,
            'returned_quantity' => (int) $item->returned_quantity,
            'product' => $product !== null ? ProductResource::summary($product) : null,
            'product_name_snapshot' => $item->product_name_snapshot,
            'product_brand_snapshot' => $item->product_brand_snapshot,
        ];
        if ($staffView) {
            $assigned = [];
            foreach ($item->assignedUnits()->orderBy('id')->get() as $assignment) {
                $assigned[] = [
                    'id' => (int) $assignment->id,
                    'product_unit_id' => (int) $assignment->product_unit_id,
                    'unit_label' => $assignment->unit?->label,
                    'assigned_at' => Dates::iso($assignment->assigned_at),
                    'returned_at' => Dates::iso($assignment->returned_at),
                    'condition_out' => $assignment->condition_out,
                    'condition_in' => $assignment->condition_in,
                    'note' => $assignment->note,
                ];
            }
            $out['assigned_units'] = $assigned;
        }
        return $out;
    }
}
