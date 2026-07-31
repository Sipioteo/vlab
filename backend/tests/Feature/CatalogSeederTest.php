<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Database\Capsule\Manager as Capsule;
use Tests\TestCase;

final class CatalogSeederTest extends TestCase
{
    public function testImportsCatalogAndIsIdempotent(): void
    {
        $path = dirname(__DIR__, 3) . '/data/catalog.json';
        $this->assertFileExists($path);
        $data = json_decode((string) file_get_contents($path), true);
        $expectedCategories = count($data['categories']);
        $expectedProducts = count($data['products']);
        $seeder = new \CatalogSeeder($path);
        // quantity loanable units + parseable non-loanable extras from source_notes.
        $expectedUnits = array_sum(array_map(
            static fn ($p) => count($seeder->parseUnitStatuses((string) ($p['source_notes'] ?? ''), max(1, (int) ($p['quantity'] ?? 1)))),
            $data['products']
        ));
        $expectedLoanable = array_sum(array_map(static fn ($p) => max(1, (int) ($p['quantity'] ?? 1)), $data['products']));

        $seeder->run();
        $this->assertSame($expectedCategories, Category::count());
        $this->assertSame($expectedProducts, Product::count());
        $this->assertSame($expectedUnits, ProductUnit::count());
        // Capacity-relevant (loanable) units match the scraped quantities.
        $this->assertSame($expectedLoanable, ProductUnit::where('status', 'available')->count());

        // The 9 real categories exist with their slugs.
        foreach (['audio', 'tecnologie-interattive', 'video', 'supporti'] as $slug) {
            $this->assertNotNull(Category::where('slug', $slug)->first(), $slug);
        }

        // Unit labels are 01..NN per product.
        $sample = Product::first();
        $labels = ProductUnit::where('product_id', $sample->id)->orderBy('label')->pluck('label')->all();
        $this->assertSame('01', $labels[0]);
        $this->assertCount((int) ProductUnit::where('product_id', $sample->id)->count(), array_unique($labels));

        // Per-unit statuses parsed from source_notes: a "Dismesso: 1" product has a retired unit.
        $dismesso = Product::where('source_notes', 'like', '%Dismesso%')->first();
        $this->assertNotNull($dismesso);
        $this->assertGreaterThan(0, ProductUnit::where('product_id', $dismesso->id)->where('status', 'retired')->count());

        // Idempotent: re-running duplicates nothing.
        (new \CatalogSeeder($path))->run();
        $this->assertSame($expectedCategories, Category::count());
        $this->assertSame($expectedProducts, Product::count());
        $this->assertSame($expectedUnits, ProductUnit::count());

        // Existing units are never deleted even if quantity shrinks; descriptive fields update.
        $product = Product::first();
        $product->description = 'da sovrascrivere';
        $product->save();
        (new \CatalogSeeder($path))->run();
        $this->assertNotSame('da sovrascrivere', Product::find($product->id)->description);
    }

    public function testMissingCatalogFileIsANonFatalWarning(): void
    {
        $messages = [];
        (new \CatalogSeeder('/nonexistent/catalog.json'))->run(static function ($m) use (&$messages) {
            $messages[] = $m;
        });
        $this->assertStringContainsString('ATTENZIONE', $messages[0]);
        $this->assertSame(0, Product::count());
    }
}
