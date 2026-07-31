<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSubstitute;
use App\Models\ProductUnit;
use App\Support\Str;

/**
 * Imports data/catalog.json (SPEC §15.1). Idempotent: upserts categories by
 * slug and products by slug; adds missing units up to `quantity` but never
 * deletes units. Per-unit statuses are parsed from source_notes when possible.
 */
final class CatalogSeeder
{
    private const CATEGORY_ICONS = [
        'audio' => 'audio',
        'audio-accessori-e-cavi' => 'cable',
        'hardware-e-software' => 'hardware',
        'luci-accessori-fondali' => 'light',
        'materiale-elettrico' => 'electric',
        'supporti' => 'support',
        'tecnologie-interattive' => 'vr',
        'video' => 'video',
        'video-accessori-e-cavi' => 'cable',
    ];

    /**
     * Same-category substitute relations among seeded products, by exact
     * catalog name: product name => ordered list of substitute names
     * (list order = priority 1, 2, 3…). Directional; idempotent (upsert on
     * the unique pair).
     */
    private const SUBSTITUTES = [
        // Luci LED
        'Kit Luci LED Aputure H528KIT-WWS ELET' => [
            'Kit Luci LED Aputure HR672KIT-WWS BAT',
            'Luci Yongnuo YN300 III | Pro LED Video Light | 3200K - 5500K',
        ],
        'Kit Luci LED Aputure HR672KIT-WWS BAT' => [
            'Kit Luci LED Aputure H528KIT-WWS ELET',
            'Luci Yongnuo YN300 III | Pro LED Video Light | 3200K - 5500K',
        ],
        // Treppiedi video
        'Treppiede Manfrotto MVB525-519' => [
            'Treppiede Manfrotto MVT502AM-Kit 502',
            'Treppiede Coman KX 3939',
        ],
        'Treppiede Manfrotto MVT502AM-Kit 502' => [
            'Treppiede Manfrotto MVB525-519',
            'Treppiede Coman KX 3939',
        ],
        'Treppiede Coman KX 3939' => [
            'Treppiede Manfrotto MVB525-519',
            'Treppiede Manfrotto MVT502AM-Kit 502',
        ],
        // Microfoni direzionali
        'Microfono Mezzo Fucile Rode NTG4' => [
            'Microfono per Videocamera Rode VideoMic Pro',
        ],
        // Sistemi audio wireless (Rode ↔ Sennheiser)
        'Microfono Rode Wireless Pro' => [
            'Microfono Rode Trasmettitore + Ricevitore Audio Wireless RodeLink',
            'ENG Set Sennheiser ew 100 G3-C',
        ],
        'Microfono Rode Trasmettitore + Ricevitore Audio Wireless RodeLink' => [
            'Microfono Rode Wireless Pro',
            'ENG Set Sennheiser ew 100 G3-C',
        ],
        // Action cam / 360 kit
        'Fotocamera GoPro Max | Kit' => [
            'Fotocamera Insta360 X5 | Kit',
        ],
        'Fotocamera Insta360 X5 | Kit' => [
            'Fotocamera GoPro Max | Kit',
        ],
        // Registratori digitali
        'Registratore Digitale Portatile ZOOM H5 Handy Recorder' => [
            'Registratore Digitale Zoom F6',
            'Registratore PCM Lineare Tascam DR-701D',
            'Registratore Digitale Edirol by Roland R-44',
        ],
        'Registratore Digitale Zoom F6' => [
            'Registratore Digitale Portatile ZOOM H5 Handy Recorder',
            'Registratore PCM Lineare Tascam DR-701D',
        ],
        // Visori VR
        'Visore Oculus Meta Quest 3 512gb' => [
            'Visore Oculus Meta Quest 3 512gb + Cinturino Elite',
            'Visore Oculus Quest All in one 64GB',
        ],
        // Aste microfono
        'Asta telescopica Rode Micro Boompole' => [
            'Asta telescopica Rode Micro Boompole V2',
        ],
        'Asta telescopica Rode Micro Boompole V2' => [
            'Asta telescopica Rode Micro Boompole',
        ],
    ];

    /** Legacy status strings → unit_status enum. */
    private const STATUS_MAP = [
        'prestabile studenti' => 'available',
        'prestabile' => 'available',
        'prestato' => 'available',   // occupancy comes from orders, not unit status
        'in prestito' => 'available',
        'in uso' => 'internal_use',
        'mancante' => 'missing',
        'dismesso' => 'retired',
        'in manutenzione' => 'maintenance',
    ];

    public function __construct(private ?string $catalogPath = null)
    {
    }

    public function run(?callable $out = null): void
    {
        $path = $this->catalogPath ?? dirname(__DIR__, 3) . '/data/catalog.json';
        if (!is_file($path)) {
            if ($out !== null) {
                $out("ATTENZIONE: catalogo non trovato in {$path} — importazione saltata.");
            }
            return;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            if ($out !== null) {
                $out('ATTENZIONE: catalog.json non è JSON valido — importazione saltata.');
            }
            return;
        }

        $newCategories = 0;
        $totalCategories = 0;
        foreach ((array) ($data['categories'] ?? []) as $i => $cat) {
            if (!isset($cat['slug'], $cat['name'])) {
                continue;
            }
            $totalCategories++;
            $existing = Category::withTrashed()->where('slug', (string) $cat['slug'])->first();
            $position = isset($cat['position']) ? (int) $cat['position'] * 10 : ($i + 1) * 10;
            if ($existing === null) {
                Category::create([
                    'slug' => (string) $cat['slug'],
                    'name' => (string) $cat['name'],
                    'icon' => self::CATEGORY_ICONS[(string) $cat['slug']] ?? null,
                    'position' => $position,
                    'is_active' => true,
                ]);
                $newCategories++;
            } else {
                $existing->name = (string) $cat['name'];
                $existing->position = $position;
                if ($existing->icon === null) {
                    $existing->icon = self::CATEGORY_ICONS[(string) $cat['slug']] ?? null;
                }
                $existing->save();
            }
        }

        $categoriesBySlug = Category::withTrashed()->get()->keyBy('slug');
        $newProducts = 0;
        $totalProducts = 0;
        $newUnits = 0;
        $totalUnits = 0;

        foreach ((array) ($data['products'] ?? []) as $entry) {
            if (!isset($entry['name'])) {
                continue;
            }
            $totalProducts++;
            $categorySlug = (string) ($entry['category_slug'] ?? '');
            $category = $categoriesBySlug->get($categorySlug);
            if ($category === null) {
                $category = Category::create([
                    'slug' => $categorySlug !== '' ? $categorySlug : Str::slug('senza-categoria'),
                    'name' => $categorySlug !== '' ? ucwords(str_replace('-', ' ', $categorySlug)) : 'Senza categoria',
                    'position' => 999,
                    'is_active' => true,
                ]);
                $categoriesBySlug->put($category->slug, $category);
                if ($out !== null) {
                    $out("ATTENZIONE: categoria sconosciuta '{$categorySlug}' creata al volo.");
                }
            }

            $name = (string) $entry['name'];
            $baseSlug = Str::slug($name);
            $product = Product::withTrashed()->where('slug', $baseSlug)->first();
            if ($product === null) {
                // Try uniquified variants for legacy duplicates.
                $slug = Str::uniqueSlug($baseSlug, static fn ($s) => Product::withTrashed()->where('slug', $s)->exists());
                $product = Product::create([
                    'slug' => $slug,
                    'name' => $name,
                    'category_id' => $category->id,
                    'brand' => $entry['brand'] ?? null,
                    'model' => $entry['model'] ?? null,
                    'description' => $entry['description'] ?? null,
                    'image_url' => $this->validUrl($entry['image_url'] ?? null),
                    'source_notes' => $entry['source_notes'] ?? null,
                    'status' => 'available',
                    'loan_mode' => 'takeaway',
                ]);
                $newProducts++;
            } else {
                $product->name = $name;
                $product->category_id = $category->id;
                $product->brand = $entry['brand'] ?? $product->brand;
                $product->model = $entry['model'] ?? $product->model;
                $product->description = $entry['description'] ?? $product->description;
                $product->image_url = $this->validUrl($entry['image_url'] ?? null) ?? $product->image_url;
                $product->source_notes = $entry['source_notes'] ?? $product->source_notes;
                $product->save();
            }

            if ($product->image_url !== null && !ProductImage::where('product_id', $product->id)->exists()) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'url' => $product->image_url,
                    'alt' => $name,
                    'position' => 0,
                ]);
            }

            $quantity = (int) ($entry['quantity'] ?? 1);
            if ($quantity <= 0) {
                $quantity = 1;
            }
            // `quantity` counts the LOANABLE units; extra non-loanable units
            // (Dismesso/Mancante/In Uso) parsed from source_notes are appended
            // after them so per-unit history is preserved.
            $statuses = $this->parseUnitStatuses((string) ($entry['source_notes'] ?? ''), $quantity);
            $target = count($statuses);
            $existingUnits = (int) ProductUnit::withTrashed()->where('product_id', $product->id)->count();
            $totalUnits += max($target, $existingUnits);
            for ($i = $existingUnits + 1; $i <= $target; $i++) {
                ProductUnit::create([
                    'product_id' => $product->id,
                    'label' => sprintf('%02d', $i),
                    'status' => $statuses[$i - 1] ?? 'available',
                ]);
                $newUnits++;
            }
        }

        $substituteRelations = $this->seedSubstitutes();

        if ($out !== null) {
            $out("Categorie: {$totalCategories} ({$newCategories} nuove) · Prodotti: {$totalProducts} ({$newProducts} nuovi) · Unità: {$totalUnits} ({$newUnits} nuove) · Sostitutive: {$substituteRelations}");
        }
    }

    /**
     * Idempotently upserts the SUBSTITUTES relations (unique on the pair,
     * priority refreshed). Missing products are skipped silently. Returns the
     * number of relations applied.
     */
    private function seedSubstitutes(): int
    {
        $names = [];
        foreach (self::SUBSTITUTES as $name => $subs) {
            $names[] = $name;
            foreach ($subs as $sub) {
                $names[] = $sub;
            }
        }
        $products = Product::whereIn('name', array_values(array_unique($names)))->get()->keyBy('name');
        $applied = 0;
        foreach (self::SUBSTITUTES as $name => $subs) {
            $product = $products->get($name);
            if ($product === null) {
                continue;
            }
            foreach (array_values($subs) as $i => $subName) {
                $substitute = $products->get($subName);
                if ($substitute === null || (int) $substitute->id === (int) $product->id) {
                    continue;
                }
                ProductSubstitute::updateOrCreate(
                    ['product_id' => $product->id, 'substitute_product_id' => $substitute->id],
                    ['priority' => $i + 1]
                );
                $applied++;
            }
        }
        return $applied;
    }

    /**
     * Parse "Stato unità: Prestabile Studenti: 4, Dismesso: 1; …" into an
     * ordered status list: exactly $quantity loanable ('available') units
     * first, then any parseable non-loanable extras. Falls back to
     * $quantity × 'available' when nothing is parseable.
     *
     * @return string[] length >= $quantity
     */
    public function parseUnitStatuses(string $sourceNotes, int $quantity): array
    {
        $extras = [];
        if (preg_match('/Stato unit[àa]:\s*([^;]+)/iu', $sourceNotes, $m) === 1) {
            $parts = explode(',', $m[1]);
            foreach ($parts as $part) {
                if (preg_match('/^\s*(.+?):\s*(\d+)\s*$/u', $part, $pm) !== 1) {
                    continue;
                }
                $label = mb_strtolower(trim($pm[1]));
                $count = (int) $pm[2];
                $status = self::STATUS_MAP[$label] ?? null;
                if ($status === null || $status === 'available') {
                    continue; // loanable units are represented by `quantity`
                }
                for ($i = 0; $i < $count; $i++) {
                    $extras[] = $status;
                }
            }
        }
        return array_merge(array_fill(0, $quantity, 'available'), $extras);
    }

    private function validUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        return preg_match('#^(https?://|/)#i', $url) === 1 ? $url : null;
    }
}
