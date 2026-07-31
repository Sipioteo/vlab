<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Support\Enums;

/**
 * SPEC §4.1: local override wins; then ldap.role_map (insertion order,
 * first match wins, case-insensitive, bare cn= prefix allowed); then
 * ldap.default_role.
 */
final class RoleResolver
{
    public function __construct(private SettingsRepository $settings)
    {
    }

    public function resolve(LdapUser $ldapUser, ?User $existing): string
    {
        if ($existing !== null && $existing->role_locked) {
            return (string) $existing->role;
        }

        $map = $this->settings->get('ldap.role_map', []);
        if (is_array($map)) {
            foreach ($map as $groupKey => $role) {
                if (!in_array($role, Enums::ROLES, true)) {
                    continue;
                }
                foreach ($ldapUser->groups as $group) {
                    if ($this->matches((string) $groupKey, (string) $group)) {
                        return $role;
                    }
                }
            }
        }

        $default = $this->settings->get('ldap.default_role', 'student');
        return in_array($default, Enums::ROLES, true) ? $default : 'student';
    }

    /** Case-insensitive: equal, or the key is a bare `cn=xxx` prefix of the DN. */
    private function matches(string $key, string $group): bool
    {
        $key = strtolower(trim($key));
        $group = strtolower(trim($group));
        if ($key === '' || $group === '') {
            return false;
        }
        return $group === $key || str_starts_with($group, $key . ',');
    }
}
