<?php

declare(strict_types=1);

use App\Domain\Auth\FakeLdapAuthenticator;
use App\Domain\Auth\JwtService;
use App\Domain\Auth\LdapAuthenticatorInterface;
use App\Domain\Auth\LdapTestResult;
use App\Domain\Auth\LdapUnavailableException;
use App\Domain\Auth\LdapUser;
use App\Domain\Auth\RealLdapAuthenticator;
use App\Domain\Auth\RoleResolver;
use App\Domain\Availability\AvailabilityService;
use App\Domain\Calendar\CalendarService;
use App\Domain\Calendar\IcalService;
use App\Domain\Orders\LimitsEvaluator;
use App\Domain\Orders\OrderPdfService;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Regulations\RegulationService;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Settings\SettingsValidator;
use App\Domain\Stats\StatsService;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ClosureController;
use App\Http\Controllers\IcalController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RegulationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\AuthenticateMiddleware;
use App\Http\Middleware\CatalogGateMiddleware;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\JsonBodyParserMiddleware;
use App\Http\Middleware\RateLimitMiddleware;
use App\Http\Middleware\RequireRegulationAcceptanceMiddleware;
use App\Http\Middleware\RequireRoleMiddleware;
use DI\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

/**
 * Boot the DB connection + configuration. Shared by HTTP (index.php),
 * the console (bin/console) and the test suite.
 *
 * @return array{0: array<string,mixed>, 1: array<string,mixed>} [config, dbConfig]
 */
function vlab_boot_core(): array
{
    $root = dirname(__DIR__);
    if (class_exists(Dotenv\Dotenv::class) && is_file($root . '/.env')) {
        Dotenv\Dotenv::createImmutable($root)->safeLoad();
    }
    $config = require $root . '/config/settings.php';
    $dbConfig = require $root . '/config/database.php';

    if (($dbConfig['driver'] ?? '') === 'sqlite' && ($dbConfig['database'] ?? '') !== ':memory:') {
        $dbFile = (string) $dbConfig['database'];
        if (!is_file($dbFile)) {
            if (!is_dir(dirname($dbFile))) {
                @mkdir(dirname($dbFile), 0777, true);
            }
            @touch($dbFile);
        }
    }

    $capsule = new Capsule();
    $capsule->addConnection($dbConfig);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    if (($dbConfig['driver'] ?? '') === 'sqlite') {
        try {
            Capsule::connection()->statement('PRAGMA foreign_keys=ON');
        } catch (\Throwable $e) {
            // DB file may not exist yet (pre-migrate) — ignore.
        }
    }
    return [$config, $dbConfig];
}

/**
 * Build the Slim application with container, middleware and every /api/v1 route.
 */
function vlab_create_app(): App
{
    [$config] = vlab_boot_core();

    $container = new Container();
    $container->set('config', $config);
    $container->set(SettingsRepository::class, static fn () => SettingsRepository::instance());
    $container->set(SettingsValidator::class, static fn () => new SettingsValidator());
    $container->set(CalendarService::class, static fn (Container $c) => new CalendarService($c->get(SettingsRepository::class)));
    $container->set(IcalService::class, static fn (Container $c) => new IcalService($c->get(SettingsRepository::class), $c->get(CalendarService::class)));
    $container->set(AvailabilityService::class, static fn (Container $c) => new AvailabilityService($c->get(SettingsRepository::class), $c->get(CalendarService::class)));
    $container->set(LimitsEvaluator::class, static fn (Container $c) => new LimitsEvaluator($c->get(SettingsRepository::class), $c->get(CalendarService::class), $c->get(AvailabilityService::class)));
    $container->set(OrderStateMachine::class, static fn (Container $c) => new OrderStateMachine($c->get(SettingsRepository::class), $c->get(CalendarService::class)));
    $container->set(RegulationService::class, static fn (Container $c) => new RegulationService($c->get(SettingsRepository::class)));
    $container->set(OrderService::class, static fn (Container $c) => new OrderService(
        $c->get(SettingsRepository::class),
        $c->get(CalendarService::class),
        $c->get(AvailabilityService::class),
        $c->get(LimitsEvaluator::class),
        $c->get(OrderStateMachine::class),
        $c->get(RegulationService::class)
    ));
    $container->set(OrderPdfService::class, static fn (Container $c) => new OrderPdfService($c->get(SettingsRepository::class)));
    $container->set(StatsService::class, static fn (Container $c) => new StatsService($c->get(SettingsRepository::class), $c->get(CalendarService::class)));
    $container->set(JwtService::class, static fn (Container $c) => new JwtService($c->get(SettingsRepository::class), $c->get('config')));
    $container->set(RoleResolver::class, static fn (Container $c) => new RoleResolver($c->get(SettingsRepository::class)));
    $container->set(LdapAuthenticatorInterface::class, static function (Container $c) {
        $config = $c->get('config');
        $mode = $c->get(SettingsRepository::class)->ldapMode($config);
        if ($mode === 'real') {
            try {
                return new RealLdapAuthenticator($c->get(SettingsRepository::class), $config);
            } catch (LdapUnavailableException $e) {
                // Surface as 503 on use, not as a boot failure.
                return new class($e->getMessage()) implements LdapAuthenticatorInterface {
                    public function __construct(private string $reason)
                    {
                    }

                    public function authenticate(string $username, string $password): ?LdapUser
                    {
                        throw new LdapUnavailableException($this->reason);
                    }

                    public function testConnection(): LdapTestResult
                    {
                        return new LdapTestResult(false, $this->reason);
                    }

                    public function mode(): string
                    {
                        return 'real';
                    }
                };
            }
        }
        return new FakeLdapAuthenticator();
    });

    // Controllers.
    $container->set(SystemController::class, static fn (Container $c) => new SystemController($c->get(SettingsRepository::class), $c->get('config')));
    $container->set(AuthController::class, static fn (Container $c) => new AuthController(
        $c->get(LdapAuthenticatorInterface::class),
        $c->get(RoleResolver::class),
        $c->get(JwtService::class),
        $c->get(RegulationService::class),
        $c->get(OrderService::class)
    ));
    $container->set(SettingsController::class, static fn (Container $c) => new SettingsController(
        $c->get(SettingsRepository::class),
        $c->get(SettingsValidator::class),
        $c->get(LdapAuthenticatorInterface::class),
        $c->get('config')
    ));
    $container->set(CategoryController::class, static fn () => new CategoryController());
    $container->set(ProductController::class, static fn (Container $c) => new ProductController($c->get(AvailabilityService::class)));
    $container->set(UnitController::class, static fn () => new UnitController());
    $container->set(LogController::class, static fn () => new LogController());
    $container->set(AvailabilityController::class, static fn (Container $c) => new AvailabilityController(
        $c->get(AvailabilityService::class),
        $c->get(CalendarService::class),
        $c->get(SettingsRepository::class),
        $c->get(OrderService::class)
    ));
    $container->set(CalendarController::class, static fn (Container $c) => new CalendarController($c->get(CalendarService::class)));
    $container->set(IcalController::class, static fn (Container $c) => new IcalController($c->get(IcalService::class), $c->get('config')));
    $container->set(CartController::class, static fn (Container $c) => new CartController($c->get(OrderService::class), $c->get(AvailabilityService::class)));
    $container->set(OrderController::class, static fn (Container $c) => new OrderController(
        $c->get(OrderService::class),
        $c->get(OrderStateMachine::class),
        $c->get(RegulationService::class),
        $c->get(CalendarService::class),
        $c->get(OrderPdfService::class),
        $c->get(JwtService::class),
        $c->get(LdapAuthenticatorInterface::class),
        $c->get(RoleResolver::class)
    ));
    $container->set(RegulationController::class, static fn (Container $c) => new RegulationController(
        $c->get(RegulationService::class),
        $c->get(JwtService::class),
        $c->get('config')
    ));
    $container->set(ClosureController::class, static fn () => new ClosureController());
    $container->set(UserController::class, static fn (Container $c) => new UserController($c->get(JwtService::class)));
    $container->set(StatsController::class, static fn (Container $c) => new StatsController($c->get(StatsService::class), $c->get(SettingsRepository::class)));
    $container->set(AuditController::class, static fn () => new AuditController());

    AppFactory::setContainer($container);
    $app = AppFactory::create();

    $responseFactory = $app->getResponseFactory();

    $storagePath = (string) ($config['storage']['path'] ?? 'storage');
    if (!str_starts_with($storagePath, '/')) {
        $storagePath = dirname(__DIR__) . '/' . $storagePath;
    }

    // Middleware helpers (route-level).
    $authRequired = new AuthenticateMiddleware($container->get(JwtService::class), false);
    $authOptional = new AuthenticateMiddleware($container->get(JwtService::class), true);
    $catalogGate = new CatalogGateMiddleware($container->get(SettingsRepository::class));
    $staff = new RequireRoleMiddleware('technician', 'assistant', 'admin');
    $techAdmin = new RequireRoleMiddleware('technician', 'admin');
    $adminOnly = new RequireRoleMiddleware('admin');
    $studentOnly = new RequireRoleMiddleware('student');

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container, $authRequired, $authOptional, $catalogGate, $staff, $techAdmin, $adminOnly, $studentOnly, $storagePath) {
        // --- system ---------------------------------------------------------
        $group->get('/health', [SystemController::class, 'health']);
        $group->get('/meta/enums', [SystemController::class, 'enums']);

        // --- auth -----------------------------------------------------------
        $group->post('/auth/login', [AuthController::class, 'login'])
            ->add(new RateLimitMiddleware($container->get(SettingsRepository::class), $storagePath));
        $group->post('/auth/refresh', [AuthController::class, 'refresh']);
        $group->post('/auth/logout', [AuthController::class, 'logout'])->add($authRequired);
        $group->get('/auth/me', [AuthController::class, 'me'])->add($authRequired);
        $group->patch('/auth/me', [AuthController::class, 'updateMe'])->add($authRequired);

        // --- settings -------------------------------------------------------
        $group->get('/settings/public', [SettingsController::class, 'publicSettings']);
        $group->get('/settings', [SettingsController::class, 'index'])->add($staff)->add($authRequired);
        $group->post('/settings/ldap/test', [SettingsController::class, 'ldapTest'])->add($adminOnly)->add($authRequired);
        $group->put('/settings', [SettingsController::class, 'bulkUpdate'])->add($adminOnly)->add($authRequired);
        $group->put('/settings/{key}', [SettingsController::class, 'updateOne'])->add($adminOnly)->add($authRequired);

        // --- catalog --------------------------------------------------------
        $group->get('/categories', [CategoryController::class, 'index'])->add($catalogGate)->add($authOptional);
        $group->get('/categories/{idOrSlug}', [CategoryController::class, 'show'])->add($catalogGate)->add($authOptional);
        $group->post('/categories', [CategoryController::class, 'store'])->add($techAdmin)->add($authRequired);
        $group->put('/categories/{id}', [CategoryController::class, 'update'])->add($techAdmin)->add($authRequired);
        $group->delete('/categories/{id}', [CategoryController::class, 'destroy'])->add($techAdmin)->add($authRequired);

        $group->get('/products', [ProductController::class, 'index'])->add($catalogGate)->add($authOptional);
        $group->get('/products/{idOrSlug}', [ProductController::class, 'show'])->add($catalogGate)->add($authOptional);
        $group->post('/products', [ProductController::class, 'store'])->add($techAdmin)->add($authRequired);
        $group->put('/products/{id}', [ProductController::class, 'update'])->add($techAdmin)->add($authRequired);
        $group->delete('/products/{id}', [ProductController::class, 'destroy'])->add($techAdmin)->add($authRequired);
        $group->put('/products/{id}/recommended', [ProductController::class, 'replaceRecommendedEndpoint'])->add($techAdmin)->add($authRequired);
        $group->put('/products/{id}/substitutes', [ProductController::class, 'replaceSubstitutesEndpoint'])->add($techAdmin)->add($authRequired);
        $group->get('/products/{id}/availability', [ProductController::class, 'availability'])->add($catalogGate)->add($authOptional);
        $group->get('/brands', [ProductController::class, 'brands'])->add($catalogGate)->add($authOptional);

        $group->get('/products/{id}/units', [UnitController::class, 'index'])->add($staff)->add($authRequired);
        $group->post('/products/{id}/units', [UnitController::class, 'store'])->add($techAdmin)->add($authRequired);
        $group->put('/units/{unitId}', [UnitController::class, 'update'])->add($techAdmin)->add($authRequired);
        $group->delete('/units/{unitId}', [UnitController::class, 'destroy'])->add($techAdmin)->add($authRequired);

        $group->get('/products/{id}/logs', [LogController::class, 'productLogs'])->add($catalogGate)->add($authOptional);
        $group->post('/products/{id}/logs', [LogController::class, 'store'])->add($staff)->add($authRequired);
        $group->put('/logs/{logId}', [LogController::class, 'update'])->add($staff)->add($authRequired);
        $group->delete('/logs/{logId}', [LogController::class, 'destroy'])->add($techAdmin)->add($authRequired);
        $group->get('/logs', [LogController::class, 'feed'])->add($staff)->add($authRequired);

        // --- availability & calendar ---------------------------------------
        $group->get('/availability/products', [AvailabilityController::class, 'products'])->add($catalogGate)->add($authOptional);
        $group->post('/availability/dates', [AvailabilityController::class, 'dates'])->add($catalogGate)->add($authOptional);
        $group->post('/availability/check', [AvailabilityController::class, 'check'])->add($authRequired);
        $group->get('/calendar/opening', [CalendarController::class, 'opening']);

        // --- iCal feed ------------------------------------------------------
        // No auth on the feed itself: the opaque token in the path IS the
        // credential (calendar clients cannot send an Authorization header).
        $group->get('/ical/{token:[0-9a-f]{32,64}}.ics', [IcalController::class, 'feed']);
        $group->get('/me/ical', [IcalController::class, 'mine'])->add($authRequired);
        $group->post('/me/ical/rotate', [IcalController::class, 'rotate'])->add($authRequired);

        // --- cart & orders --------------------------------------------------
        $group->get('/cart', [CartController::class, 'show'])->add($studentOnly)->add($authRequired);
        $group->post('/cart/items', [CartController::class, 'addItem'])->add($studentOnly)->add($authRequired);
        $group->patch('/cart/items/{itemId}', [CartController::class, 'patchItem'])->add($studentOnly)->add($authRequired);
        $group->post('/cart/items/{itemId}/swap', [CartController::class, 'swapItem'])->add($studentOnly)->add($authRequired);
        $group->delete('/cart/items/{itemId}', [CartController::class, 'deleteItem'])->add($studentOnly)->add($authRequired);
        $group->put('/cart/dates', [CartController::class, 'putDates'])->add($studentOnly)->add($authRequired);
        $group->delete('/cart', [CartController::class, 'clear'])->add($studentOnly)->add($authRequired);

        $group->post('/orders', [OrderController::class, 'store'])
            ->add(new RequireRegulationAcceptanceMiddleware($container->get(RegulationService::class), $container->get(OrderService::class)))
            ->add($studentOnly)
            ->add($authRequired);
        // Staff manual loan creation (`orders.create_manual`: technician + admin).
        $group->post('/orders/manual', [OrderController::class, 'storeManual'])->add($techAdmin)->add($authRequired);
        $group->get('/orders/calendar', [OrderController::class, 'calendar'])->add($staff)->add($authRequired);
        $group->get('/orders', [OrderController::class, 'index'])->add($authRequired);
        $group->get('/orders/{id:[0-9]+}', [OrderController::class, 'show'])->add($authRequired);
        $group->put('/orders/{id:[0-9]+}', [OrderController::class, 'update'])->add($staff)->add($authRequired);
        $group->post('/orders/{id:[0-9]+}/approve', [OrderController::class, 'approve'])->add($staff)->add($authRequired);
        $group->post('/orders/{id:[0-9]+}/reject', [OrderController::class, 'reject'])->add($staff)->add($authRequired);
        $group->post('/orders/{id:[0-9]+}/cancel', [OrderController::class, 'cancel'])->add($authRequired);
        $group->post('/orders/{id:[0-9]+}/pickup', [OrderController::class, 'pickup'])->add($staff)->add($authRequired);
        $group->post('/orders/{id:[0-9]+}/return', [OrderController::class, 'returnOrder'])->add($staff)->add($authRequired);
        $group->post('/orders/{id:[0-9]+}/no-show', [OrderController::class, 'noShow'])->add($staff)->add($authRequired);
        $group->post('/orders/{id:[0-9]+}/reopen', [OrderController::class, 'reopen'])->add($adminOnly)->add($authRequired);
        $group->post('/orders/{id:[0-9]+}/change-dates', [OrderController::class, 'changeDates'])->add($adminOnly)->add($authRequired);
        $group->post('/orders/{id:[0-9]+}/notes', [OrderController::class, 'notes'])->add($staff)->add($authRequired);
        $group->get('/orders/{id:[0-9]+}/events', [OrderController::class, 'events'])->add($authRequired);
        // Printable loan form: header auth OR ?token= (same pattern as the regulations PDF stream).
        $group->get('/orders/{id:[0-9]+}/pdf', [OrderController::class, 'pdf'])->add($authOptional);

        // --- regulations ----------------------------------------------------
        $group->get('/me/regulations/pending', [RegulationController::class, 'pendingMine'])->add($authRequired);
        $group->post('/me/regulations/{id:[0-9]+}/accept', [RegulationController::class, 'accept'])->add($authRequired);
        $group->get('/regulations', [RegulationController::class, 'index'])->add($authOptional);
        $group->get('/regulations/{id:[0-9]+}/file', [RegulationController::class, 'file'])->add($authOptional);
        $group->get('/regulations/{idOrSlug}', [RegulationController::class, 'show'])->add($authOptional);
        $group->post('/regulations', [RegulationController::class, 'store'])->add($techAdmin)->add($authRequired);
        $group->put('/regulations/{id:[0-9]+}', [RegulationController::class, 'update'])->add($techAdmin)->add($authRequired);
        $group->post('/regulations/{id:[0-9]+}/file', [RegulationController::class, 'upload'])->add($techAdmin)->add($authRequired);
        $group->post('/regulations/{id:[0-9]+}/publish', [RegulationController::class, 'publish'])->add($techAdmin)->add($authRequired);
        $group->delete('/regulations/{id:[0-9]+}', [RegulationController::class, 'destroy'])->add($adminOnly)->add($authRequired);
        $group->get('/regulations/{id:[0-9]+}/acceptances', [RegulationController::class, 'acceptances'])->add($staff)->add($authRequired);

        // --- closures -------------------------------------------------------
        $group->get('/closures', [ClosureController::class, 'index'])->add($authOptional);
        $group->post('/closures', [ClosureController::class, 'store'])->add($techAdmin)->add($authRequired);
        $group->put('/closures/{id:[0-9]+}', [ClosureController::class, 'update'])->add($techAdmin)->add($authRequired);
        $group->delete('/closures/{id:[0-9]+}', [ClosureController::class, 'destroy'])->add($techAdmin)->add($authRequired);

        // --- users ----------------------------------------------------------
        $group->get('/users', [UserController::class, 'index'])->add($staff)->add($authRequired);
        $group->get('/users/{id:[0-9]+}', [UserController::class, 'show'])->add($staff)->add($authRequired);
        $group->put('/users/{id:[0-9]+}', [UserController::class, 'update'])->add($adminOnly)->add($authRequired);
        $group->get('/users/{id:[0-9]+}/orders', [OrderController::class, 'userOrders'])->add($staff)->add($authRequired);

        // --- stats ----------------------------------------------------------
        $group->get('/stats/overview', [StatsController::class, 'overview'])->add($staff)->add($authRequired);
        $group->get('/stats/loans-over-time', [StatsController::class, 'loansOverTime'])->add($techAdmin)->add($authRequired);
        $group->get('/stats/top-products', [StatsController::class, 'topProducts'])->add($techAdmin)->add($authRequired);
        $group->get('/stats/by-category', [StatsController::class, 'byCategory'])->add($techAdmin)->add($authRequired);
        $group->get('/stats/late-returns', [StatsController::class, 'lateReturns'])->add($staff)->add($authRequired);
        $group->get('/stats/utilization', [StatsController::class, 'utilization'])->add($techAdmin)->add($authRequired);
        $group->get('/stats/my-activity', [StatsController::class, 'myActivity'])->add($staff)->add($authRequired);
        $group->get('/stats/export', [StatsController::class, 'export'])->add($techAdmin)->add($authRequired);

        // --- audit ----------------------------------------------------------
        $group->get('/audit-logs', [AuditController::class, 'index'])->add($adminOnly)->add($authRequired);
    });

    // Global middleware: innermost first (Slim executes last-added first).
    $app->add(new JsonBodyParserMiddleware());
    $app->add(new CorsMiddleware($responseFactory, $config));
    $app->add(new ErrorHandlerMiddleware($responseFactory, (bool) $config['app']['debug']));

    return $app;
}
