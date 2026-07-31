<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequireRoleMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = $roles;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if (!$user instanceof User) {
            throw ApiException::unauthenticated();
        }
        if (!in_array($user->role, $this->roles, true)) {
            throw new ApiException(403, 'role_required', 'Il tuo ruolo non consente questa operazione.');
        }
        return $handler->handle($request);
    }
}
