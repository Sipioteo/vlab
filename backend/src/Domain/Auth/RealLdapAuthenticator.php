<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Settings\SettingsRepository;

/**
 * Production authenticator using ext-ldap. Every parameter is read from
 * settings (`ldap.*`); env values are used only as defaults when the setting
 * is empty (SPEC §4.2). Guarded so the platform runs without ext-ldap when
 * LDAP_MODE=fake: the extension check happens on use, not at class load.
 */
class RealLdapAuthenticator implements LdapAuthenticatorInterface
{
    /** @var array<string,mixed> */
    private array $config;

    private SettingsRepository $settings;

    /** @param array<string,mixed> $config boot config (env-derived defaults) */
    public function __construct(SettingsRepository $settings, array $config)
    {
        if (!extension_loaded('ldap')) {
            throw new LdapUnavailableException('php-ldap extension missing');
        }
        $this->settings = $settings;
        $this->config = $config;
    }

    /**
     * Resolve an ldap.* parameter: setting value, else env default, else fallback.
     */
    public function param(string $key, mixed $fallback = ''): mixed
    {
        $value = $this->settings->get('ldap.' . $key);
        if ($value !== null && $value !== '') {
            return $value;
        }
        $envDefaults = $this->config['ldap'] ?? [];
        if (isset($envDefaults[$key]) && $envDefaults[$key] !== '') {
            return $envDefaults[$key];
        }
        return $fallback;
    }

    /** @return array<string,mixed> the full resolved parameter set (also used by tests). */
    public function resolvedParams(): array
    {
        return [
            'host' => (string) $this->param('host'),
            'port' => (int) $this->param('port', 389),
            'encryption' => (string) $this->param('encryption', 'none'),
            'base_dn' => (string) $this->param('base_dn'),
            'bind_dn' => (string) $this->param('bind_dn'),
            'bind_password' => (string) $this->param('bind_password'),
            'user_filter' => (string) $this->param('user_filter', '(uid=%s)'),
            'attr_uid' => (string) $this->param('attr_uid', 'uid'),
            'attr_email' => (string) $this->param('attr_email', 'mail'),
            'attr_first_name' => (string) $this->param('attr_first_name', 'givenName'),
            'attr_last_name' => (string) $this->param('attr_last_name', 'sn'),
            'attr_display_name' => (string) $this->param('attr_display_name', 'cn'),
            'attr_matricola' => (string) $this->param('attr_matricola', 'employeeNumber'),
            'attr_groups' => (string) $this->param('attr_groups', 'memberOf'),
            'group_base_dn' => (string) $this->param('group_base_dn'),
            'group_filter' => (string) $this->param('group_filter', '(&(objectClass=groupOfNames)(member=%s))'),
            'timeout_seconds' => (int) $this->param('timeout_seconds', 5),
        ];
    }

    public function authenticate(string $username, string $password): ?LdapUser
    {
        $p = $this->resolvedParams();
        $conn = $this->connect($p);

        // Service bind (anonymous when bind_dn empty).
        $bound = $p['bind_dn'] !== ''
            ? @ldap_bind($conn, $p['bind_dn'], $p['bind_password'])
            : @ldap_bind($conn);
        if (!$bound) {
            throw new LdapUnavailableException('LDAP service bind failed: ' . ldap_error($conn));
        }

        $filter = sprintf($p['user_filter'], ldap_escape($username, '', LDAP_ESCAPE_FILTER));
        $search = @ldap_search($conn, $p['base_dn'], $filter);
        if ($search === false) {
            throw new LdapUnavailableException('LDAP search failed: ' . ldap_error($conn));
        }
        $entries = ldap_get_entries($conn, $search);
        if (!is_array($entries) || (int) ($entries['count'] ?? 0) !== 1) {
            return null;
        }
        $entry = $entries[0];
        $userDn = (string) $entry['dn'];

        // Re-bind as the found entry with the supplied password.
        if (!@ldap_bind($conn, $userDn, $password)) {
            return null;
        }

        $attr = static function (array $entry, string $name): ?string {
            $name = strtolower($name);
            if (isset($entry[$name]) && is_array($entry[$name]) && ($entry[$name]['count'] ?? 0) > 0) {
                return (string) $entry[$name][0];
            }
            return null;
        };

        $groups = [];
        if ($p['group_base_dn'] !== '') {
            $gFilter = sprintf($p['group_filter'], ldap_escape($userDn, '', LDAP_ESCAPE_FILTER));
            $gSearch = @ldap_search($conn, $p['group_base_dn'], $gFilter, ['dn']);
            if ($gSearch !== false) {
                $gEntries = ldap_get_entries($conn, $gSearch);
                for ($i = 0; $i < (int) ($gEntries['count'] ?? 0); $i++) {
                    $groups[] = (string) $gEntries[$i]['dn'];
                }
            }
        } else {
            $attrGroups = strtolower($p['attr_groups']);
            if (isset($entry[$attrGroups]) && is_array($entry[$attrGroups])) {
                for ($i = 0; $i < (int) ($entry[$attrGroups]['count'] ?? 0); $i++) {
                    $groups[] = (string) $entry[$attrGroups][$i];
                }
            }
        }

        return new LdapUser(
            uid: $attr($entry, $p['attr_uid']) ?? $username,
            email: $attr($entry, $p['attr_email']),
            firstName: $attr($entry, $p['attr_first_name']),
            lastName: $attr($entry, $p['attr_last_name']),
            displayName: $attr($entry, $p['attr_display_name']),
            groups: $groups,
            raw: ['dn' => $userDn, 'matricola' => $attr($entry, $p['attr_matricola'])],
        );
    }

    public function testConnection(): LdapTestResult
    {
        $p = $this->resolvedParams();
        $start = microtime(true);
        try {
            $conn = $this->connect($p);
            $bound = $p['bind_dn'] !== ''
                ? @ldap_bind($conn, $p['bind_dn'], $p['bind_password'])
                : @ldap_bind($conn);
            if (!$bound) {
                return new LdapTestResult(false, 'Bind fallito: ' . ldap_error($conn));
            }
            $latency = (int) round((microtime(true) - $start) * 1000);
            return new LdapTestResult(true, 'Connessione riuscita.', $latency, null);
        } catch (LdapUnavailableException $e) {
            return new LdapTestResult(false, $e->getMessage());
        }
    }

    public function mode(): string
    {
        return 'real';
    }

    /**
     * @param array<string,mixed> $p
     * @return resource|\LDAP\Connection
     */
    private function connect(array $p)
    {
        if ($p['host'] === '') {
            throw new LdapUnavailableException('LDAP host non configurato.');
        }
        $uri = ($p['encryption'] === 'ssl' ? 'ldaps://' : 'ldap://') . $p['host'] . ':' . $p['port'];
        $conn = @ldap_connect($uri);
        if ($conn === false) {
            throw new LdapUnavailableException('Impossibile connettersi al server LDAP.');
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $p['timeout_seconds']);
        if ($p['encryption'] === 'tls' && !@ldap_start_tls($conn)) {
            throw new LdapUnavailableException('start_tls fallito: ' . ldap_error($conn));
        }
        return $conn;
    }
}
