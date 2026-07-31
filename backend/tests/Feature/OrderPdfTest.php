<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Auth\JwtService;
use App\Domain\Orders\OrderPdfService;
use App\Models\User;
use Tests\Support\PdfInspector;
use Tests\TestCase;

/**
 * GET /api/v1/orders/{id}/pdf — printable loan form.
 */
final class OrderPdfTest extends TestCase
{
    public function testOwnerStudentDownloadsTheFormOfAnApprovedOrder(): void
    {
        $student = $this->actingAs('student');
        $order = $this->seedOrder(['status' => 'approved', 'user_id' => $student->id]);

        [$status, , $response] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf");

        $this->assertSame(200, $status);
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertSame(
            'inline; filename="modulo-' . $order->code . '.pdf"',
            $response->getHeaderLine('Content-Disposition')
        );
        $body = (string) $response->getBody();
        $this->assertNotSame('', $body);
        $this->assertStringStartsWith('%PDF', $body);
        $this->assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));
    }

    public function testStaffDownloadsTheFormOfAnyOrder(): void
    {
        $studentUser = User::where('ldap_uid', 'student1')->first();
        $order = $this->seedOrder(['status' => 'picked_up', 'user_id' => $studentUser->id]);

        foreach (['technician', 'assistant', 'admin'] as $role) {
            $this->actingAs($role);
            [$status, , $response] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf");
            $this->assertSame(200, $status, "ruolo {$role}");
            $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
            $this->assertStringStartsWith('%PDF', (string) $response->getBody());
        }
    }

    public function testTheFormCarriesTheLabHeaderTheUserDataAndBothSignatureBoxes(): void
    {
        $this->setSetting('lab.name', 'Laboratorio di prova');
        $this->setSetting('lab.phone', '011 000 1122');
        $student = $this->actingAs('student');
        $student->display_name = 'Mario Rossi';
        $student->email = 'mario.rossi@studenti.polito.it';
        $student->matricola = 's123456';
        $student->save();

        $order = $this->seedOrder([
            'status' => 'approved',
            'user_id' => $student->id,
            'subject' => 'Laboratorio di ripresa',
            'professor' => 'Prof. Verdi',
            'motivation' => 'Riprese per il progetto di fine corso, in esterni.',
        ]);

        [$status, , $response] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf");
        $this->assertSame(200, $status);

        $pdf = PdfInspector::fromBytes((string) $response->getBody());
        $text = $pdf->text();

        // Header from settings — nothing hardcoded.
        $this->assertStringContainsString('Laboratorio di prova', $text);
        $this->assertStringContainsString('011 000 1122', $text);
        // Order + user data.
        $this->assertStringContainsString((string) $order->code, $text);
        $this->assertStringContainsString('Mario Rossi', $text);
        $this->assertStringContainsString('student1', $text);
        $this->assertStringContainsString('mario.rossi@studenti.polito.it', $text);
        $this->assertStringContainsString('s123456', $text);
        $this->assertStringContainsString('Laboratorio di ripresa', $text);
        $this->assertStringContainsString('Prof. Verdi', $text);
        $this->assertStringContainsString('Riprese per il progetto di fine corso', $text);
        // The two signature boxes.
        $this->assertStringContainsString('FIRMA DELLO STUDENTE AL RITIRO', $text);
        $this->assertStringContainsString('FIRMA DELLO STUDENTE ALLA RICONSEGNA', $text);
        $this->assertStringContainsString("Firma dell'operatore", $text);
    }

    /**
     * A long order must paginate cleanly: the table header repeats, no row is
     * cut in half and the signature block is never split across two pages.
     */
    public function testALongOrderPaginatesWithoutSplittingRowsOrTheSignatureBlock(): void
    {
        $student = $this->actingAs('student');

        $items = [];
        $names = [];
        for ($i = 1; $i <= 26; $i++) {
            $product = $this->seedProduct([
                'name' => 'Attrezzatura multipagina ' . sprintf('%02d', $i),
                'brand' => 'MarcaTest' . sprintf('%02d', $i),
            ], 1);
            $names[] = [
                'name' => 'Attrezzatura multipagina ' . sprintf('%02d', $i),
                'brand' => 'MarcaTest' . sprintf('%02d', $i),
            ];
            $items[] = ['product_id' => $product->id, 'quantity' => 1];
        }
        $order = $this->seedOrder(['status' => 'approved', 'user_id' => $student->id, 'items' => $items]);

        [$status, , $response] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf");
        $this->assertSame(200, $status);

        $pdf = PdfInspector::fromBytes((string) $response->getBody());
        $this->assertGreaterThanOrEqual(2, $pdf->pageCount(), 'Il modulo lungo deve occupare più di una pagina.');

        // The table header repeats on every page that carries the table.
        $headerPages = $pdf->pagesWith('ATTREZZATURA');
        $this->assertGreaterThanOrEqual(2, count($headerPages), "L'intestazione della tabella deve ripetersi.");

        // Every product is present exactly once, and its row is never split:
        // name and brand (same row) always land on the same page.
        foreach ($names as $item) {
            $namePages = $pdf->pagesWith($item['name']);
            $brandPages = $pdf->pagesWith($item['brand']);
            $this->assertCount(1, $namePages, "Prodotto assente o duplicato: {$item['name']}");
            $this->assertSame($namePages, $brandPages, "Riga spezzata fra due pagine: {$item['name']}");
        }

        // The signature block stays whole on a single page.
        $pickupPages = $pdf->pagesWith('FIRMA DELLO STUDENTE AL RITIRO');
        $returnPages = $pdf->pagesWith('FIRMA DELLO STUDENTE ALLA RICONSEGNA');
        $this->assertCount(1, $pickupPages);
        $this->assertSame($pickupPages, $returnPages, 'I due riquadri firma devono stare sulla stessa pagina.');
        $this->assertSame($pickupPages, $pdf->pagesWith("Firma dell'operatore"));

        // The footer repeats on every page.
        $this->assertSame($pdf->pageCount(), $pdf->pagesContaining('Generato il'));
        $this->assertSame($pdf->pageCount(), $pdf->pagesContaining('Pag.'));
    }

    public function testAnotherStudentIsRefused(): void
    {
        $owner = User::where('ldap_uid', 'student1')->first();
        $order = $this->seedOrder(['status' => 'approved', 'user_id' => $owner->id]);

        $other = User::create([
            'ldap_uid' => 'student99',
            'role' => 'student',
            'display_name' => 'Altro Studente',
            'is_active' => true,
            'token_version' => 1,
        ]);
        $this->actingAs($other);

        [$status, $payload] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf");

        $this->assertSame(403, $status);
        $this->assertErrorEnvelope($payload, 'forbidden');
    }

    public function testMissingOrderIsNotFound(): void
    {
        $this->actingAs('technician');
        [$status, $payload] = $this->json('GET', '/api/v1/orders/999999/pdf');
        $this->assertSame(404, $status);
        $this->assertErrorEnvelope($payload, 'not_found');
    }

    public function testPendingOrderHasNoFormYet(): void
    {
        $student = $this->actingAs('student');
        $order = $this->seedOrder(['status' => 'pending', 'user_id' => $student->id]);

        [$status, $payload] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf");

        $this->assertSame(409, $status);
        $this->assertErrorEnvelope($payload, 'pdf_not_available');
        $this->assertSame('pending', $payload['error']['details']['current_status']);
        $this->assertContains('approved', $payload['error']['details']['available_from_statuses']);
    }

    public function testStatusesBeforeConfirmationAreAllRefused(): void
    {
        $student = $this->actingAs('student');
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $order = $this->seedOrder(['status' => $status, 'user_id' => $student->id]);
            [$code, $payload] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf");
            $this->assertSame(409, $code, "stato {$status}");
            $this->assertErrorEnvelope($payload, 'pdf_not_available');
        }
    }

    public function testConfirmedOrLaterStatusesAllProduceAForm(): void
    {
        $student = $this->actingAs('student');
        foreach (OrderPdfService::PRINTABLE_STATUSES as $status) {
            $order = $this->seedOrder(['status' => $status, 'user_id' => $student->id]);
            [$code, , $response] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf");
            $this->assertSame(200, $code, "stato {$status}");
            $this->assertStringStartsWith('%PDF', (string) $response->getBody());
        }
    }

    public function testTokenQueryParamAuthenticatesTheDownload(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();
        $order = $this->seedOrder(['status' => 'approved', 'user_id' => $student->id]);
        $token = $this->container()->get(JwtService::class)->issueAccessToken($student)['token'];

        $this->anonymous();
        [$status, , $response] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf?token=" . urlencode($token));

        $this->assertSame(200, $status);
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getBody());
    }

    public function testAnonymousOrBadTokenIsUnauthenticated(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();
        $order = $this->seedOrder(['status' => 'approved', 'user_id' => $student->id]);

        $this->anonymous();
        [$status, $payload] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf");
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($payload);

        [$status, $payload] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf?token=not-a-jwt");
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($payload);
    }

    public function testFilenameFallsBackToTheOrderIdWhenThereIsNoCode(): void
    {
        $student = $this->actingAs('student');
        $order = $this->seedOrder(['status' => 'approved', 'user_id' => $student->id]);
        $order->code = null;
        $order->save();

        [$status, , $response] = $this->json('GET', "/api/v1/orders/{$order->id}/pdf");
        $this->assertSame(200, $status);
        $this->assertSame(
            'inline; filename="modulo-ordine-' . $order->id . '.pdf"',
            $response->getHeaderLine('Content-Disposition')
        );
    }
}
