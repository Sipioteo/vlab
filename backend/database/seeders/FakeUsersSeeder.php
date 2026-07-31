<?php

declare(strict_types=1);

use App\Models\FakeLdapUser;
use App\Models\User;

/**
 * Seeds the 5 fake LDAP users of SPEC §4.2 / §15.3 and the matching users
 * rows (role_source='seed', role_locked=false). Skipped in production.
 */
final class FakeUsersSeeder
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    /** @return array<int,array<string,mixed>> */
    public static function users(): array
    {
        return [
            ['username' => 'student1', 'first_name' => 'Marco', 'last_name' => 'Rossi', 'display_name' => 'Marco Rossi', 'email' => 'student1@studenti.polito.it', 'groups' => ['cn=studenti,ou=groups,dc=polito,dc=it'], 'role' => 'student'],
            ['username' => 'student2', 'first_name' => 'Giulia', 'last_name' => 'Bianchi', 'display_name' => 'Giulia Bianchi', 'email' => 'student2@studenti.polito.it', 'groups' => ['cn=studenti,ou=groups,dc=polito,dc=it'], 'role' => 'student'],
            ['username' => 'tecnico1', 'first_name' => 'Luca', 'last_name' => 'Ferrero', 'display_name' => 'Luca Ferrero', 'email' => 'tecnico1@polito.it', 'groups' => ['cn=tecnici,ou=groups,dc=polito,dc=it'], 'role' => 'technician'],
            ['username' => 'borsista1', 'first_name' => 'Sara', 'last_name' => 'Conti', 'display_name' => 'Sara Conti', 'email' => 'borsista1@polito.it', 'groups' => ['cn=borsisti,ou=groups,dc=polito,dc=it'], 'role' => 'assistant'],
            ['username' => 'admin1', 'first_name' => 'Anna', 'last_name' => 'Ricci', 'display_name' => 'Anna Ricci', 'email' => 'admin1@polito.it', 'groups' => ['cn=vlab-admin,ou=groups,dc=polito,dc=it'], 'role' => 'admin'],
        ];
    }

    /** Cached per process: bcrypt is expensive and the value is constant. */
    public static function passwordHash(): string
    {
        static $hash = null;
        if ($hash === null) {
            $hash = password_hash('password', PASSWORD_DEFAULT);
        }
        return $hash;
    }

    public function run(?callable $out = null): void
    {
        $env = (string) ($this->config['app']['env'] ?? ($_ENV['APP_ENV'] ?? 'local'));
        if ($env === 'production') {
            if ($out !== null) {
                $out('FakeUsersSeeder: saltato in produzione.');
            }
            return;
        }
        foreach (self::users() as $data) {
            $fake = FakeLdapUser::where('username', $data['username'])->first();
            if ($fake === null) {
                FakeLdapUser::create([
                    'username' => $data['username'],
                    'password_hash' => self::passwordHash(),
                    'email' => $data['email'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'display_name' => $data['display_name'],
                    'groups' => json_encode($data['groups'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'is_active' => true,
                ]);
            }
            $user = User::withTrashed()->where('ldap_uid', $data['username'])->first();
            if ($user === null) {
                User::create([
                    'ldap_uid' => $data['username'],
                    'email' => $data['email'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'display_name' => $data['display_name'],
                    'role' => $data['role'],
                    'role_locked' => false,
                    'role_source' => 'seed',
                    'is_active' => true,
                    'token_version' => 1,
                    'ldap_groups' => json_encode($data['groups'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        }
        if ($out !== null) {
            $out('Utenti fake LDAP: 5 disponibili (password "password").');
        }
    }
}
