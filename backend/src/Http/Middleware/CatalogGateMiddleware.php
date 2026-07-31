<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Catalog endpoints are public unless ui.allow_anonymous_catalog = false
 * (SPEC §9.2 note 1). Runs after optional authentication.
 */
final class CatalogGateMiddleware implements MiddlewareInterface
{
    public function __construct(private SettingsRepository $settings)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $allowAnonymous = (bool) ($this->settings->get('ui.allow_anonymous_catalog', true) ?? true);
        if (!$user instanceof User && !$allowAnonymous) {
            throw ApiException::unauthenticated('unauthenticated', 'Autenticazione richiesta per consultare il catalogo.');
        }
        return $handler->handle($request);
    }
}
