<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSubstitute;
use Tests\TestCase;

/**
 * Substitute products: staff CRUD, availability suggestions (non-recursive by
 * construction) and the atomic cart swap.
 */
final class SubstituteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
    }

    /** Seeds an approved order that consumes $quantity units of $product over the range. */
    private function block(Product $product, int $quantity = 1, string $from = '2026-09-07', string $to = '2026-09-09'): void
    {
        $this->seedOrder([
            'status' => 'approved',
            'pickup_date' => $from,
            'return_date' => $to,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ]);
    }

    /** @return array<string,mixed> the availability entry for $productId */
    private function checkEntry(array $payload, int $productId): array
    {
        foreach ($payload['availability'] as $entry) {
            if ($entry['product_id'] === $productId) {
                return $entry;
            }
        }
        $this->fail("Nessuna voce availability per il prodotto {$productId}.");
    }

    // ------------------------------------------------------------- staff CRUD

    public function testReplaceSubstitutesOrderingAndReplacementSemantics(): void
    {
        $category = $this->seedCategory();
        $product = $this->seedProduct(['category_id' => $category->id]);
        $subA = $this->seedProduct(['category_id' => $category->id]);
        $subB = $this->seedProduct(['category_id' => $category->id]);

        $this->actingAs('technician');
        // Priorities out of order in the payload: response is priority-ordered.
        [$status, $payload] = $this->json('PUT', "/api/v1/products/{$product->id}/substitutes", [
            'items' => [
                ['product_id' => $subB->id, 'priority' => 2],
                ['product_id' => $subA->id, 'priority' => 1],
            ],
        ]);
        $this->assertSame(200, $status, json_encode($payload));
        $this->assertCount(2, $payload['data']);
        $this->assertSame($subA->id, $payload['data'][0]['product']['id']);
        $this->assertSame(1, $payload['data'][0]['priority']);
        $this->assertSame($subB->id, $payload['data'][1]['product']['id']);

        // Replacement semantics: the new set fully replaces the old one.
        [$status, $payload] = $this->json('PUT', "/api/v1/products/{$product->id}/substitutes", [
            'items' => [['product_id' => $subB->id, 'priority' => 1]],
        ]);
        $this->assertSame(200, $status);
        $this->assertCount(1, $payload['data']);
        $this->assertSame($subB->id, $payload['data'][0]['product']['id']);
        $this->assertSame(1, ProductSubstitute::where('product_id', $product->id)->count());

        // The relation is directional: nothing was created on subB.
        $this->assertSame(0, ProductSubstitute::where('product_id', $subB->id)->count());
    }

    public function testSelfSubstitutionRejected(): void
    {
        $product = $this->seedProduct();
        $this->actingAs('technician');
        [$status, $payload] = $this->json('PUT', "/api/v1/products/{$product->id}/substitutes", [
            'items' => [['product_id' => $product->id, 'priority' => 1]],
        ]);
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'self_substitution');
        $this->assertSame(0, ProductSubstitute::where('product_id', $product->id)->count());
    }

    public function testSubstitutesEndpointPermissions(): void
    {
        $product = $this->seedProduct();
        $sub = $this->seedProduct();
        $body = ['items' => [['product_id' => $sub->id, 'priority' => 1]]];

        // Borsista (assistant): 403 like the rest of catalog management.
        $this->actingAs('assistant');
        [$status] = $this->json('PUT', "/api/v1/products/{$product->id}/substitutes", $body);
        $this->assertSame(403, $status);

        $this->actingAs('student');
        [$status] = $this->json('PUT', "/api/v1/products/{$product->id}/substitutes", $body);
        $this->assertSame(403, $status);

        $this->actingAs('admin');
        [$status] = $this->json('PUT', "/api/v1/products/{$product->id}/substitutes", $body);
        $this->assertSame(200, $status);
    }

    public function testProductDetailListsSubstitutesByPriority(): void
    {
        $category = $this->seedCategory();
        $product = $this->seedProduct(['category_id' => $category->id]);
        $subA = $this->seedProduct(['category_id' => $category->id]);
        $subB = $this->seedProduct(['category_id' => $category->id]);
        ProductSubstitute::create(['product_id' => $product->id, 'substitute_product_id' => $subB->id, 'priority' => 2]);
        ProductSubstitute::create(['product_id' => $product->id, 'substitute_product_id' => $subA->id, 'priority' => 1]);

        [$status, $payload] = $this->json('GET', "/api/v1/products/{$product->id}");
        $this->assertSame(200, $status);
        $this->assertArrayHasKey('substitutes', $payload);
        $this->assertSame(
            [$subA->id, $subB->id],
            array_map(static fn ($s) => $s['product']['id'], $payload['substitutes'])
        );
        $this->assertSame([1, 2], array_map(static fn ($s) => $s['priority'], $payload['substitutes']));
        $this->assertSame($subA->slug, $payload['substitutes'][0]['product']['slug']);
    }

    // -------------------------------------------------- availability check ---

    public function testSuggestedSubstitutesOnlyForUnavailableItems(): void
    {
        $category = $this->seedCategory();
        $wanted = $this->seedProduct(['category_id' => $category->id], 1);
        $free = $this->seedProduct(['category_id' => $category->id], 3);
        $subOk = $this->seedProduct(['category_id' => $category->id], 2);
        $subBusy = $this->seedProduct(['category_id' => $category->id], 1);
        ProductSubstitute::create(['product_id' => $wanted->id, 'substitute_product_id' => $subBusy->id, 'priority' => 1]);
        ProductSubstitute::create(['product_id' => $wanted->id, 'substitute_product_id' => $subOk->id, 'priority' => 2]);
        $this->block($wanted);
        $this->block($subBusy);

        $this->actingAs('student');
        [$status, $payload] = $this->json('POST', '/api/v1/availability/check', [
            'items' => [
                ['product_id' => $wanted->id, 'quantity' => 1],
                ['product_id' => $free->id, 'quantity' => 1],
            ],
            'pickup_date' => '2026-09-07',
            'return_date' => '2026-09-09',
        ]);
        $this->assertSame(200, $status, json_encode($payload));

        // Unavailable item: suggestions present, busy substitute filtered out.
        $entry = $this->checkEntry($payload, $wanted->id);
        $this->assertFalse($entry['sufficient']);
        $this->assertArrayHasKey('suggested_substitutes', $entry);
        $this->assertCount(1, $entry['suggested_substitutes']);
        $suggestion = $entry['suggested_substitutes'][0];
        $this->assertSame($subOk->id, $suggestion['product_id']);
        $this->assertSame($subOk->name, $suggestion['name']);
        $this->assertSame($subOk->slug, $suggestion['slug']);
        $this->assertSame(2, $suggestion['available_quantity']);
        $this->assertSame(2, $suggestion['priority']);

        // Available item: no suggested_substitutes key at all.
        $freeEntry = $this->checkEntry($payload, $free->id);
        $this->assertTrue($freeEntry['sufficient']);
        $this->assertArrayNotHasKey('suggested_substitutes', $freeEntry);
    }

    public function testSuggestedSubstitutesPriorityOrderAndCapAtThree(): void
    {
        $category = $this->seedCategory();
        $wanted = $this->seedProduct(['category_id' => $category->id], 1);
        $this->block($wanted);
        $subs = [];
        foreach ([4, 2, 1, 3] as $priority) {
            $sub = $this->seedProduct(['category_id' => $category->id], 2);
            ProductSubstitute::create(['product_id' => $wanted->id, 'substitute_product_id' => $sub->id, 'priority' => $priority]);
            $subs[$priority] = $sub->id;
        }

        $this->actingAs('student');
        [, $payload] = $this->json('POST', '/api/v1/availability/check', [
            'items' => [['product_id' => $wanted->id, 'quantity' => 1]],
            'pickup_date' => '2026-09-07',
            'return_date' => '2026-09-09',
        ]);
        $entry = $this->checkEntry($payload, $wanted->id);
        // Capped at 3, ordered by priority: 1, 2, 3 — priority 4 dropped.
        $this->assertSame(
            [$subs[1], $subs[2], $subs[3]],
            array_map(static fn ($s) => $s['product_id'], $entry['suggested_substitutes'])
        );
    }

    /**
     * NON-RECURSION: X→Y and Y→Z. With X and Y both unavailable, suggestions
     * for X contain neither Y (unavailable) nor Z (not a DIRECT substitute of
     * X — reachable only through Y's own list, which must never be traversed).
     * Once X→Z exists as a DIRECT entry, Z may appear.
     */
    public function testSuggestionsAreNotRecursive(): void
    {
        $category = $this->seedCategory();
        $x = $this->seedProduct(['category_id' => $category->id], 1);
        $y = $this->seedProduct(['category_id' => $category->id], 1);
        $z = $this->seedProduct(['category_id' => $category->id], 3);
        ProductSubstitute::create(['product_id' => $x->id, 'substitute_product_id' => $y->id, 'priority' => 1]);
        ProductSubstitute::create(['product_id' => $y->id, 'substitute_product_id' => $z->id, 'priority' => 1]);
        $this->block($x);
        $this->block($y);

        $this->actingAs('student');
        $request = [
            'items' => [['product_id' => $x->id, 'quantity' => 1]],
            'pickup_date' => '2026-09-07',
            'return_date' => '2026-09-09',
        ];
        [, $payload] = $this->json('POST', '/api/v1/availability/check', $request);
        $entry = $this->checkEntry($payload, $x->id);
        $suggestedIds = array_map(static fn ($s) => $s['product_id'], $entry['suggested_substitutes']);
        // Y is a direct substitute but unavailable; Z is available but NOT a
        // direct substitute of X: neither may appear.
        $this->assertNotContains($y->id, $suggestedIds);
        $this->assertNotContains($z->id, $suggestedIds);
        $this->assertSame([], $suggestedIds);

        // The distinction: with a DIRECT X→Z entry, Z is suggested.
        ProductSubstitute::create(['product_id' => $x->id, 'substitute_product_id' => $z->id, 'priority' => 2]);
        [, $payload] = $this->json('POST', '/api/v1/availability/check', $request);
        $entry = $this->checkEntry($payload, $x->id);
        $suggestedIds = array_map(static fn ($s) => $s['product_id'], $entry['suggested_substitutes']);
        $this->assertSame([$z->id], $suggestedIds);
        $this->assertNotContains($y->id, $suggestedIds);
    }

    // ------------------------------------------------------------- cart flow

    public function testCartCheckCarriesSuggestionsAndSwapReplacesItem(): void
    {
        $category = $this->seedCategory();
        $wanted = $this->seedProduct(['category_id' => $category->id], 1);
        $sub = $this->seedProduct(['category_id' => $category->id], 2);
        $unrelated = $this->seedProduct(['category_id' => $category->id], 2);
        ProductSubstitute::create(['product_id' => $wanted->id, 'substitute_product_id' => $sub->id, 'priority' => 1]);
        $this->block($wanted);

        $this->actingAs('student');
        [, $cart] = $this->json('POST', '/api/v1/cart/items', ['product_id' => $wanted->id, 'quantity' => 1]);
        [$status, $cart] = $this->json('PUT', '/api/v1/cart/dates', [
            'pickup_date' => '2026-09-07',
            'return_date' => '2026-09-09',
        ]);
        $this->assertSame(200, $status);
        $item = $cart['items'][0];
        $this->assertFalse($item['sufficient']);
        $entry = $this->checkEntry($cart['check'], $wanted->id);
        $this->assertSame($sub->id, $entry['suggested_substitutes'][0]['product_id']);

        // Swap with a product that is NOT a configured substitute: 422.
        [$status, $payload] = $this->json('POST', "/api/v1/cart/items/{$item['id']}/swap", [
            'product_id' => $unrelated->id,
        ]);
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'not_a_substitute');

        // Swap with the configured substitute: atomic replacement, 200 = full cart.
        [$status, $cart] = $this->json('POST', "/api/v1/cart/items/{$item['id']}/swap", [
            'product_id' => $sub->id,
        ]);
        $this->assertSame(200, $status, json_encode($cart));
        $this->assertCount(1, $cart['items']);
        $this->assertSame($sub->id, $cart['items'][0]['product_id']);
        $this->assertSame(1, $cart['items'][0]['quantity']);
        $this->assertTrue($cart['items'][0]['sufficient']);
        $this->assertSame(1, $cart['items_count']);
        // The old product is gone from the cart.
        $this->assertSame(0, OrderItem::where('order_id', $cart['id'])->where('product_id', $wanted->id)->count());
    }

    public function testSwapMergesIntoExistingRowForTheSameProduct(): void
    {
        $category = $this->seedCategory();
        $wanted = $this->seedProduct(['category_id' => $category->id], 1);
        $sub = $this->seedProduct(['category_id' => $category->id], 3);
        ProductSubstitute::create(['product_id' => $wanted->id, 'substitute_product_id' => $sub->id, 'priority' => 1]);

        $this->actingAs('student');
        $this->json('POST', '/api/v1/cart/items', ['product_id' => $wanted->id, 'quantity' => 1]);
        [, $cart] = $this->json('POST', '/api/v1/cart/items', ['product_id' => $sub->id, 'quantity' => 1]);
        $wantedItem = null;
        foreach ($cart['items'] as $it) {
            if ($it['product_id'] === $wanted->id) {
                $wantedItem = $it;
            }
        }
        $this->assertNotNull($wantedItem);

        [$status, $cart] = $this->json('POST', "/api/v1/cart/items/{$wantedItem['id']}/swap", [
            'product_id' => $sub->id,
        ]);
        $this->assertSame(200, $status, json_encode($cart));
        // One merged row (unique order+product), quantities summed.
        $this->assertCount(1, $cart['items']);
        $this->assertSame($sub->id, $cart['items'][0]['product_id']);
        $this->assertSame(2, $cart['items'][0]['quantity']);
        $this->assertSame(2, $cart['items_count']);
    }

    // ---------------------------------------------------------------- seeder

    public function testCatalogSeederSeedsSubstitutesIdempotently(): void
    {
        $path = dirname(__DIR__, 3) . '/data/catalog.json';
        $this->assertFileExists($path);
        (new \CatalogSeeder($path))->run();
        $count = ProductSubstitute::count();
        $this->assertGreaterThanOrEqual(10, $count);

        // Same-category only, never self-referencing, priorities >= 1.
        foreach (ProductSubstitute::all() as $rel) {
            $this->assertNotSame($rel->product_id, $rel->substitute_product_id);
            $this->assertGreaterThanOrEqual(1, $rel->priority);
            $a = Product::find($rel->product_id);
            $b = Product::find($rel->substitute_product_id);
            $this->assertSame($a->category_id, $b->category_id, "{$a->name} → {$b->name}");
        }

        // Re-seeding duplicates nothing.
        (new \CatalogSeeder($path))->run();
        $this->assertSame($count, ProductSubstitute::count());
    }
}
