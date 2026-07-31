<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Optional capability of an LDAP authenticator: resolving a username through
 * the directory WITHOUT the user's credentials (service bind only). Used by
 * the staff manual-loan flow to provision a user that never logged in.
 * Implementations that cannot search on behalf of the service simply don't
 * implement this interface and manual creation falls back to local users only.
 */
interface LdapDirectoryLookupInterface
{
    /**
     * @throws LdapUnavailableException when the directory cannot be reached / bound.
     * @return LdapUser|null null == no such (active) user in the directory
     */
    public function lookupUsername(string $username): ?LdapUser;
}
