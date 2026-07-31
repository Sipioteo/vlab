<?php

declare(strict_types=1);

namespace App\Domain\Auth;

interface LdapAuthenticatorInterface
{
    /**
     * @throws LdapUnavailableException when the directory cannot be reached / bound.
     * @return LdapUser|null null == credentials rejected
     */
    public function authenticate(string $username, string $password): ?LdapUser;

    /** Connectivity probe used by POST /api/v1/settings/ldap/test. */
    public function testConnection(): LdapTestResult;

    /** 'fake' | 'real' */
    public function mode(): string;
}
