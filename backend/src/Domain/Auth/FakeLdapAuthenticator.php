<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Models\FakeLdapUser;

/**
 * Development/test authenticator backed by the fake_ldap_users table (SPEC §4.2).
 */
final class FakeLdapAuthenticator implements LdapAuthenticatorInterface
{
    public function authenticate(string $username, string $password): ?LdapUser
    {
        $row = FakeLdapUser::where('username', $username)->first();
        if ($row === null || !$row->is_active) {
            return null;
        }
        if (!password_verify($password, (string) $row->password_hash)) {
            return null;
        }
        $groups = [];
        if ($row->groups !== null && $row->groups !== '') {
            $decoded = json_decode((string) $row->groups, true);
            if (is_array($decoded)) {
                $groups = $decoded;
            }
        }
        return new LdapUser(
            uid: (string) $row->username,
            email: $row->email,
            firstName: $row->first_name,
            lastName: $row->last_name,
            displayName: $row->display_name,
            groups: $groups,
            raw: [],
        );
    }

    public function testConnection(): LdapTestResult
    {
        return new LdapTestResult(true, 'Fake LDAP attivo', 0, FakeLdapUser::count());
    }

    public function mode(): string
    {
        return 'fake';
    }
}
