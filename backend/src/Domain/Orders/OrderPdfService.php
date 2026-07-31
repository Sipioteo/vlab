<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Settings\SettingsRepository;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Dates;
use App\Support\Enums;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Builds the printable loan form ("modulo di ritiro/riconsegna") for an order.
 *
 * Responsibilities are deliberately split in two:
 *  - THIS class assembles the data (settings, order, user, items) into a plain
 *    array and drives dompdf;
 *  - the LAYOUT lives entirely in `backend/templates/order_form.php`, which is
 *    the single file to swap when the lab provides its own template. The array
 *    passed to the template is documented in {@see self::data()} and in the
 *    template header.
 */
final class OrderPdfService
{
    /**
     * Statuses from which the form makes sense: the order has been confirmed
     * by the lab at least once (SPEC §8.1). Earlier/refused states have nothing
     * to sign.
     */
    public const PRINTABLE_STATUSES = [
        'approved', 'picked_up', 'overdue', 'returned', 'returned_late', 'no_show',
    ];

    private string $templatePath;

    public function __construct(private SettingsRepository $settings, ?string $templatePath = null)
    {
        $this->templatePath = $templatePath ?? dirname(__DIR__, 3) . '/templates/order_form.php';
    }

    public static function isPrintable(Order $order): bool
    {
        return in_array((string) $order->status, self::PRINTABLE_STATUSES, true);
    }

    /** `modulo-VL-2026-0001.pdf` (falls back to the id when no code was assigned). */
    public static function filename(Order $order): string
    {
        $code = (string) ($order->code ?? '');
        if ($code === '') {
            $code = 'ordine-' . (int) $order->id;
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $code) ?? $code;
        return 'modulo-' . $safe . '.pdf';
    }

    /**
     * Everything the template may render. Keys are stable: a custom template
     * can rely on them.
     *
     * @return array<string,mixed>
     */
    public function data(Order $order): array
    {
        $tz = (string) ($this->settings->get('hours.timezone') ?? 'Europe/Rome');
        $owner = $order->user;

        $items = [];
        $position = 0;
        foreach (OrderItem::where('order_id', $order->id)->orderBy('id')->get() as $item) {
            $position++;
            $product = $item->product;
            $units = [];
            foreach ($item->assignedUnits()->orderBy('id')->get() as $assignment) {
                $label = $assignment->unit?->label;
                if ($label !== null && $label !== '') {
                    $units[] = (string) $label;
                }
            }
            $items[] = [
                'position' => $position,
                'name' => (string) ($item->product_name_snapshot ?? $product?->name ?? '—'),
                'brand' => $item->product_brand_snapshot !== null && $item->product_brand_snapshot !== ''
                    ? (string) $item->product_brand_snapshot
                    : ($product?->brand !== null && $product?->brand !== '' ? (string) $product->brand : null),
                'category' => $product?->category?->name !== null ? (string) $product->category->name : null,
                'quantity' => (int) $item->quantity,
                'units' => $units,
                'units_label' => $units === [] ? '—' : implode(' · ', $units),
                'notes' => $item->notes !== null && $item->notes !== '' ? (string) $item->notes : null,
            ];
        }

        return [
            'lab' => [
                'name' => $this->setting('lab.name', 'Visionary Lab'),
                'subtitle' => $this->setting('lab.subtitle', ''),
                'department' => $this->setting('lab.department', ''),
                'email' => $this->setting('lab.email', ''),
                'phone' => $this->setting('lab.phone', ''),
                'address' => $this->setting('lab.address', ''),
                'room' => $this->setting('lab.room', ''),
                'website_url' => $this->setting('lab.website_url', ''),
            ],
            'order' => [
                'id' => (int) $order->id,
                'code' => (string) ($order->code ?? ('#' . (int) $order->id)),
                'status' => (string) $order->status,
                'status_label' => Enums::ORDER_STATUS_LABELS[$order->status] ?? (string) $order->status,
                'subject' => $this->text($order->subject),
                'professor' => $this->text($order->professor),
                'motivation' => $this->text($order->motivation),
                'notes' => $this->text($order->notes),
                'pickup_date' => self::itDate(Dates::datePart($order->pickup_date)),
                'pickup_time' => $this->text($order->pickup_time),
                'return_date' => self::itDate(Dates::datePart($order->return_date)),
                'return_time' => $this->text($order->return_time),
                'submitted_at' => self::itDateTime($order->submitted_at, $tz),
                'picked_up_at' => self::itDateTime($order->picked_up_at, $tz),
                'returned_at' => self::itDateTime($order->returned_at, $tz),
                'items_count' => (int) $order->items_count,
                'distinct_products' => count($items),
            ],
            'user' => [
                'display_name' => $owner?->displayName() ?? '—',
                'username' => $owner?->ldap_uid !== null ? (string) $owner->ldap_uid : '—',
                'email' => $this->text($owner?->email),
                'matricola' => $this->text($owner?->matricola),
                'course' => $this->text($owner?->course),
                'phone' => $this->text($owner?->phone),
            ],
            'items' => $items,
            'generated_at' => self::itDateTime(Dates::nowDb(), $tz),
        ];
    }

    /** The rendered HTML, before dompdf. Handy for tests and for template work. */
    public function html(Order $order): string
    {
        $data = $this->data($order);
        /** @var callable(mixed):string $h escaping helper available inside the template */
        $h = static fn ($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require $this->templatePath;
        return (string) ob_get_clean();
    }

    /** Raw PDF bytes (A4 portrait, core fonts only — no network access). */
    public function render(Order $order): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'Helvetica');
        $options->set('defaultMediaType', 'print');
        $options->set('defaultPaperSize', 'A4');
        $options->set('defaultPaperOrientation', 'portrait');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($order), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    // ---------------------------------------------------------------- utils

    private function setting(string $key, string $default): string
    {
        $value = $this->settings->get($key);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }
        return trim($value);
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    /** `2026-08-01` → `01/08/2026`. */
    private static function itDate(?string $date): ?string
    {
        if ($date === null || !Dates::isValidDate($date)) {
            return null;
        }
        [$y, $m, $d] = explode('-', $date);
        return $d . '/' . $m . '/' . $y;
    }

    /** Stored UTC datetime → `01/08/2026 09:30` in the lab timezone. */
    private static function itDateTime(mixed $value, string $tz): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $dt = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)
                : new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone($tz))->format('d/m/Y H:i');
    }
}
