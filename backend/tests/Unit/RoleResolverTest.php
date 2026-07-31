<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\LdapUser;
use App\Domain\Auth\RealLdapAuthenticator;
use App\Domain\Auth\RoleResolver;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Tests\TestCase;

final class RoleResolverTest extends TestCase
{
    private function resolver(): RoleResolver
    {
        return new RoleResolver(SettingsRepository::instance());
    }

    private function ldapUser(array $groups): LdapUser
    {
        return new LdapUser('someone', null, null, null, null, $groups);
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function defaultMap(): array
    {
        return [
            'admin' => ['cn=vlab-admin,ou=groups,dc=polito,dc=it', 'admin'],
            'technician' => ['cn=tecnici,ou=groups,dc=polito,dc=it', 'technician'],
            'assistant' => ['cn=borsisti,ou=groups,dc=polito,dc=it', 'assistant'],
            'student' => ['cn=studenti,ou=groups,dc=polito,dc=it', 'student'],
        ];
    }

    /** @dataProvider defaultMap */
    public function testGroupToRoleMappingForDefaultMapEntries(string $group, string $expected): void
    {
        $this->assertSame($expected, $this->resolver()->resolve($this->ldapUser([$group]), null));
    }

    public function testRoleLockedKeepsLocalRole(): void
    {
        $user = User::where('ldap_uid', 'student1')->first();
        $user->role = 'admin';
        $user->role_locked = true;
        $user->save();
        $resolved = $this->resolver()->resolve($this->ldapUser(['cn=studenti,ou=groups,dc=polito,dc=it']), $user);
        $this->assertSame('admin', $resolved);
    }

    public function testNoMatchingGroupFallsBackToDefaultRole(): void
    {
        $this->assertSame('student', $this->resolver()->resolve($this->ldapUser(['cn=unknown,ou=x,dc=y']), null));
        $this->setSetting('ldap.default_role', 'technician');
        $this->assertSame('technician', $this->resolver()->resolve($this->ldapUser([]), null));
    }

    public function testMatchingIsCaseInsensitiveAndBareCnPrefix(): void
    {
        $this->assertSame('technician', $this->resolver()->resolve($this->ldapUser(['CN=Tecnici,OU=Groups,DC=Polito,DC=It']), null));
        // Bare cn= key matching a full DN.
        $this->setSetting('ldap.role_map', ['cn=tecnici' => 'technician']);
        $this->assertSame('technician', $this->resolver()->resolve($this->ldapUser(['cn=tecnici,ou=groups,dc=polito,dc=it']), null));
        // But not a different cn.
        $this->assertSame('student', $this->resolver()->resolve($this->ldapUser(['cn=tecnici2,ou=groups,dc=polito,dc=it']), null));
    }

    public function testFirstMatchInMapOrderWins(): void
    {
        $this->setSetting('ldap.role_map', [
            'cn=gruppo-a' => 'admin',
            'cn=gruppo-b' => 'student',
        ]);
        $resolved = $this->resolver()->resolve($this->ldapUser([
            'cn=gruppo-b,ou=groups,dc=polito,dc=it',
            'cn=gruppo-a,ou=groups,dc=polito,dc=it',
        ]), null);
        $this->assertSame('admin', $resolved);
    }

    /**
     * SPEC test #15: RealLdapAuthenticator reads EVERY parameter from settings
     * (asserted via reflection — no network, works without ext-ldap).
     */
    public function testRealLdapAuthenticatorReadsAllParametersFromSettings(): void
    {
        $expected = [
            'host' => 'ldap.example.org',
            'port' => 6389,
            'encryption' => 'tls',
            'base_dn' => 'dc=example,dc=org',
            'bind_dn' => 'cn=svc,dc=example,dc=org',
            'bind_password' => 's3cret',
            'user_filter' => '(sAMAccountName=%s)',
            'attr_uid' => 'sAMAccountName',
            'attr_email' => 'userPrincipalName',
            'attr_first_name' => 'gn',
            'attr_last_name' => 'surname',
            'attr_display_name' => 'displayName',
            'attr_matricola' => 'polito-matricola',
            'attr_groups' => 'groupMembership',
            'group_base_dn' => 'ou=groups,dc=example,dc=org',
            'group_filter' => '(&(objectClass=group)(member=%s))',
            'timeout_seconds' => 9,
        ];
        foreach ($expected as $key => $value) {
            $this->setSetting('ldap.' . $key, $value);
        }
        $reflection = new \ReflectionClass(RealLdapAuthenticator::class);
        /** @var RealLdapAuthenticator $auth */
        $auth = $reflection->newInstanceWithoutConstructor();
        $settingsProp = $reflection->getProperty('settings');
        $settingsProp->setValue($auth, SettingsRepository::instance());
        $configProp = $reflection->getProperty('config');
        $configProp->setValue($auth, ['ldap' => ['host' => 'env-host-should-not-win', 'port' => 1]]);
        $this->assertSame($expected, $auth->resolvedParams());
        $this->assertSame('real', $auth->mode());
    }

    public function testRealLdapEnvValuesUsedOnlyWhenSettingEmpty(): void
    {
        $this->setSetting('ldap.host', '');
        $reflection = new \ReflectionClass(RealLdapAuthenticator::class);
        $auth = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('settings')->setValue($auth, SettingsRepository::instance());
        $reflection->getProperty('config')->setValue($auth, ['ldap' => ['host' => 'env-fallback.example.org']]);
        $this->assertSame('env-fallback.example.org', $auth->resolvedParams()['host']);
    }

    public function testLdapUnavailableSurfacesAs503(): void
    {
        // Real mode without ext-ldap (or with no host) must yield 503 on login.
        if (extension_loaded('ldap')) {
            $this->setSetting('ldap.host', ''); // no host => LdapUnavailableException on connect
        }
        // Force mode: env LDAP_MODE=fake takes precedence, so build the container path manually.
        $authenticator = extension_loaded('ldap')
            ? new RealLdapAuthenticator(SettingsRepository::instance(), ['ldap' => []])
            : null;
        if ($authenticator !== null) {
            $this->expectException(\App\Domain\Auth\LdapUnavailableException::class);
            $authenticator->authenticate('user', 'pass');
        } else {
            $this->expectException(\App\Domain\Auth\LdapUnavailableException::class);
            new RealLdapAuthenticator(SettingsRepository::instance(), ['ldap' => []]);
        }
    }
}
