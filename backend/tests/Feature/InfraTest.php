<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Enums;
use App\Support\Migrator;
use Tests\TestCase;

final class InfraTest extends TestCase
{
    public function testEveryErrorMatchesTheEnvelope(): void
    {
        // 404 unknown route.
        [$status, $payload] = $this->json('GET', '/api/v1/nonexistent');
        $this->assertSame(404, $status);
        $this->assertErrorEnvelope($payload, 'not_found');
        // 405 wrong method.
        [$status, $payload] = $this->json('DELETE', '/api/v1/health');
        $this->assertSame(405, $status);
        $this->assertErrorEnvelope($payload, 'method_not_allowed');
        // 400 malformed JSON.
        $factory = new \Slim\Psr7\Factory\ServerRequestFactory();
        $request = $factory->createServerRequest('POST', '/api/v1/auth/login', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write('{invalid json');
        $response = $this->app->handle($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertErrorEnvelope(json_decode((string) $response->getBody(), true), 'invalid_json');
        // 422 validation.
        [$status, $payload] = $this->json('POST', '/api/v1/auth/login', []);
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'validation_failed');
        $this->assertIsArray($payload['error']['details']);
        // 401.
        [$status, $payload] = $this->json('GET', '/api/v1/auth/me');
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($payload, 'unauthenticated');
    }

    public function testHealthReportsModeConnectionAndMigrations(): void
    {
        [$status, $payload] = $this->json('GET', '/api/v1/health');
        $this->assertSame(200, $status);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('vlab', $payload['app']);
        $this->assertSame('fake', $payload['ldap_mode']);
        $this->assertTrue($payload['database']['connected']);
        $this->assertSame('sqlite', $payload['database']['driver']);
        $this->assertSame(21, $payload['database']['migrations_applied']);
        $this->assertSame('Europe/Rome', $payload['timezone']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['server_time']);
    }

    public function testMetaEnumsCoversAppendixA(): void
    {
        [$status, $payload] = $this->json('GET', '/api/v1/meta/enums');
        $this->assertSame(200, $status);
        $expected = [
            'order_status' => Enums::ORDER_STATUSES,
            'product_status' => Enums::PRODUCT_STATUSES,
            'unit_status' => Enums::UNIT_STATUSES,
            'loan_mode' => Enums::LOAN_MODES,
            'log_type' => Enums::LOG_TYPES,
            'log_severity' => Enums::LOG_SEVERITIES,
            'role' => Enums::ROLES,
            'regulation_scope' => Enums::REGULATION_SCOPES,
            'recommendation_relation' => Enums::RECOMMENDATION_RELATIONS,
            'condition' => Enums::CONDITIONS,
        ];
        foreach ($expected as $enum => $values) {
            $this->assertArrayHasKey($enum, $payload);
            $actualValues = array_map(static fn ($e) => $e['value'], $payload[$enum]);
            $this->assertSame($values, $actualValues, $enum);
            foreach ($payload[$enum] as $entry) {
                $this->assertNotEmpty($entry['label'], "{$enum}/{$entry['value']} label");
            }
        }
        foreach ($payload['order_status'] as $entry) {
            $this->assertArrayHasKey('is_terminal', $entry);
            $this->assertArrayHasKey('locks_stock', $entry);
        }
    }

    public function testMigrationsRunCleanAndFreshIsIdempotent(): void
    {
        $migrator = new Migrator();
        // Re-running migrate is a no-op.
        $this->assertSame([], $migrator->migrate());
        $this->assertSame(21, $migrator->appliedCount());
        // fresh drops and recreates everything.
        $ran = $migrator->fresh();
        $this->assertCount(21, $ran);
        $this->assertSame(21, $migrator->appliedCount());
        // And again.
        $ran = $migrator->fresh();
        $this->assertCount(21, $ran);
    }

    public function testCollectionEnvelopeAndPaginationClamping(): void
    {
        foreach (range(1, 3) as $i) {
            $this->seedProduct();
        }
        [, $payload] = $this->json('GET', '/api/v1/products?per_page=2&page=2');
        $this->assertSame(2, $payload['meta']['page']);
        $this->assertSame(2, $payload['meta']['per_page']);
        $this->assertSame(3, $payload['meta']['total']);
        $this->assertSame(2, $payload['meta']['total_pages']);
        $this->assertCount(1, $payload['data']);
        // per_page clamped, not rejected.
        [$status, $payload] = $this->json('GET', '/api/v1/products?per_page=100000');
        $this->assertSame(200, $status);
        $this->assertSame(100, $payload['meta']['per_page']);
        // Non-paginated collections use meta null.
        [, $payload] = $this->json('GET', '/api/v1/categories');
        $this->assertNull($payload['meta']);
    }
}
