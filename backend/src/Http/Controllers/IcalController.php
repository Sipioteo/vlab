<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendar\IcalService;
use App\Models\User;
use App\Support\ApiException;
use App\Support\AuditLogger;
use App\Support\Dates;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Obfuscated, rotatable iCal subscription feed.
 *
 * The token in the URL *is* the credential — calendar clients cannot send an
 * Authorization header — so it is 32 random bytes (64 hex chars), unguessable,
 * single-active-per-user and revoked by rotation.
 */
final class IcalController extends Controller
{
    public function __construct(
        private IcalService $ical,
        /** @var array<string,mixed> */
        private array $config,
    ) {
    }

    /** GET /ical/{token}.ics — no auth: the token resolves the user. */
    public function feed(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $user = self::resolve($token);
        if ($user === null) {
            throw ApiException::notFound('Calendario non trovato.');
        }

        $body = $this->ical->feedFor($user);
        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="visionary-lab.ics"')
            ->withHeader('Cache-Control', 'private, max-age=300')
            ->withStatus(200);
    }

    /** GET /me/ical — current feed URL, minting the token on first read. */
    public function mine(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $token = self::ensureToken($user);
        return $this->json($response, $this->payload($user, $token));
    }

    /** POST /me/ical/rotate — new token; the previous URL stops working at once. */
    public function rotate(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $token = self::newToken($user);
        AuditLogger::log($user, 'user.ical_rotate', 'User', (string) $user->id, ['after' => ['ical_token' => 'rotated']]);
        return $this->json($response, $this->payload($user, $token));
    }

    // ------------------------------------------------------------ helpers ---

    /** @return array<string,mixed> */
    private function payload(User $user, string $token): array
    {
        return [
            'token' => $token,
            'feed_url' => $this->feedUrl($token),
            'generated_at' => Dates::iso($user->ical_token_generated_at),
        ];
    }

    private function feedUrl(string $token): string
    {
        $base = rtrim((string) ($this->config['app']['url'] ?? ''), '/');
        return $base . '/api/v1/ical/' . $token . '.ics';
    }

    public static function resolve(string $token): ?User
    {
        if (!preg_match('/^[0-9a-f]{32,64}$/', $token)) {
            return null;
        }
        $user = User::where('ical_token', $token)->first();
        if ($user === null || !$user->is_active) {
            return null;
        }
        return $user;
    }

    public static function ensureToken(User $user): string
    {
        $current = (string) ($user->ical_token ?? '');
        if ($current !== '') {
            return $current;
        }
        return self::newToken($user);
    }

    public static function newToken(User $user): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while (User::where('ical_token', $token)->exists());
        $user->ical_token = $token;
        $user->ical_token_generated_at = Dates::nowDb();
        $user->save();
        return $token;
    }
}
