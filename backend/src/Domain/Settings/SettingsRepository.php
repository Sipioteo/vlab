<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Models\Setting;
use App\Support\Dates;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Reads/writes the settings table. Values are ALWAYS stored JSON-encoded (SPEC §6.13).
 * The whole table is cached in a static array per request; every write invalidates it.
 */
final class SettingsRepository
{
    private static ?self $shared = null;

    /**
     * Static so every instance (container-injected or shared) sees the same
     * per-request cache and every write invalidates it for all consumers.
     *
     * @var array<string,array<string,mixed>>|null key => raw row
     */
    private static ?array $cache = null;

    public static function instance(): self
    {
        if (self::$shared === null) {
            self::$shared = new self();
        }
        return self::$shared;
    }

    /** Reset the per-request cache (used by tests and after external writes). */
    public static function reset(): void
    {
        self::$shared = null;
        self::$cache = null;
    }

    public function invalidate(): void
    {
        self::$cache = null;
    }

    /** @return array<string,array<string,mixed>> */
    private function load(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            if (Capsule::schema()->hasTable('settings')) {
                foreach (Capsule::table('settings')->get() as $row) {
                    self::$cache[$row->key] = (array) $row;
                }
            }
        }
        return self::$cache;
    }

    public function has(string $key): bool
    {
        return isset($this->load()[$key]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $rows = $this->load();
        if (!isset($rows[$key])) {
            return $default;
        }
        return self::decodeValue($rows[$key]['value']);
    }

    /** Decoded key => value map. @return array<string,mixed> */
    public function all(): array
    {
        $out = [];
        foreach ($this->load() as $key => $row) {
            $out[$key] = self::decodeValue($row['value']);
        }
        return $out;
    }

    /** Raw metadata rows. @return array<string,array<string,mixed>> */
    public function rows(): array
    {
        return $this->load();
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        Capsule::table('settings')->where('key', $key)->update([
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_by' => $userId,
            'updated_at' => Dates::nowDb(),
        ]);
        $this->invalidate();
    }

    public static function decodeValue(?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }
        return json_decode($raw, true);
    }

    public function row(string $key): ?Setting
    {
        return Setting::where('key', $key)->first();
    }

    /** Resolved LDAP mode: env LDAP_MODE > settings ldap.mode > 'fake' (SPEC §3.3). */
    public function ldapMode(array $config): string
    {
        $envMode = $config['ldap']['mode_env'] ?? '';
        if (is_string($envMode) && $envMode !== '') {
            return $envMode;
        }
        $setting = $this->get('ldap.mode');
        if (is_string($setting) && $setting !== '') {
            return $setting;
        }
        return 'fake';
    }
}
