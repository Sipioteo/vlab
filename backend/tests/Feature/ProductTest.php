<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductLog;
use App\Models\ProductUnit;
use Tests\TestCase;

final class ProductTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
    }

    public function testCreateWithInitialUnitsGeneratesLabelledUnits(): void
    {
        $category = $this->seedCategory();
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/products', [
            'name' => 'Visore VR Meta Quest 3 128GB',
            'category_id' => $category->id,
            'brand' => 'Meta',
            'initial_units' => 5,
        ]);
        $this->assertSame(201, $status, json_encode($payload));
        $this->assertSame('visore-vr-meta-quest-3-128gb', $payload['slug']);
        $this->assertSame(5, $payload['units_total']);
        $labels = array_map(static fn ($u) => $u['label'], $payload['units']);
        $this->assertSame(['01', '02', '03', '04', '05'], $labels);
    }

    public function testCreateWithExplicitUnitsHonoursDetails(): void
    {
        $category = $this->seedCategory();
        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', '/api/v1/products', [
            'name' => 'Registratore Zoom H6',
            'category_id' => $category->id,
            'initial_units' => 99,
            'units' => [
                ['label' => '01', 'serial_number' => 'ZH6-1', 'asset_code' => 'INV-1', 'purchase_date' => '2024-03-15', 'inspection_date' => '2026-01-20'],
                ['label' => '02', 'serial_number' => 'ZH6-2', 'status' => 'maintenance'],
            ],
        ]);
        $this->assertSame(201, $status);
        // Explicit units array wins over initial_units.
        $this->assertCount(2, $payload['units']);
        $this->assertSame('ZH6-1', $payload['units'][0]['serial_number']);
        $this->assertSame('INV-1', $payload['units'][0]['asset_code']);
        $this->assertSame('2024-03-15', $payload['units'][0]['purchase_date']);
        $this->assertSame('maintenance', $payload['units'][1]['status']);
        $this->assertSame('In manutenzione', $payload['units'][1]['status_label']);
    }

    public function testUpdateImagesReplacesCollectionAndSyncsPrimary(): void
    {
        $product = $this->seedProduct();
        ProductImage::create(['product_id' => $product->id, 'url' => 'https://img/old.jpg', 'position' => 0]);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('PUT', "/api/v1/products/{$product->id}", [
            'images' => [
                ['url' => 'https://img/new-b.jpg', 'position' => 1],
                ['url' => 'https://img/new-a.jpg', 'alt' => 'Fronte', 'position' => 0],
            ],
        ]);
        $this->assertSame(200, $status);
        $this->assertSame('https://img/new-a.jpg', $payload['image_url']);
        $this->assertCount(2, $payload['images']);
        $this->assertSame(0, $payload['images'][0]['position']);
        $this->assertSame(1, ProductImage::where('product_id', $product->id)->where('url', 'https://img/new-a.jpg')->count());
        $this->assertSame(0, ProductImage::where('product_id', $product->id)->where('url', 'https://img/old.jpg')->count());
    }

    public function testDeleteRefusedWhileLockingOrderReferencesIt(): void
    {
        $product = $this->seedProduct();
        $this->seedOrder([
            'status' => 'approved',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('DELETE', "/api/v1/products/{$product->id}");
        $this->assertSame(409, $status);
        $this->assertErrorEnvelope($payload, 'conflict');
        $this->assertNotEmpty($payload['error']['details']['order_ids']);
        // After the order terminates, delete succeeds.
        \App\Models\Order::query()->update(['status' => 'returned']);
        [$status] = $this->json('DELETE', "/api/v1/products/{$product->id}");
        $this->assertSame(204, $status);
        $this->assertNull(Product::find($product->id));
    }

    public function testCategoryDeleteRefusedWhenNotEmpty(): void
    {
        $category = $this->seedCategory();
        $this->seedProduct(['category_id' => $category->id]);
        $this->actingAs('technician');
        [$status, $payload] = $this->json('DELETE', "/api/v1/categories/{$category->id}");
        $this->assertSame(409, $status);
        $this->assertErrorEnvelope($payload, 'category_not_empty');
        $empty = $this->seedCategory();
        [$status] = $this->json('DELETE', "/api/v1/categories/{$empty->id}");
        $this->assertSame(204, $status);
    }

    public function testUnitDeleteRefusedWhileAssignedToActiveOrder(): void
    {
        $product = $this->seedProduct([], 2);
        $order = $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => '2026-09-02',
            'return_date' => '2026-09-04',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->actingAs('technician');
        [, $picked] = $this->json('POST', "/api/v1/orders/{$order->id}/pickup", []);
        $unitId = $picked['items'][0]['assigned_units'][0]['product_unit_id'];
        [$status, $payload] = $this->json('DELETE', "/api/v1/units/{$unitId}");
        $this->assertSame(409, $status);
        $this->assertErrorEnvelope($payload, 'unit_in_use');
        // A free unit can be deleted.
        $freeUnit = ProductUnit::where('product_id', $product->id)->where('id', '!=', $unitId)->first();
        [$status] = $this->json('DELETE', "/api/v1/units/{$freeUnit->id}");
        $this->assertSame(204, $status);
    }

    public function testRecommendedProductsSelfAndReplacement(): void
    {
        $product = $this->seedProduct();
        $accessory = $this->seedProduct();
        $alternative = $this->seedProduct();
        $this->actingAs('technician');
        [$status, $payload] = $this->json('PUT', "/api/v1/products/{$product->id}/recommended", [
            'items' => [['product_id' => $product->id, 'relation' => 'accessory']],
        ]);
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'self_recommendation');

        [$status, $payload] = $this->json('PUT', "/api/v1/products/{$product->id}/recommended", [
            'items' => [['product_id' => $accessory->id, 'relation' => 'accessory', 'position' => 0]],
        ]);
        $this->assertSame(200, $status);
        $this->assertCount(1, $payload['data']);
        $this->assertSame($accessory->id, $payload['data'][0]['product']['id']);

        // Replacement semantics.
        [$status, $payload] = $this->json('PUT', "/api/v1/products/{$product->id}/recommended", [
            'items' => [['product_id' => $alternative->id, 'relation' => 'alternative']],
        ]);
        $this->assertSame(200, $status);
        $this->assertCount(1, $payload['data']);
        $this->assertSame($alternative->id, $payload['data'][0]['product']['id']);
        $this->assertSame('alternative', $payload['data'][0]['relation']);
    }

    public function testStudentVisibilityOfUnitsAndLogs(): void
    {
        $product = $this->seedProduct([], 2);
        $unit = ProductUnit::where('product_id', $product->id)->first();
        $unit->serial_number = 'SN-SECRET';
        $unit->asset_code = 'INV-SECRET';
        $unit->location = 'Armadio B';
        $unit->save();
        $staff = \App\Models\User::where('ldap_uid', 'tecnico1')->first();
        ProductLog::create([
            'product_id' => $product->id, 'user_id' => $staff->id, 'type' => 'note',
            'severity' => 'info', 'title' => 'Nota pubblica', 'occurred_at' => '2026-08-01 10:00:00', 'is_public' => true,
        ]);
        ProductLog::create([
            'product_id' => $product->id, 'user_id' => $staff->id, 'type' => 'note',
            'severity' => 'info', 'title' => 'Nota privata', 'occurred_at' => '2026-08-02 10:00:00', 'is_public' => false,
        ]);

        // Student: no units key, only public logs with user null.
        $this->actingAs('student');
        [, $payload] = $this->json('GET', "/api/v1/products/{$product->id}");
        $this->assertArrayNotHasKey('units', $payload);
        $titles = array_map(static fn ($l) => $l['title'], $payload['recent_logs']);
        $this->assertContains('Nota pubblica', $titles);
        $this->assertNotContains('Nota privata', $titles);
        $this->assertNull($payload['recent_logs'][0]['user']);
        $raw = json_encode($payload);
        $this->assertStringNotContainsString('SN-SECRET', $raw);
        $this->assertStringNotContainsString('INV-SECRET', $raw);
        $this->assertStringNotContainsString('Armadio B', $raw);

        // ui.show_unit_codes_to_students=true -> reduced {id,label,status} only.
        $this->setSetting('ui.show_unit_codes_to_students', true);
        [, $payload] = $this->json('GET', "/api/v1/products/{$product->id}");
        $this->assertArrayHasKey('units', $payload);
        $this->assertSame(['id', 'label', 'status'], array_keys($payload['units'][0]));

        // Staff: full units + private logs with user.
        $this->actingAs('technician');
        [, $payload] = $this->json('GET', "/api/v1/products/{$product->id}");
        $this->assertSame('SN-SECRET', $payload['units'][0]['serial_number']);
        $titles = array_map(static fn ($l) => $l['title'], $payload['recent_logs']);
        $this->assertContains('Nota privata', $titles);
        $this->assertSame('Luca Ferrero', $payload['recent_logs'][0]['user']['display_name']);
    }

    public function testAnonymousCatalogGate(): void
    {
        $this->seedProduct();
        [$status] = $this->json('GET', '/api/v1/products');
        $this->assertSame(200, $status);
        $this->setSetting('ui.allow_anonymous_catalog', false);
        [$status, $payload] = $this->json('GET', '/api/v1/products');
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($payload, 'unauthenticated');
        [$status] = $this->json('GET', '/api/v1/categories');
        $this->assertSame(401, $status);
        // Authenticated users still pass.
        $this->actingAs('student');
        [$status] = $this->json('GET', '/api/v1/products');
        $this->assertSame(200, $status);
    }

    public function testAssistantCanLogButNotManageCatalog(): void
    {
        $product = $this->seedProduct();
        $this->actingAs('assistant');
        [$status, $payload] = $this->json('POST', "/api/v1/products/{$product->id}/logs", [
            'type' => 'damage', 'severity' => 'warning', 'title' => 'Graffio evidente',
        ]);
        $this->assertSame(201, $status, json_encode($payload));
        [$status] = $this->json('POST', '/api/v1/products', ['name' => 'Nuovo', 'category_id' => 1]);
        $this->assertSame(403, $status);
        [$status] = $this->json('PUT', "/api/v1/products/{$product->id}", ['name' => 'Rinominato']);
        $this->assertSame(403, $status);
    }

    public function testCriticalDamageLogFlipsUnitStatus(): void
    {
        $product = $this->seedProduct([], 2);
        $unit = ProductUnit::where('product_id', $product->id)->orderBy('label')->first();
        $this->actingAs('technician');
        [$status] = $this->json('POST', "/api/v1/products/{$product->id}/logs", [
            'product_unit_id' => $unit->id,
            'type' => 'damage',
            'severity' => 'critical',
            'title' => 'Connettore rotto',
        ]);
        $this->assertSame(201, $status);
        $this->assertSame('maintenance', $unit->refresh()->status);
        // loss -> missing
        $unit2 = ProductUnit::where('product_id', $product->id)->orderBy('label', 'desc')->first();
        $this->json('POST', "/api/v1/products/{$product->id}/logs", [
            'product_unit_id' => $unit2->id,
            'type' => 'loss',
            'severity' => 'critical',
            'title' => 'Smarrito',
        ]);
        $this->assertSame('missing', $unit2->refresh()->status);
    }

    public function testProductListFiltersFacetsAndSort(): void
    {
        $catA = $this->seedCategory(['name' => 'AAA Categoria']);
        $catB = $this->seedCategory(['name' => 'BBB Categoria']);
        $this->seedProduct(['category_id' => $catA->id, 'brand' => 'Rode', 'name' => 'Alfa'], 1);
        $this->seedProduct(['category_id' => $catA->id, 'brand' => 'Sony', 'name' => 'Beta'], 2);
        $this->seedProduct(['category_id' => $catB->id, 'brand' => 'Rode', 'name' => 'Gamma'], 3);
        $retired = $this->seedProduct(['category_id' => $catB->id, 'brand' => 'Sony', 'name' => 'Delta', 'status' => 'retired'], 1);

        [, $payload] = $this->json('GET', "/api/v1/products?category_id={$catA->id}&sort=name");
        $this->assertSame(['Alfa', 'Beta'], array_map(static fn ($p) => $p['name'], $payload['data']));
        // Facets: categories ignore the category filter; brands reflect it.
        $catFacetIds = array_map(static fn ($c) => $c['id'], $payload['filters']['categories']);
        $this->assertContains($catB->id, $catFacetIds);
        $brandNames = array_map(static fn ($b) => $b['name'], $payload['filters']['brands']);
        $this->assertSame(['Rode', 'Sony'], $brandNames);

        // Students never see retired.
        [, $payload] = $this->json('GET', '/api/v1/products?per_page=100');
        $names = array_map(static fn ($p) => $p['name'], $payload['data']);
        $this->assertNotContains('Delta', $names);
        [$status] = $this->json('GET', "/api/v1/products/{$retired->id}");
        $this->assertSame(404, $status);
        // Staff can.
        $this->actingAs('technician');
        [$status] = $this->json('GET', "/api/v1/products/{$retired->id}");
        $this->assertSame(200, $status);

        // Invalid sort -> 422 invalid_sort.
        $this->anonymous();
        [$status, $payload] = $this->json('GET', '/api/v1/products?sort=price');
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'invalid_sort');
    }
}
