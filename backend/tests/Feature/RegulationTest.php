<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Regulation;
use App\Models\RegulationAcceptance;
use App\Models\RegulationTarget;
use App\Models\User;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

final class RegulationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
        (new \RegulationsSeeder())->run();
    }

    private function globalReg(): Regulation
    {
        return Regulation::where('slug', 'regolamento-generale')->first();
    }

    public function testGlobalRegulationPendingOnLogin(): void
    {
        [, $login] = $this->json('POST', '/api/v1/auth/login', ['username' => 'student1', 'password' => 'password']);
        $slugs = array_map(static fn ($r) => $r['slug'], $login['pending_regulations']);
        $this->assertContains('regolamento-generale', $slugs);
        // Scoped regulations are NOT in the global pending list.
        $this->assertNotContains('avvertenze-vr', $slugs);
    }

    /**
     * Regression: login and /auth/me used to omit `blocking`, so the SPA gate —
     * which keys the blocking modal off that flag — never fired and there was
     * literally no way for a user to accept anything.
     */
    public function testPendingRegulationsCarryTheBlockingFlagEverywhere(): void
    {
        [, $login] = $this->json('POST', '/api/v1/auth/login', ['username' => 'student1', 'password' => 'password']);
        $this->assertNotSame([], $login['pending_regulations']);
        foreach ($login['pending_regulations'] as $reg) {
            $this->assertArrayHasKey('blocking', $reg, 'login payload must carry blocking');
            $this->assertTrue($reg['blocking']);
        }

        $this->actingAs('student');
        [, $me] = $this->json('GET', '/api/v1/auth/me');
        $this->assertNotSame([], $me['pending_regulations']);
        foreach ($me['pending_regulations'] as $reg) {
            $this->assertArrayHasKey('blocking', $reg, '/auth/me payload must carry blocking');
            $this->assertTrue($reg['blocking']);
        }

        [, $pending] = $this->json('GET', '/api/v1/me/regulations/pending');
        foreach ($pending['data'] as $reg) {
            $this->assertTrue($reg['blocking']);
        }
    }

    /** Staff are held to the same global regulations as students (§9 matrix). */
    public function testStaffAlsoGetBlockingGlobalRegulations(): void
    {
        foreach (['technician', 'assistant', 'admin'] as $role) {
            $this->actingAs($role);
            [, $me] = $this->json('GET', '/api/v1/auth/me');
            $slugs = array_map(static fn ($r) => $r['slug'], $me['pending_regulations']);
            $this->assertContains('regolamento-generale', $slugs, $role . ' must be gated too');
        }
    }

    public function testAcceptClearsPendingAndIsIdempotent(): void
    {
        $this->actingAs('student');
        $reg = $this->globalReg();
        [$status, $payload] = $this->json('POST', "/api/v1/me/regulations/{$reg->id}/accept", ['version' => 1]);
        $this->assertSame(200, $status);
        $this->assertTrue($payload['accepted']);
        $this->assertSame([], $payload['pending_regulations']);
        [, $pending] = $this->json('GET', '/api/v1/me/regulations/pending');
        $this->assertSame([], $pending['data']);
        // Idempotent re-accept.
        [$status] = $this->json('POST', "/api/v1/me/regulations/{$reg->id}/accept", ['version' => 1]);
        $this->assertSame(200, $status);
        $this->assertSame(1, RegulationAcceptance::where('regulation_id', $reg->id)->count());
    }

    public function testStaleVersionAcceptConflicts(): void
    {
        $this->actingAs('student');
        $reg = $this->globalReg();
        [$status, $payload] = $this->json('POST', "/api/v1/me/regulations/{$reg->id}/accept", ['version' => 99]);
        $this->assertSame(409, $status);
        $this->assertErrorEnvelope($payload, 'conflict');
    }

    public function testPublishWithBumpMakesPendingAgain(): void
    {
        $student = $this->actingAs('student');
        $reg = $this->globalReg();
        $this->json('POST', "/api/v1/me/regulations/{$reg->id}/accept", ['version' => 1]);
        [, $pending] = $this->json('GET', '/api/v1/me/regulations/pending');
        $this->assertSame([], $pending['data']);

        $this->actingAs('technician');
        [$status, $payload] = $this->json('POST', "/api/v1/regulations/{$reg->id}/publish", ['bump_version' => true]);
        $this->assertSame(200, $status);
        $this->assertSame(2, $payload['version']);

        $this->actingAs($student);
        [, $pending] = $this->json('GET', '/api/v1/me/regulations/pending');
        $this->assertCount(1, $pending['data']);
        $this->assertTrue($pending['data'][0]['blocking']);
        $this->assertSame(2, $pending['data'][0]['version']);
    }

    public function testCheckoutBlockedByCategoryRegulationThenAcceptedInline(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $vr = \App\Models\Category::where('slug', 'tecnologie-interattive')->first()
            ?? $this->seedCategory(['slug' => 'tecnologie-interattive', 'name' => 'Tecnologie Interattive']);
        // The seeder targets the category only if it existed; ensure the target row exists.
        $vrReg = Regulation::where('slug', 'avvertenze-vr')->first();
        if (!RegulationTarget::where('regulation_id', $vrReg->id)->exists()) {
            RegulationTarget::create(['regulation_id' => $vrReg->id, 'target_type' => 'category', 'target_id' => $vr->id]);
        }
        $product = $this->seedProduct(['category_id' => $vr->id], 3);
        $student = $this->actingAs('student');
        // Accept the global one so only the scoped one is missing.
        $this->json('POST', '/api/v1/me/regulations/' . $this->globalReg()->id . '/accept', ['version' => 1]);

        $body = [
            'from_cart' => false,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'pickup_date' => '2026-09-07', 'pickup_time' => '09:30',
            'return_date' => '2026-09-08', 'return_time' => '14:00',
            'subject' => 'Materia', 'motivation' => 'Motivazione abbastanza lunga per superare il minimo.',
        ];
        [$status, $payload] = $this->json('POST', '/api/v1/orders', $body);
        $this->assertSame(409, $status);
        $this->assertErrorEnvelope($payload, 'regulation_acceptance_required');
        $this->assertSame([$vrReg->id], $payload['error']['details']['regulation_ids']);

        // availability/check lists it with accepted=false.
        [, $check] = $this->json('POST', '/api/v1/availability/check', [
            'items' => $body['items'],
            'pickup_date' => $body['pickup_date'], 'pickup_time' => $body['pickup_time'],
            'return_date' => $body['return_date'], 'return_time' => $body['return_time'],
        ]);
        $this->assertCount(1, $check['required_regulations']);
        $this->assertSame('avvertenze-vr', $check['required_regulations'][0]['slug']);
        $this->assertFalse($check['required_regulations'][0]['accepted']);
        $this->assertSame('category', $check['required_regulations'][0]['scope']);

        // Checkout collecting the acceptance inline.
        [$status, $order] = $this->json('POST', '/api/v1/orders', $body + ['accepted_regulation_ids' => [$vrReg->id]]);
        $this->assertSame(201, $status, json_encode($order));
        $acceptance = RegulationAcceptance::where('regulation_id', $vrReg->id)->where('user_id', $student->id)->first();
        $this->assertNotNull($acceptance);
        $this->assertSame($order['id'], (int) $acceptance->order_id);
        $this->assertTrue($order['required_regulations'][0]['accepted']);
    }

    public function testNonBlockingRegulationsNeverBlock(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $this->setSetting('regulations.enforce_global_acceptance', false);
        $category = $this->seedCategory();
        $product = $this->seedProduct(['category_id' => $category->id], 3);
        // requires_acceptance=false regulation targeting the category.
        $info = Regulation::create([
            'slug' => 'info-only', 'title' => 'Solo informativo', 'scope' => 'category',
            'content_type' => 'markdown', 'body' => 'x', 'requires_acceptance' => false,
            'is_active' => true, 'version' => 1, 'published_at' => '2026-01-01 00:00:00',
        ]);
        RegulationTarget::create(['regulation_id' => $info->id, 'target_type' => 'category', 'target_id' => $category->id]);
        // Inactive + unpublished blocking regs.
        $inactive = Regulation::create([
            'slug' => 'inactive-reg', 'title' => 'Disattivato', 'scope' => 'category',
            'content_type' => 'markdown', 'requires_acceptance' => true,
            'is_active' => false, 'version' => 1, 'published_at' => '2026-01-01 00:00:00',
        ]);
        RegulationTarget::create(['regulation_id' => $inactive->id, 'target_type' => 'category', 'target_id' => $category->id]);
        $draft = Regulation::create([
            'slug' => 'draft-reg', 'title' => 'Bozza', 'scope' => 'category',
            'content_type' => 'markdown', 'requires_acceptance' => true,
            'is_active' => true, 'version' => 1, 'published_at' => null,
        ]);
        RegulationTarget::create(['regulation_id' => $draft->id, 'target_type' => 'category', 'target_id' => $category->id]);

        $this->actingAs('student');
        [$status, $payload] = $this->json('POST', '/api/v1/orders', [
            'from_cart' => false,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'pickup_date' => '2026-09-07', 'pickup_time' => '09:30',
            'return_date' => '2026-09-08', 'return_time' => '14:00',
            'subject' => 'Materia', 'motivation' => 'Motivazione abbastanza lunga per superare il minimo.',
        ]);
        $this->assertSame(201, $status, json_encode($payload));

        // Students get 404 on draft/inactive detail; staff 200.
        [$status] = $this->json('GET', '/api/v1/regulations/draft-reg');
        $this->assertSame(404, $status);
        [$status] = $this->json('GET', '/api/v1/regulations/inactive-reg');
        $this->assertSame(404, $status);
        $this->actingAs('technician');
        [$status] = $this->json('GET', '/api/v1/regulations/draft-reg');
        $this->assertSame(200, $status);
    }

    public function testProductAndCategoryScopedRegulationsDeduplicate(): void
    {
        $category = $this->seedCategory();
        $product = $this->seedProduct(['category_id' => $category->id], 3);
        $reg = Regulation::create([
            'slug' => 'both-scopes', 'title' => 'Doppio ambito', 'scope' => 'category',
            'content_type' => 'markdown', 'requires_acceptance' => true,
            'is_active' => true, 'version' => 1, 'published_at' => '2026-01-01 00:00:00',
        ]);
        RegulationTarget::create(['regulation_id' => $reg->id, 'target_type' => 'category', 'target_id' => $category->id]);
        RegulationTarget::create(['regulation_id' => $reg->id, 'target_type' => 'product', 'target_id' => $product->id]);
        $this->actingAs('student');
        [, $check] = $this->json('POST', '/api/v1/availability/check', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $ids = array_map(static fn ($r) => $r['id'], $check['required_regulations']);
        $this->assertSame(1, count(array_keys($ids, $reg->id, true)));
    }

    public function testListHidesBodyDetailIncludesIt(): void
    {
        [, $list] = $this->json('GET', '/api/v1/regulations');
        $this->assertNotEmpty($list['data']);
        foreach ($list['data'] as $item) {
            $this->assertArrayNotHasKey('body', $item);
        }
        [, $detail] = $this->json('GET', '/api/v1/regulations/regolamento-generale');
        $this->assertArrayHasKey('body', $detail);
        $this->assertStringContainsString('Regolamento', (string) $detail['body']);
    }

    public function testPdfUploadValidationAndStreaming(): void
    {
        $tech = $this->actingAs('technician');
        $reg = $this->globalReg();

        $upload = function (string $contents, string $mime, string $name) use ($reg): array {
            $tmp = tempnam(sys_get_temp_dir(), 'vlabtest');
            file_put_contents($tmp, $contents);
            $factory = new ServerRequestFactory();
            $request = $factory->createServerRequest('POST', "/api/v1/regulations/{$reg->id}/file", ['REMOTE_ADDR' => '127.0.0.1'])
                ->withHeader('Authorization', 'Bearer ' . $this->token)
                ->withUploadedFiles(['file' => new UploadedFile($tmp, $name, $mime, strlen($contents), UPLOAD_ERR_OK)]);
            $response = $this->app->handle($request);
            $raw = (string) $response->getBody();
            return [$response->getStatusCode(), $raw !== '' ? json_decode($raw, true) : null];
        };

        // Non-PDF -> 415.
        [$status, $payload] = $upload('hello world', 'text/plain', 'nota.txt');
        $this->assertSame(415, $status);
        $this->assertErrorEnvelope($payload, 'unsupported_media_type');

        // Valid PDF -> 200 and metadata set.
        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
        [$status, $payload] = $upload($pdf, 'application/pdf', 'regolamento.pdf');
        $this->assertSame(200, $status, json_encode($payload));
        $this->assertSame('pdf', $payload['content_type']);
        $this->assertSame("/api/v1/regulations/{$reg->id}/file", $payload['file_url']);

        // Streaming with ?token= (no Authorization header).
        $this->anonymous();
        $jwt = $this->container()->get(\App\Domain\Auth\JwtService::class);
        $token = $jwt->issueAccessToken($tech)['token'];
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('GET', "/api/v1/regulations/{$reg->id}/file", ['REMOTE_ADDR' => '127.0.0.1'])
            ->withQueryParams(['token' => $token]);
        $response = $this->app->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('inline; filename="regolamento.pdf"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame((string) strlen($pdf), $response->getHeaderLine('Content-Length'));
        $this->assertStringStartsWith('%PDF', (string) $response->getBody());
    }

    public function testOversizedUploadRejected413(): void
    {
        $this->actingAs('technician');
        $reg = $this->globalReg();
        $big = '%PDF' . str_repeat('a', 11 * 1024 * 1024);
        $tmp = tempnam(sys_get_temp_dir(), 'vlabtest');
        file_put_contents($tmp, $big);
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', "/api/v1/regulations/{$reg->id}/file", ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withUploadedFiles(['file' => new UploadedFile($tmp, 'big.pdf', 'application/pdf', strlen($big), UPLOAD_ERR_OK)]);
        $response = $this->app->handle($request);
        $this->assertSame(413, $response->getStatusCode());
    }

    public function testOnlyTechAdminCreateOnlyAdminDelete(): void
    {
        $body = ['title' => 'Nuovo regolamento', 'scope' => 'global', 'body' => 'testo', 'publish' => true];
        $this->actingAs('assistant');
        [$status] = $this->json('POST', '/api/v1/regulations', $body);
        $this->assertSame(403, $status);
        $this->actingAs('technician');
        [$status, $created] = $this->json('POST', '/api/v1/regulations', $body);
        $this->assertSame(201, $status);
        [$status] = $this->json('DELETE', '/api/v1/regulations/' . $created['id']);
        $this->assertSame(403, $status);
        $this->actingAs('admin');
        [$status] = $this->json('DELETE', '/api/v1/regulations/' . $created['id']);
        $this->assertSame(204, $status);
    }

    public function testAcceptancesReport(): void
    {
        $this->actingAs('student');
        $reg = $this->globalReg();
        $this->json('POST', "/api/v1/me/regulations/{$reg->id}/accept", ['version' => 1]);
        $this->actingAs('assistant');
        [$status, $payload] = $this->json('GET', "/api/v1/regulations/{$reg->id}/acceptances");
        $this->assertSame(200, $status);
        $this->assertCount(1, $payload['data']);
        $this->assertSame('student1', $payload['data'][0]['user']['ldap_uid']);
        $this->assertSame(1, $payload['stats']['accepted_current_version']);
        $this->assertSame(1, $payload['stats']['current_version']);
    }
}
