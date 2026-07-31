<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Auth\JwtService;
use App\Models\RefreshToken;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    /** @return array<string, array{0:string, 1:string}> */
    public static function seededUsers(): array
    {
        return [
            'student1' => ['student1', 'student'],
            'student2' => ['student2', 'student'],
            'tecnico1' => ['tecnico1', 'technician'],
            'borsista1' => ['borsista1', 'assistant'],
            'admin1' => ['admin1', 'admin'],
        ];
    }

    /** @dataProvider seededUsers */
    public function testLoginWithSeededUsersReturnsExpectedRole(string $username, string $role): void
    {
        [$status, $body] = $this->json('POST', '/api/v1/auth/login', ['username' => $username, 'password' => 'password']);
        $this->assertSame(200, $status);
        $this->assertSame($role, $body['user']['role']);
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertNotEmpty($body['access_token']);
        $this->assertNotEmpty($body['refresh_token']);
        $this->assertArrayHasKey('pending_regulations', $body);
    }

    public function testLoginWrongPasswordAndUnknownUserAreIndistinguishable(): void
    {
        [$status1, $body1] = $this->json('POST', '/api/v1/auth/login', ['username' => 'student1', 'password' => 'nope']);
        [$status2, $body2] = $this->json('POST', '/api/v1/auth/login', ['username' => 'ghost-user', 'password' => 'nope']);
        $this->assertSame(401, $status1);
        $this->assertSame(401, $status2);
        $this->assertErrorEnvelope($body1, 'invalid_credentials');
        $this->assertErrorEnvelope($body2, 'invalid_credentials');
        $this->assertSame($body1['error']['message'], $body2['error']['message']);
    }

    public function testLoginInactiveUserReturnsAccountDisabled(): void
    {
        User::where('ldap_uid', 'student1')->update(['is_active' => false]);
        [$status, $body] = $this->json('POST', '/api/v1/auth/login', ['username' => 'student1', 'password' => 'password']);
        $this->assertSame(403, $status);
        $this->assertErrorEnvelope($body, 'account_disabled');
    }

    public function testElevenFailedLoginsRateLimited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            [$status] = $this->json('POST', '/api/v1/auth/login', ['username' => 'student1', 'password' => 'wrong']);
            $this->assertSame(401, $status, "attempt {$i}");
        }
        [$status, $body] = $this->json('POST', '/api/v1/auth/login', ['username' => 'student1', 'password' => 'wrong']);
        $this->assertSame(429, $status);
        $this->assertErrorEnvelope($body, 'too_many_attempts');
    }

    public function testMeTokenErrors(): void
    {
        [$status, $body] = $this->json('GET', '/api/v1/auth/me');
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($body, 'unauthenticated');

        $this->token = 'not-a-jwt';
        [$status, $body] = $this->json('GET', '/api/v1/auth/me');
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($body, 'token_invalid');

        // Expired token.
        $user = User::where('ldap_uid', 'student1')->first();
        $payload = [
            'iss' => 'vlab', 'sub' => (string) $user->id, 'iat' => time() - 7200, 'exp' => time() - 3600,
            'uid' => 'student1', 'role' => 'student', 'ver' => 1,
        ];
        $this->token = JWT::encode($payload, 'test-secret-at-least-32-characters-long', 'HS256');
        [$status, $body] = $this->json('GET', '/api/v1/auth/me');
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($body, 'token_expired');
    }

    public function testTokenVersionBumpInvalidatesToken(): void
    {
        $user = $this->actingAs('student');
        [$status] = $this->json('GET', '/api/v1/auth/me');
        $this->assertSame(200, $status);
        $user->token_version = 2;
        $user->save();
        [$status, $body] = $this->json('GET', '/api/v1/auth/me');
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($body, 'token_stale');
    }

    public function testRefreshRotationAndReuseDetection(): void
    {
        [, $login] = $this->json('POST', '/api/v1/auth/login', ['username' => 'student1', 'password' => 'password']);
        $oldRefresh = $login['refresh_token'];

        [$status, $refreshed] = $this->json('POST', '/api/v1/auth/refresh', ['refresh_token' => $oldRefresh]);
        $this->assertSame(200, $status);
        $this->assertNotSame($oldRefresh, $refreshed['refresh_token']);

        // Presenting the consumed token again revokes the family.
        [$status, $body] = $this->json('POST', '/api/v1/auth/refresh', ['refresh_token' => $oldRefresh]);
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($body, 'refresh_reused');

        // The rotated token is now revoked too (family revocation).
        [$status, $body] = $this->json('POST', '/api/v1/auth/refresh', ['refresh_token' => $refreshed['refresh_token']]);
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($body, 'refresh_reused');
    }

    public function testLogoutRevokesRefreshToken(): void
    {
        [, $login] = $this->json('POST', '/api/v1/auth/login', ['username' => 'student1', 'password' => 'password']);
        $this->token = $login['access_token'];
        [$status] = $this->json('POST', '/api/v1/auth/logout', ['refresh_token' => $login['refresh_token']]);
        $this->assertSame(204, $status);
        [$status, $body] = $this->json('POST', '/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->assertSame(401, $status);
        $this->assertErrorEnvelope($body);
    }

    public function testFirstLoginCreatesSecondLoginUpdatesWithoutDuplicating(): void
    {
        \App\Models\FakeLdapUser::create([
            'username' => 'newstudent',
            'password_hash' => \FakeUsersSeeder::passwordHash(),
            'email' => 'new@studenti.polito.it',
            'display_name' => 'Nuovo Studente',
            'groups' => json_encode(['cn=studenti,ou=groups,dc=polito,dc=it']),
            'is_active' => true,
        ]);
        $this->assertNull(User::where('ldap_uid', 'newstudent')->first());
        [$status] = $this->json('POST', '/api/v1/auth/login', ['username' => 'newstudent', 'password' => 'password']);
        $this->assertSame(200, $status);
        $created = User::where('ldap_uid', 'newstudent')->first();
        $this->assertNotNull($created);
        $firstLogin = $created->last_login_at;

        \App\Models\FakeLdapUser::where('username', 'newstudent')->update(['display_name' => 'Nome Aggiornato']);
        $this->travelTo('2026-08-01 10:00:00');
        [$status] = $this->json('POST', '/api/v1/auth/login', ['username' => 'newstudent', 'password' => 'password']);
        $this->assertSame(200, $status);
        $this->assertSame(1, User::where('ldap_uid', 'newstudent')->count());
        $updated = User::where('ldap_uid', 'newstudent')->first();
        $this->assertSame('Nome Aggiornato', $updated->display_name);
        $this->assertNotEquals((string) $firstLogin, (string) $updated->last_login_at);
    }

    public function testJwtClaimsHonourTtlSetting(): void
    {
        $this->setSetting('security.jwt_ttl_minutes', 10);
        $user = User::where('ldap_uid', 'student1')->first();
        $jwt = $this->container()->get(JwtService::class);
        $issued = $jwt->issueAccessToken($user);
        $this->assertSame(600, $issued['expires_in']);
        $claims = (array) JWT::decode($issued['token'], new Key('test-secret-at-least-32-characters-long', 'HS256'));
        $this->assertSame((string) $user->id, $claims['sub']);
        $this->assertSame('student1', $claims['uid']);
        $this->assertSame('student', $claims['role']);
        $this->assertSame(1, $claims['ver']);
        $this->assertSame($claims['iat'] + 600, $claims['exp']);
    }

    public function testMeReturnsPermissionsCartAndActiveCounts(): void
    {
        $this->actingAs('student');
        [$status, $body] = $this->json('GET', '/api/v1/auth/me');
        $this->assertSame(200, $status);
        $this->assertTrue($body['permissions']['orders.create']);
        $this->assertFalse($body['permissions']['orders.manage']);
        $this->assertSame(0, $body['cart_items_count']);
        $this->assertSame(0, $body['active_orders_count']);
        // Students do not receive role_locked.
        $this->assertArrayNotHasKey('role_locked', $body['user']);
    }

    public function testPatchMeOnlyPhoneAndCourse(): void
    {
        $this->actingAs('student');
        [$status, $body] = $this->json('PATCH', '/api/v1/auth/me', ['phone' => '3401234567', 'course' => 'ICMC', 'role' => 'admin']);
        $this->assertSame(200, $status);
        $this->assertSame('3401234567', $body['user']['phone']);
        $this->assertSame('ICMC', $body['user']['course']);
        $this->assertSame('student', $body['user']['role']);
    }
}
