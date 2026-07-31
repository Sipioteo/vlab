<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Auth\JwtService;
use App\Domain\Settings\SettingsRepository;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Support\Dates;
use App\Support\Migrator;
use App\Support\Str;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Boots the app with a fresh in-memory SQLite DB + migrations + SettingsSeeder
 * per test (SPEC §13.1).
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected App $app;

    protected ?string $token = null;

    protected ?User $currentUser = null;

    protected function setUp(): void
    {
        parent::setUp();
        Dates::travelTo(null);
        SettingsRepository::reset();
        $this->app = vlab_create_app();
        (new Migrator())->migrate();
        (new \SettingsSeeder())->run();
        (new \FakeUsersSeeder(['app' => ['env' => 'test']]))->run();
        SettingsRepository::reset();
        $this->clearRateLimits();
        $this->token = null;
        $this->currentUser = null;
    }

    protected function tearDown(): void
    {
        Dates::travelTo(null);
        $this->clearRateLimits();
        parent::tearDown();
    }

    private function clearRateLimits(): void
    {
        $dir = dirname(__DIR__) . '/storage/test/ratelimit';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    // ------------------------------------------------------------ helpers ---

    protected function container(): \Psr\Container\ContainerInterface
    {
        return $this->app->getContainer();
    }

    protected function travelTo(string $datetime): void
    {
        Dates::travelTo($datetime);
    }

    protected function setSetting(string $key, mixed $value): void
    {
        SettingsRepository::instance()->set($key, $value, null);
    }

    /**
     * Authenticate the subsequent json() calls as a user or a bare role name.
     * Role names map to the seeded fake users.
     */
    protected function actingAs(User|string $roleOrUser): User
    {
        if (is_string($roleOrUser)) {
            $uidByRole = [
                'student' => 'student1',
                'technician' => 'tecnico1',
                'assistant' => 'borsista1',
                'admin' => 'admin1',
            ];
            $uid = $uidByRole[$roleOrUser] ?? $roleOrUser;
            $user = User::where('ldap_uid', $uid)->first();
            if ($user === null) {
                $user = User::create([
                    'ldap_uid' => $uid,
                    'role' => in_array($roleOrUser, ['student', 'technician', 'assistant', 'admin'], true) ? $roleOrUser : 'student',
                    'display_name' => ucfirst($uid),
                    'is_active' => true,
                    'token_version' => 1,
                ]);
            }
        } else {
            $user = $roleOrUser;
        }
        $jwt = $this->container()->get(JwtService::class);
        $this->token = $jwt->issueAccessToken($user)['token'];
        $this->currentUser = $user;
        return $user;
    }

    protected function anonymous(): void
    {
        $this->token = null;
        $this->currentUser = null;
    }

    /**
     * Perform an in-process request. Returns [status, decoded array|null, ResponseInterface].
     *
     * @return array{0:int, 1:mixed, 2:\Psr\Http\Message\ResponseInterface}
     */
    protected function json(string $method, string $uri, ?array $body = null, array $headers = []): array
    {
        $parts = parse_url($uri);
        $path = $parts['path'] ?? $uri;
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest(strtoupper($method), $path, ['REMOTE_ADDR' => '127.0.0.1']);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            $request = $request->withQueryParams($queryParams)->withUri($request->getUri()->withQuery($parts['query']));
        }
        $request = $request->withHeader('Accept', 'application/json');
        if ($body !== null) {
            $stream = (new StreamFactory())->createStream((string) json_encode($body, JSON_UNESCAPED_UNICODE));
            $request = $request->withBody($stream)->withHeader('Content-Type', 'application/json');
        }
        if ($this->token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->token);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $response = $this->app->handle($request);
        $raw = (string) $response->getBody();
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return [$response->getStatusCode(), $decoded, $response];
    }

    /** Assert the SPEC §7.3 error envelope shape. */
    protected function assertErrorEnvelope(mixed $payload, ?string $code = null): void
    {
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('error', $payload);
        $this->assertArrayHasKey('code', $payload['error']);
        $this->assertArrayHasKey('message', $payload['error']);
        $this->assertArrayHasKey('details', $payload['error']);
        $this->assertArrayHasKey('trace_id', $payload['error']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $payload['error']['trace_id']);
        if ($code !== null) {
            $this->assertSame($code, $payload['error']['code']);
        }
    }

    // ------------------------------------------------------------ fixtures --

    protected function seedCategory(array $overrides = []): Category
    {
        static $i = 0;
        $i++;
        return Category::create($overrides + [
            'slug' => 'test-category-' . $i . '-' . Str::randomHex(4),
            'name' => 'Categoria di test ' . $i,
            'position' => 10 * $i,
            'is_active' => true,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    protected function seedProduct(array $overrides = [], int $units = 3): Product
    {
        static $i = 0;
        $i++;
        $categoryId = $overrides['category_id'] ?? $this->seedCategory()->id;
        unset($overrides['category_id']);
        $product = Product::create($overrides + [
            'category_id' => $categoryId,
            'slug' => 'test-product-' . $i . '-' . Str::randomHex(4),
            'name' => 'Prodotto di test ' . $i,
            'brand' => 'TestBrand',
            'status' => 'available',
            'loan_mode' => 'takeaway',
        ]);
        for ($u = 1; $u <= $units; $u++) {
            ProductUnit::create([
                'product_id' => $product->id,
                'label' => sprintf('%02d', $u),
                'status' => 'available',
            ]);
        }
        return $product;
    }

    /**
     * Seed an order. Overrides accept order columns plus:
     * - 'items' => [[product_id, quantity]] (default: one seeded product, qty 1)
     *
     * @param array<string,mixed> $overrides
     */
    protected function seedOrder(array $overrides = []): Order
    {
        $items = $overrides['items'] ?? null;
        unset($overrides['items']);
        if (!isset($overrides['user_id'])) {
            $overrides['user_id'] = User::where('ldap_uid', 'student1')->first()->id;
        }
        $today = Dates::todayInTz('Europe/Rome');
        $order = Order::create($overrides + [
            'status' => 'pending',
            'pickup_date' => Dates::addDays($today, 3),
            'pickup_time' => '09:30',
            'return_date' => Dates::addDays($today, 5),
            'return_time' => '14:00',
            'subject' => 'Materia di test',
            'motivation' => 'Motivazione di test sufficientemente lunga.',
            'submitted_at' => Dates::nowDb(),
            'code' => null,
        ]);
        if ($order->status !== 'draft' && $order->code === null) {
            $year = substr((string) $order->pickup_date, 0, 4);
            $seq = (int) Order::where('code', 'like', "VL-{$year}-%")->max('year_sequence') + 1;
            $order->code = sprintf('VL-%s-%04d', $year, $seq);
            $order->year_sequence = $seq;
            $order->save();
        }
        if ($items === null) {
            $product = $this->seedProduct();
            $items = [['product_id' => $product->id, 'quantity' => 1]];
        }
        foreach ($items as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'] ?? 1,
            ]);
        }
        $order->items_count = (int) OrderItem::where('order_id', $order->id)->sum('quantity');
        $order->save();
        return $order->refresh();
    }

    /** A pickup date guaranteed bookable (next open weekday within the window). */
    protected function bookableDate(int $offsetOpenDays = 0): string
    {
        $calendar = $this->container()->get(\App\Domain\Calendar\CalendarService::class);
        $window = $calendar->bookingWindow();
        $d = $window['min_date'];
        $found = 0;
        while ($d <= $window['max_date']) {
            if ($calendar->canPickup($d) && $calendar->canReturn($d)) {
                if ($found === $offsetOpenDays) {
                    return $d;
                }
                $found++;
            }
            $d = Dates::addDays($d, 1);
        }
        $this->fail('Nessuna data prenotabile trovata.');
    }
}
