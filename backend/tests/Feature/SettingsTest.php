<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Settings\SettingsRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Tests\TestCase;

final class SettingsTest extends TestCase
{
    public function testSeederCreatesDocumentedKeys(): void
    {
        $rows = SettingsRepository::instance()->rows();
        $expectations = [
            'lab.name' => ['string', 'lab', 'Visionary Lab', true, false],
            'hours.timezone' => ['string', 'hours', 'Europe/Rome', true, false],
            'hours.slot_duration_minutes' => ['int', 'hours', 30, true, false],
            'booking.max_loan_days' => ['int', 'booking', 7, true, false],
            'booking.max_loan_days_hard_cap' => ['int', 'booking', 30, true, true],
            'booking.max_orders_per_month' => ['int', 'booking', 4, true, true],
            'booking.max_orders_per_year' => ['int', 'booking', null, true, true],
            'booking.max_active_orders' => ['int', 'booking', 2, true, true],
            'booking.pending_locks_stock' => ['bool', 'booking', true, false, false],
            'booking.cart_ttl_hours' => ['int', 'booking', 72, false, false],
            'regulations.enforce_global_acceptance' => ['bool', 'regulations', true, true, false],
            'ldap.mode' => ['enum', 'ldap', 'fake', false, false],
            'ldap.bind_password' => ['secret', 'ldap', '', false, false],
            'security.jwt_ttl_minutes' => ['int', 'security', 480, false, false],
            'ui.items_per_page' => ['int', 'ui', 24, true, false],
            'ui.allow_anonymous_catalog' => ['bool', 'ui', true, true, false],
            'stats.default_range_days' => ['int', 'stats', 90, false, false],
        ];
        foreach ($expectations as $key => [$type, $group, $default, $public, $nullable]) {
            $this->assertArrayHasKey($key, $rows, $key);
            $row = $rows[$key];
            $this->assertSame($type, $row['type'], $key);
            $this->assertSame($group, $row['group'], $key);
            $this->assertSame($default, SettingsRepository::decodeValue($row['value']), $key);
            $this->assertSame($public, (bool) $row['is_public'], $key . ' public');
            $this->assertSame($nullable, (bool) $row['nullable'], $key . ' nullable');
        }
        $this->assertGreaterThanOrEqual(85, count($rows));
        // hours.weekly default has 7 entries with Sunday=0 closed.
        $weekly = SettingsRepository::instance()->get('hours.weekly');
        $this->assertCount(7, $weekly);
        $this->assertTrue($weekly[0]['closed']);
        $this->assertSame('09:00', $weekly[1]['open']);
    }

    public function testSeederIsIdempotentAndPreservesModifiedValues(): void
    {
        $this->setSetting('booking.max_loan_days', 12);
        Capsule::table('settings')->where('key', 'stats.top_products_limit')->delete();
        SettingsRepository::reset();
        (new \SettingsSeeder())->run();
        SettingsRepository::reset();
        $this->assertSame(12, SettingsRepository::instance()->get('booking.max_loan_days'));
        $this->assertSame(10, SettingsRepository::instance()->get('stats.top_products_limit'));
    }

    public function testPublicSettingsOnlyPublicAndNeverSecrets(): void
    {
        [$status, $payload] = $this->json('GET', '/api/v1/settings/public');
        $this->assertSame(200, $status);
        $this->assertSame('Visionary Lab', $payload['lab.name']);
        $this->assertSame(7, $payload['booking.max_loan_days']);
        $this->assertNull($payload['booking.max_orders_per_year']);
        $this->assertArrayNotHasKey('ldap.bind_password', $payload);
        $this->assertArrayNotHasKey('ldap.host', $payload);
        $this->assertArrayNotHasKey('security.jwt_ttl_minutes', $payload);
        $this->assertArrayNotHasKey('booking.pending_locks_stock', $payload);
    }

    public function testStaffSettingsHideLdapSecurityAndSecrets(): void
    {
        foreach (['technician', 'assistant'] as $role) {
            $this->actingAs($role);
            [$status, $payload] = $this->json('GET', '/api/v1/settings');
            $this->assertSame(200, $status, $role);
            $groups = array_unique(array_map(static fn ($s) => $s['group'], $payload['data']));
            $this->assertNotContains('ldap', $groups, $role);
            $this->assertNotContains('security', $groups, $role);
            foreach ($payload['data'] as $setting) {
                $this->assertFalse($setting['is_secret'], $role);
            }
            $groupKeys = array_map(static fn ($g) => $g['key'], $payload['groups']);
            $this->assertNotContains('ldap', $groupKeys);
        }
        $this->actingAs('admin');
        [, $payload] = $this->json('GET', '/api/v1/settings');
        $groups = array_unique(array_map(static fn ($s) => $s['group'], $payload['data']));
        $this->assertContains('ldap', $groups);
        $this->assertContains('security', $groups);
        // Secrets are redacted even for admin.
        foreach ($payload['data'] as $setting) {
            if ($setting['is_secret']) {
                $this->assertSame('********', $setting['value']);
            }
        }
    }

    public function testPutSettingsRequiresAdmin(): void
    {
        foreach (['technician', 'assistant'] as $role) {
            $this->actingAs($role);
            [$status, $payload] = $this->json('PUT', '/api/v1/settings', ['settings' => ['booking.max_loan_days' => 10]]);
            $this->assertSame(403, $status, $role);
            $this->assertErrorEnvelope($payload);
        }
        $this->actingAs('student');
        [$status] = $this->json('PUT', '/api/v1/settings', ['settings' => ['booking.max_loan_days' => 10]]);
        $this->assertSame(403, $status);
    }

    public function testUnknownKeyRejectedAtomically(): void
    {
        $this->actingAs('admin');
        [$status, $payload] = $this->json('PUT', '/api/v1/settings', ['settings' => [
            'booking.max_loan_days' => 10,
            'booking.totally_unknown' => 1,
        ]]);
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'unknown_setting_key');
        $this->assertSame(['booking.totally_unknown'], $payload['error']['details']['keys']);
        // Nothing was written.
        SettingsRepository::reset();
        $this->assertSame(7, SettingsRepository::instance()->get('booking.max_loan_days'));
    }

    public function testTypeValidation(): void
    {
        $this->actingAs('admin');
        // string into an int key.
        [$status, $payload] = $this->json('PUT', '/api/v1/settings', ['settings' => ['booking.max_loan_days' => 'dieci']]);
        $this->assertSame(422, $status);
        $this->assertErrorEnvelope($payload, 'validation_failed');
        // null into non-nullable.
        [$status] = $this->json('PUT', '/api/v1/settings', ['settings' => ['booking.max_loan_days' => null]]);
        $this->assertSame(422, $status);
        // null into nullable is fine.
        [$status, $payload] = $this->json('PUT', '/api/v1/settings', ['settings' => ['booking.max_orders_per_month' => null]]);
        $this->assertSame(200, $status, json_encode($payload));
        SettingsRepository::reset();
        $this->assertNull(SettingsRepository::instance()->get('booking.max_orders_per_month'));
        // enum outside options.
        [$status] = $this->json('PUT', '/api/v1/settings', ['settings' => ['ui.banner_level' => 'panic']]);
        $this->assertSame(422, $status);
        // bool must be a JSON boolean.
        [$status] = $this->json('PUT', '/api/v1/settings', ['settings' => ['booking.require_motivation' => 'yes']]);
        $this->assertSame(422, $status);
    }

    public function testWeeklyShapeValidation(): void
    {
        $this->actingAs('admin');
        $valid = SettingsRepository::instance()->get('hours.weekly');
        // 6 entries.
        [$status] = $this->json('PUT', '/api/v1/settings', ['settings' => ['hours.weekly' => array_slice($valid, 0, 6)]]);
        $this->assertSame(422, $status);
        // duplicate weekday.
        $dup = $valid;
        $dup[6]['weekday'] = 5;
        [$status] = $this->json('PUT', '/api/v1/settings', ['settings' => ['hours.weekly' => $dup]]);
        $this->assertSame(422, $status);
        // open >= close.
        $bad = $valid;
        $bad[1]['open'] = '18:00';
        [$status] = $this->json('PUT', '/api/v1/settings', ['settings' => ['hours.weekly' => $bad]]);
        $this->assertSame(422, $status);
        // valid payload passes.
        [$status] = $this->json('PUT', '/api/v1/settings', ['settings' => ['hours.weekly' => $valid]]);
        $this->assertSame(200, $status);
    }

    public function testSecretRoundTrip(): void
    {
        $this->actingAs('admin');
        [$status] = $this->json('PUT', '/api/v1/settings/ldap.bind_password', ['value' => 'super-secret']);
        $this->assertSame(200, $status);
        SettingsRepository::reset();
        $this->assertSame('super-secret', SettingsRepository::instance()->get('ldap.bind_password'));
        // GET always redacts.
        [, $payload] = $this->json('GET', '/api/v1/settings?group=ldap');
        foreach ($payload['data'] as $setting) {
            if ($setting['key'] === 'ldap.bind_password') {
                $this->assertSame('********', $setting['value']);
            }
        }
        // PUT with the mask leaves the stored value unchanged.
        [$status] = $this->json('PUT', '/api/v1/settings', ['settings' => ['ldap.bind_password' => '********']]);
        $this->assertSame(200, $status);
        SettingsRepository::reset();
        $this->assertSame('super-secret', SettingsRepository::instance()->get('ldap.bind_password'));
    }

    public function testSettingChangeImmediatelyAffectsAvailabilityCheck(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $product = $this->seedProduct([], 3);
        $this->actingAs('admin');
        [$status] = $this->json('PUT', '/api/v1/settings/booking.max_loan_days', ['value' => 3]);
        $this->assertSame(200, $status);
        $this->actingAs('student');
        [, $check] = $this->json('POST', '/api/v1/availability/check', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'pickup_date' => '2026-09-07', 'pickup_time' => '09:30',
            'return_date' => '2026-09-11', 'return_time' => '14:00',
        ]);
        $codes = array_map(static fn ($v) => $v['code'], $check['violations']);
        $this->assertContains('max_loan_days_exceeded', $codes);
        $this->assertSame(3, $check['violations'][0]['limit']);
    }

    public function testDomainServicesReadConstantsFromSettingsSmoke(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        // 1. items_per_page drives default pagination.
        $this->setSetting('ui.items_per_page', 5);
        $this->seedProduct([], 1);
        [, $payload] = $this->json('GET', '/api/v1/products');
        $this->assertSame(5, $payload['meta']['per_page']);
        // 2. min/max advance drive the booking window.
        $this->setSetting('booking.min_advance_days', 5);
        $this->setSetting('booking.max_advance_days', 20);
        [, $opening] = $this->json('GET', '/api/v1/calendar/opening');
        $this->assertSame('2026-09-06', $opening['booking_window']['min_date']);
        $this->assertSame('2026-09-21', $opening['booking_window']['max_date']);
        // 3. slot duration drives slot slicing.
        $this->setSetting('hours.slot_duration_minutes', 105);
        [, $opening] = $this->json('GET', '/api/v1/calendar/opening?from=2026-09-07&to=2026-09-07');
        $this->assertSame(['start' => '09:00', 'end' => '10:45'], $opening['days'][0]['pickup_slots'][0]);
        // 4. jwt ttl drives token expiry.
        $this->setSetting('security.jwt_ttl_minutes', 1);
        [, $login] = $this->json('POST', '/api/v1/auth/login', ['username' => 'student1', 'password' => 'password']);
        $this->assertSame(60, $login['expires_in']);
        // 5. lab.name flows to public settings.
        $this->setSetting('lab.name', 'Laboratorio Rinominato');
        [, $public] = $this->json('GET', '/api/v1/settings/public');
        $this->assertSame('Laboratorio Rinominato', $public['lab.name']);
    }
}
