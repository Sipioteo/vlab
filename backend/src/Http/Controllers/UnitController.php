<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProductUnitResource;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Support\ApiException;
use App\Support\AuditLogger;
use App\Support\Enums;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UnitController extends Controller
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $product = Product::find((int) $args['id']);
        if ($product === null) {
            throw ApiException::notFound('Prodotto non trovato.');
        }
        $units = ProductUnit::where('product_id', $product->id)->orderBy('label')->get();
        return $this->json($response, [
            'data' => array_map(static fn ($u) => ProductUnitResource::toArray($u), $units->all()),
            'meta' => null,
        ]);
    }

    public function store(Request $request, Response $response, array $args): Response
    {
        $product = Product::find((int) $args['id']);
        if ($product === null) {
            throw ApiException::notFound('Prodotto non trovato.');
        }
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $created = [];
        $nextIndex = (int) ProductUnit::withTrashed()->where('product_id', $product->id)->count() + 1;
        if (isset($body['count'])) {
            $count = max(1, (int) $body['count']);
            for ($i = 0; $i < $count; $i++) {
                $created[] = $this->create($product, ['label' => $this->freeLabel($product, $nextIndex + $i)], $user->id);
            }
        } else {
            if (!isset($body['label']) || $body['label'] === null || $body['label'] === '') {
                $body['label'] = $this->freeLabel($product, $nextIndex);
            }
            $created[] = $this->create($product, $body, $user->id);
        }
        AuditLogger::log($user, 'unit.create', 'Product', (string) $product->id, ['after' => ['count' => count($created)]]);
        return $this->json($response, [
            'data' => array_map(static fn ($u) => ProductUnitResource::toArray($u), $created),
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $unit = ProductUnit::find((int) $args['unitId']);
        if ($unit === null) {
            throw ApiException::notFound('Unità non trovata.');
        }
        $user = $this->requireUser($request);
        $body = $this->body($request);
        if (array_key_exists('label', $body) && $body['label'] !== null && $body['label'] !== $unit->label) {
            if (ProductUnit::where('product_id', $unit->product_id)->where('label', (string) $body['label'])->where('id', '!=', $unit->id)->exists()) {
                throw ApiException::validation(['label' => ['Etichetta già in uso per questo prodotto.']]);
            }
            $unit->label = (string) $body['label'];
        }
        foreach (['serial_number', 'asset_code', 'purchase_date', 'inspection_date', 'next_inspection_date', 'condition_note', 'location'] as $field) {
            if (array_key_exists($field, $body)) {
                $unit->{$field} = $body[$field];
            }
        }
        if (array_key_exists('status', $body) && in_array((string) $body['status'], Enums::UNIT_STATUSES, true)) {
            $unit->status = (string) $body['status'];
        }
        $unit->updated_by = $user->id;
        $unit->save();
        AuditLogger::log($user, 'unit.update', 'ProductUnit', (string) $unit->id, null);
        return $this->json($response, ProductUnitResource::toArray($unit));
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $unit = ProductUnit::find((int) $args['unitId']);
        if ($unit === null) {
            throw ApiException::notFound('Unità non trovata.');
        }
        $inUse = Capsule::table('order_item_units')
            ->join('order_items', 'order_items.id', '=', 'order_item_units.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_item_units.product_unit_id', $unit->id)
            ->whereNotIn('orders.status', ['rejected', 'cancelled', 'returned', 'returned_late', 'no_show'])
            ->exists();
        if ($inUse) {
            throw ApiException::conflict('unit_in_use', 'L\'unità è assegnata a una richiesta attiva.');
        }
        $unit->delete();
        AuditLogger::log($this->user($request), 'unit.delete', 'ProductUnit', (string) $unit->id, null);
        return $response->withStatus(204);
    }

    private function create(Product $product, array $data, int $userId): ProductUnit
    {
        return ProductUnit::create([
            'product_id' => $product->id,
            'label' => (string) $data['label'],
            'serial_number' => $data['serial_number'] ?? null,
            'asset_code' => $data['asset_code'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'inspection_date' => $data['inspection_date'] ?? null,
            'next_inspection_date' => $data['next_inspection_date'] ?? null,
            'status' => in_array($data['status'] ?? 'available', Enums::UNIT_STATUSES, true) ? ($data['status'] ?? 'available') : 'available',
            'condition_note' => $data['condition_note'] ?? null,
            'location' => $data['location'] ?? null,
            'created_by' => $userId,
        ]);
    }

    private function freeLabel(Product $product, int $startIndex): string
    {
        $i = $startIndex;
        while (ProductUnit::withTrashed()->where('product_id', $product->id)->where('label', sprintf('%02d', $i))->exists()) {
            $i++;
        }
        return sprintf('%02d', $i);
    }
}
