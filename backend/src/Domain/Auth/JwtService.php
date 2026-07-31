<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Settings\SettingsRepository;
use App\Models\RefreshToken;
use App\Models\User;
use App\Support\ApiException;
use App\Support\Dates;
use App\Support\Str;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Access tokens (HS256 JWT) + rotating refresh tokens (SPEC §4.3).
 */
final class JwtService
{
    public function __construct(
        private SettingsRepository $settings,
        private array $config,
    ) {
    }

    private function secret(): string
    {
        return (string) $this->config['jwt']['secret'];
    }

    private function algo(): string
    {
        return (string) ($this->config['jwt']['algo'] ?? 'HS256');
    }

    public function accessTtlSeconds(): int
    {
        $minutes = $this->settings->get('security.jwt_ttl_minutes', 480);
        return (int) ($minutes ?? 480) * 60;
    }

    public function refreshTtlDays(): int
    {
        return (int) ($this->settings->get('security.jwt_refresh_ttl_days', 14) ?? 14);
    }

    /** @return array{token:string, expires_in:int, expires_at:string} */
    public function issueAccessToken(User $user): array
    {
        $now = Dates::nowUtc();
        $ttl = $this->accessTtlSeconds();
        $exp = $now->getTimestamp() + $ttl;
        $issuer = $this->settings->get('security.jwt_issuer', $this->config['jwt']['issuer'] ?? 'vlab');
        $payload = [
            'iss' => $issuer,
            'sub' => (string) $user->id,
            'iat' => $now->getTimestamp(),
            'exp' => $exp,
            'jti' => Str::randomHex(16),
            'uid' => (string) $user->ldap_uid,
            'role' => (string) $user->role,
            'name' => $user->displayName(),
            'ver' => (int) $user->token_version,
        ];
        return [
            'token' => JWT::encode($payload, $this->secret(), $this->algo()),
            'expires_in' => $ttl,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $exp),
        ];
    }

    /**
     * Decode and validate an access token.
     *
     * @return array<string,mixed> claims
     * @throws ApiException 401 token_expired | token_invalid
     */
    public function decode(string $jwt): array
    {
        try {
            JWT::$timestamp = Dates::nowUtc()->getTimestamp();
            $decoded = JWT::decode($jwt, new Key($this->secret(), $this->algo()));
            return (array) $decoded;
        } catch (ExpiredException $e) {
            throw ApiException::unauthenticated('token_expired', 'Il token di accesso è scaduto.');
        } catch (\Throwable $e) {
            throw ApiException::unauthenticated('token_invalid', 'Token di accesso non valido.');
        } finally {
            JWT::$timestamp = null;
        }
    }

    /**
     * Create a refresh token row; returns the plaintext token.
     *
     * @return array{token:string, expires_at:string}
     */
    public function issueRefreshToken(User $user, ?string $familyId, ?string $ip, ?string $userAgent): array
    {
        $plain = Str::randomHex(64);
        $expires = Dates::nowUtc()->modify('+' . $this->refreshTtlDays() . ' days');
        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'family_id' => $familyId ?? Str::uuid4(),
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'ip' => $ip,
        ]);
        return [
            'token' => $plain,
            'expires_at' => $expires->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Consume a presented refresh token (rotation). Returns the row.
     *
     * @throws ApiException 401 refresh_invalid | refresh_expired | refresh_reused
     */
    public function consumeRefreshToken(string $plain): RefreshToken
    {
        $row = RefreshToken::where('token_hash', hash('sha256', $plain))->first();
        if ($row === null) {
            throw ApiException::unauthenticated('refresh_invalid', 'Token di rinnovo non valido.');
        }
        if ($row->revoked_at !== null) {
            // Reuse detected: revoke the whole family.
            RefreshToken::where('family_id', $row->family_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => Dates::nowDb()]);
            throw ApiException::unauthenticated('refresh_reused', 'Token di rinnovo già utilizzato.');
        }
        if ((string) $row->expires_at < Dates::nowDb()) {
            throw ApiException::unauthenticated('refresh_expired', 'Token di rinnovo scaduto.');
        }
        $row->revoked_at = Dates::nowDb();
        $row->save();
        return $row;
    }

    public function revokeToken(string $plain): void
    {
        RefreshToken::where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Dates::nowDb()]);
    }

    public function revokeAllForUser(int $userId): void
    {
        RefreshToken::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Dates::nowDb()]);
    }
}
