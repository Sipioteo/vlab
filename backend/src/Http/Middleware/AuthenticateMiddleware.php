<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\JwtService;
use App\Models\User;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Bearer JWT authentication (SPEC §4.4). Role is re-read from the DB — the
 * token's `role` claim is a UI hint only. `ver` mismatch => 401 token_stale.
 *
 * $optional = true: anonymous requests pass through with no `user` attribute
 * (used by public endpoints whose payload varies by viewer).
 */
final class AuthenticateMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JwtService $jwt,
        private bool $optional = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        $token = null;
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            $token = $m[1];
        }
        if ($token === null) {
            if ($this->optional) {
                return $handler->handle($request);
            }
            throw ApiException::unauthenticated('unauthenticated', 'Autenticazione richiesta.');
        }
        try {
            $user = self::resolveUser($this->jwt, $token);
        } catch (ApiException $e) {
            if ($this->optional && $e->getStatus() === 401) {
                return $handler->handle($request);
            }
            throw $e;
        }
        return $handler->handle($request->withAttribute('user', $user));
    }

    /**
     * Shared token->User resolution (also used by the ?token= PDF stream path).
     *
     * @throws ApiException
     */
    public static function resolveUser(JwtService $jwt, string $token): User
    {
        $claims = $jwt->decode($token);
        $userId = (int) ($claims['sub'] ?? 0);
        $user = User::find($userId);
        if ($user === null) {
            throw ApiException::unauthenticated('token_invalid', 'Token di accesso non valido.');
        }
        if ((int) ($claims['ver'] ?? -1) !== (int) $user->token_version) {
            throw ApiException::unauthenticated('token_stale', 'Il token non è più valido, effettua di nuovo l\'accesso.');
        }
        if (!$user->is_active) {
            throw new ApiException(403, 'account_disabled', 'Account disabilitato.');
        }
        return $user;
    }
}
