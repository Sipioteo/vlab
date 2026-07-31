<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Settings\SettingsRepository;
use App\Http\JsonRenderer;
use App\Support\Dates;
use App\Support\Enums;
use App\Support\Migrator;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SystemController extends Controller
{
    public function __construct(
        private SettingsRepository $settings,
        private array $config,
    ) {
    }

    public function health(Request $request, Response $response): Response
    {
        $connected = true;
        $migrations = 0;
        try {
            Capsule::connection()->getPdo();
            $migrations = (new Migrator())->appliedCount();
        } catch (\Throwable $e) {
            $connected = false;
        }
        $body = [
            'status' => $connected ? 'ok' : 'error',
            'app' => 'vlab',
            'version' => (string) ($this->config['app']['version'] ?? '1.0.0'),
            'environment' => (string) ($this->config['app']['env'] ?? 'local'),
            'database' => [
                'driver' => Capsule::connection()->getDriverName(),
                'connected' => $connected,
                'migrations_applied' => $migrations,
            ],
            'ldap_mode' => $this->settings->ldapMode($this->config),
            'server_time' => Dates::nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'timezone' => (string) ($this->settings->get('hours.timezone', 'Europe/Rome') ?? 'Europe/Rome'),
        ];
        if (!$connected) {
            return JsonRenderer::error($response, 503, 'server_error', 'Database non raggiungibile.', $body);
        }
        return $this->json($response, $body);
    }

    public function enums(Request $request, Response $response): Response
    {
        return $this->json($response, Enums::metaPayload());
    }
}
