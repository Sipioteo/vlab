<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Settings\SettingsRepository;
use App\Support\ApiException;
use App\Support\Dates;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * File-based brute-force protection for POST /auth/login (SPEC §4.4 #4).
 * Counts failed attempts per username AND per IP inside a sliding window.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SettingsRepository $settings,
        private string $storagePath,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $username = strtolower(trim((string) ($body['username'] ?? '')));
        $ip = $this->clientIp($request);
        $max = (int) ($this->settings->get('security.login_max_attempts', 10) ?? 10);
        $windowMinutes = (int) ($this->settings->get('security.login_window_minutes', 15) ?? 15);

        $keys = array_filter([
            $username !== '' ? 'u_' . sha1($username) : null,
            $ip !== '' ? 'ip_' . sha1($ip) : null,
        ]);
        foreach ($keys as $key) {
            if ($this->failures($key, $windowMinutes) >= $max) {
                throw new ApiException(429, 'too_many_attempts', 'Troppi tentativi di accesso: riprova più tardi.');
            }
        }

        try {
            $response = $handler->handle($request);
        } catch (ApiException $e) {
            if (in_array($e->getStatus(), [401, 403], true)) {
                foreach ($keys as $key) {
                    $this->recordFailure($key, $windowMinutes);
                }
            }
            throw $e;
        }

        if ($response->getStatusCode() === 200) {
            foreach ($keys as $key) {
                @unlink($this->file($key));
            }
        } elseif (in_array($response->getStatusCode(), [401, 403], true)) {
            foreach ($keys as $key) {
                $this->recordFailure($key, $windowMinutes);
            }
        }
        return $response;
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();
        return (string) ($params['REMOTE_ADDR'] ?? '');
    }

    private function file(string $key): string
    {
        return rtrim($this->storagePath, '/') . '/ratelimit/' . $key . '.json';
    }

    /** @return int failure count inside the window */
    private function failures(string $key, int $windowMinutes): int
    {
        return count($this->readTimestamps($key, $windowMinutes));
    }

    /** @return int[] */
    private function readTimestamps(string $key, int $windowMinutes): array
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }
        $cutoff = Dates::nowUtc()->getTimestamp() - $windowMinutes * 60;
        return array_values(array_filter($data, static fn ($ts) => is_int($ts) && $ts >= $cutoff));
    }

    private function recordFailure(string $key, int $windowMinutes): void
    {
        $dir = rtrim($this->storagePath, '/') . '/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $timestamps = $this->readTimestamps($key, $windowMinutes);
        $timestamps[] = Dates::nowUtc()->getTimestamp();
        @file_put_contents($this->file($key), json_encode($timestamps), LOCK_EX);
    }
}
