<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Calendar\IcalService;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Dates;
use Tests\TestCase;

/**
 * Obfuscated rotating iCal feed (owner request: "il calendario deve sputare
 * fuori un iCal con offuscamento link e un tasto per ruotare il link").
 */
final class IcalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-01 08:00:00');
    }

    /** Mint a feed token for a user through the authenticated endpoint. */
    private function tokenFor(string $role): string
    {
        $this->actingAs($role);
        [$status, $payload] = $this->json('GET', '/api/v1/me/ical');
        $this->assertSame(200, $status);
        return (string) $payload['token'];
    }

    /** @return string raw .ics body */
    private function fetchFeed(string $token, int $expectStatus = 200): string
    {
        $this->anonymous();
        [$status, , $response] = $this->json('GET', "/api/v1/ical/{$token}.ics");
        $this->assertSame($expectStatus, $status);
        return (string) $response->getBody();
    }

    // ------------------------------------------------------------- basics ---

    public function testMeIcalMintsTokenLazilyAndIsStable(): void
    {
        $user = $this->actingAs('student');
        $this->assertNull($user->fresh()->ical_token);

        [$status, $first] = $this->json('GET', '/api/v1/me/ical');
        $this->assertSame(200, $status);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['token']);
        $this->assertStringEndsWith('/api/v1/ical/' . $first['token'] . '.ics', $first['feed_url']);

        [, $second] = $this->json('GET', '/api/v1/me/ical');
        $this->assertSame($first['token'], $second['token'], 'reading the URL must not rotate it');
    }

    public function testFeedResolvesWithCalendarContentTypeAndVevents(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();
        $this->seedOrder(['user_id' => $student->id, 'status' => 'approved']);

        $token = $this->tokenFor('student');

        $this->anonymous();
        [$status, , $response] = $this->json('GET', "/api/v1/ical/{$token}.ics");
        $this->assertSame(200, $status);
        $this->assertSame('text/calendar; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $body);
        $this->assertStringContainsString('VERSION:2.0', $body);
        $this->assertStringContainsString('PRODID:', $body);
        // Pickup + return = two events for one order.
        $this->assertSame(2, substr_count($body, 'BEGIN:VEVENT'));
        $this->assertSame(2, substr_count($body, 'END:VEVENT'));
        $this->assertStringContainsString('STATUS:CONFIRMED', $body);
    }

    public function testPendingOrderIsTentativeAndApprovedIsConfirmed(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();
        $this->seedOrder(['user_id' => $student->id, 'status' => 'pending']);
        $body = $this->fetchFeed($this->tokenFor('student'));
        $this->assertStringContainsString('STATUS:TENTATIVE', $body);
        $this->assertStringNotContainsString('STATUS:CONFIRMED', $body);
    }

    public function testUnknownTokenIs404(): void
    {
        $this->anonymous();
        [$status, $payload] = $this->json('GET', '/api/v1/ical/' . str_repeat('a', 64) . '.ics');
        $this->assertSame(404, $status);
        $this->assertErrorEnvelope($payload, 'not_found');
    }

    public function testMalformedTokenDoesNotMatchTheRoute(): void
    {
        $this->anonymous();
        [$status] = $this->json('GET', '/api/v1/ical/nope.ics');
        $this->assertSame(404, $status);
    }

    // ------------------------------------------------------------ rotation ---

    public function testRotationInvalidatesTheOldTokenAndOldUrl404s(): void
    {
        $old = $this->tokenFor('student');
        $this->assertSame(200, $this->statusOf($old));

        $this->actingAs('student');
        [$status, $payload] = $this->json('POST', '/api/v1/me/ical/rotate');
        $this->assertSame(200, $status);
        $new = (string) $payload['token'];
        $this->assertNotSame($old, $new);
        $this->assertStringContainsString($new, (string) $payload['feed_url']);

        $this->assertSame(404, $this->statusOf($old), 'the previous link must stop working');
        $this->assertSame(200, $this->statusOf($new));

        // Single active token per user.
        $this->assertSame(1, User::where('ical_token', $new)->count());
        $this->assertSame(0, User::where('ical_token', $old)->count());
    }

    private function statusOf(string $token): int
    {
        $this->anonymous();
        [$status] = $this->json('GET', "/api/v1/ical/{$token}.ics");
        return $status;
    }

    public function testFeedEndpointsRequireAuth(): void
    {
        $this->anonymous();
        [$status] = $this->json('GET', '/api/v1/me/ical');
        $this->assertSame(401, $status);
        [$status] = $this->json('POST', '/api/v1/me/ical/rotate');
        $this->assertSame(401, $status);
    }

    // -------------------------------------------------------------- scoping ---

    public function testStudentFeedContainsOnlyOwnOrders(): void
    {
        $mine = User::where('ldap_uid', 'student1')->first();
        $other = User::where('ldap_uid', 'student2')->first();
        $this->assertNotNull($other);

        $a = $this->seedOrder(['user_id' => $mine->id, 'status' => 'approved']);
        $b = $this->seedOrder(['user_id' => $other->id, 'status' => 'approved']);

        $body = $this->fetchFeed($this->tokenFor('student'));
        $this->assertStringContainsString('vlab-order-' . $a->id . '-pickup@', $body);
        $this->assertStringNotContainsString('vlab-order-' . $b->id . '-pickup@', $body);
    }

    public function testStaffFeedIncludesOtherUsersOrders(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();
        $order = $this->seedOrder(['user_id' => $student->id, 'status' => 'approved']);

        $body = $this->fetchFeed($this->tokenFor('technician'));
        $this->assertStringContainsString('vlab-order-' . $order->id . '-pickup@', $body);
        // The lab calendar names who is coming.
        $this->assertStringContainsString('Richiedente: ', $body);
    }

    public function testStaffFeedRespectsTheRollingWindow(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();
        $today = Dates::todayInTz('Europe/Rome');

        $inWindow = $this->seedOrder([
            'user_id' => $student->id,
            'status' => 'approved',
            'pickup_date' => Dates::addDays($today, 10),
            'return_date' => Dates::addDays($today, 12),
        ]);
        $tooOld = $this->seedOrder([
            'user_id' => $student->id,
            'status' => 'approved',
            'pickup_date' => Dates::addDays($today, -200),
            'return_date' => Dates::addDays($today, -190),
        ]);
        $tooFar = $this->seedOrder([
            'user_id' => $student->id,
            'status' => 'approved',
            'pickup_date' => Dates::addDays($today, 300),
            'return_date' => Dates::addDays($today, 305),
        ]);

        $body = $this->fetchFeed($this->tokenFor('technician'));
        $this->assertStringContainsString('vlab-order-' . $inWindow->id . '-pickup@', $body);
        $this->assertStringNotContainsString('vlab-order-' . $tooOld->id . '-pickup@', $body);
        $this->assertStringNotContainsString('vlab-order-' . $tooFar->id . '-pickup@', $body);
    }

    public function testTerminalOrdersAreNotInTheFeed(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();
        $returned = $this->seedOrder(['user_id' => $student->id, 'status' => 'returned']);
        $cancelled = $this->seedOrder(['user_id' => $student->id, 'status' => 'cancelled']);

        $body = $this->fetchFeed($this->tokenFor('student'));
        $this->assertStringNotContainsString('vlab-order-' . $returned->id . '-', $body);
        $this->assertStringNotContainsString('vlab-order-' . $cancelled->id . '-', $body);
    }

    // -------------------------------------------------------------- format ---

    public function testUidsAreStableAcrossRequestsAndRotation(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();
        $order = $this->seedOrder(['user_id' => $student->id, 'status' => 'approved']);

        $token = $this->tokenFor('student');
        $first = $this->fetchFeed($token);

        $this->actingAs('student');
        [, $payload] = $this->json('POST', '/api/v1/me/ical/rotate');
        $second = $this->fetchFeed((string) $payload['token']);

        $this->assertSame(self::uids($first), self::uids($second));
        $this->assertSame([
            'vlab-order-' . $order->id . '-pickup@visionarylab.polito.it',
            'vlab-order-' . $order->id . '-return@visionarylab.polito.it',
        ], self::uids($first));
    }

    public function testTextEscapingOfCommasSemicolonsAndNewlines(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();
        $product = $this->seedProduct(['name' => 'Cavo XLR, 5m; nero']);
        $order = $this->seedOrder([
            'user_id' => $student->id,
            'status' => 'approved',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        OrderItem::where('order_id', $order->id)->update(['product_name_snapshot' => 'Cavo XLR, 5m; nero']);

        $body = $this->fetchFeed($this->tokenFor('student'));
        $unfolded = self::unfold($body);

        $this->assertStringContainsString('Cavo XLR\\, 5m\\; nero', $unfolded);
        // The raw punctuation never survives unescaped inside a DESCRIPTION.
        foreach (explode("\n", $unfolded) as $line) {
            if (!str_starts_with($line, 'DESCRIPTION:')) {
                continue;
            }
            $value = substr($line, strlen('DESCRIPTION:'));
            $this->assertDoesNotMatchRegularExpression('/(?<!\\\\),/', $value);
            $this->assertDoesNotMatchRegularExpression('/(?<!\\\\);/', $value);
            $this->assertStringContainsString('\\n', $value, 'newlines are encoded, not literal');
        }
    }

    public function testEveryLineUsesCrlfAndFoldsAt75Octets(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();
        $product = $this->seedProduct([
            'name' => 'Videocamera professionale con obiettivo grandangolare e valigia rigida rinforzata',
        ]);
        $order = $this->seedOrder([
            'user_id' => $student->id,
            'status' => 'approved',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        OrderItem::where('order_id', $order->id)->update(['product_name_snapshot' => $product->name]);

        $body = $this->fetchFeed($this->tokenFor('student'));

        $this->assertSame(0, preg_match('/(?<!\r)\n/', $body), 'a bare LF slipped in');
        $lines = explode("\r\n", rtrim($body, "\r\n"));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'unfolded line: ' . $line);
        }
        // Folding actually happened and round-trips.
        $this->assertGreaterThan(0, count(array_filter($lines, static fn ($l) => str_starts_with($l, ' '))));
        $this->assertStringContainsString($product->name, self::unfold($body));
    }

    public function testTimesAreConvertedFromLabTimezoneToUtc(): void
    {
        $student = User::where('ldap_uid', 'student1')->first();
        // 2026-07-15 is CEST (UTC+2): 09:30 local becomes 07:30Z.
        $this->seedOrder([
            'user_id' => $student->id,
            'status' => 'approved',
            'pickup_date' => '2026-07-15',
            'pickup_time' => '09:30',
            'return_date' => '2026-12-15',
            'return_time' => '09:30',
        ]);

        $body = $this->fetchFeed($this->tokenFor('student'));
        $this->assertStringContainsString('DTSTART:20260715T073000Z', $body);
        // 2026-12-15 is CET (UTC+1): 09:30 local becomes 08:30Z — DST handled.
        $this->assertStringContainsString('DTSTART:20261215T083000Z', $body);
        $this->assertStringNotContainsString('BEGIN:VTIMEZONE', $body);
    }

    public function testLocationComesFromLabSettings(): void
    {
        $this->setSetting('lab.room', 'Aula 3B');
        $this->setSetting('lab.address', 'Corso Duca degli Abruzzi 24, Torino');
        $student = User::where('ldap_uid', 'student1')->first();
        $this->seedOrder(['user_id' => $student->id, 'status' => 'approved']);

        $body = self::unfold($this->fetchFeed($this->tokenFor('student')));
        $this->assertStringContainsString('LOCATION:Aula 3B\\, Corso Duca degli Abruzzi 24\\, Torino', $body);
    }

    public function testDisabledAccountFeedStopsResolving(): void
    {
        $token = $this->tokenFor('student');
        $this->assertSame(200, $this->statusOf($token));
        $user = User::where('ldap_uid', 'student1')->first();
        $user->is_active = false;
        $user->save();
        $this->assertSame(404, $this->statusOf($token));
    }

    // --------------------------------------------------------------- units ---

    public function testEscapeHelperIsRfc5545Compliant(): void
    {
        $this->assertSame('a\\\\b', IcalService::escape('a\\b'));
        $this->assertSame('a\\,b', IcalService::escape('a,b'));
        $this->assertSame('a\\;b', IcalService::escape('a;b'));
        $this->assertSame('a\\nb', IcalService::escape("a\nb"));
        $this->assertSame('a\\nb', IcalService::escape("a\r\nb"));
    }

    public function testFoldHelperNeverSplitsMultibyteCharacters(): void
    {
        $line = 'SUMMARY:' . str_repeat('è', 80);
        $folded = IcalService::fold($line);
        foreach (explode("\r\n", $folded) as $part) {
            $this->assertLessThanOrEqual(75, strlen($part));
        }
        $this->assertSame($line, str_replace("\r\n ", '', $folded));
    }

    // ------------------------------------------------------------- helpers ---

    /** @return array<int,string> */
    private static function uids(string $body): array
    {
        preg_match_all('/^UID:(.+)$/m', self::unfold($body), $m);
        return array_map(static fn (string $s) => rtrim($s, "\r"), $m[1]);
    }

    private static function unfold(string $body): string
    {
        return str_replace("\r\n ", '', $body);
    }
}
