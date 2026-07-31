<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Orders\OrderService;
use App\Domain\Regulations\RegulationService;
use App\Models\User;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applied ONLY to POST /api/v1/orders (SPEC §4.4 #7): every required
 * regulation for the cart contents must have a current acceptance, either
 * pre-existing or supplied via `accepted_regulation_ids` in the same request.
 */
final class RequireRegulationAcceptanceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RegulationService $regulations,
        private OrderService $orders,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if (!$user instanceof User) {
            throw ApiException::unauthenticated();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $fromCart = (bool) ($body['from_cart'] ?? true);
        if ($fromCart) {
            $cart = $this->orders->cart($user);
            $items = $this->orders->cartItemsNormalized($cart);
        } else {
            $items = is_array($body['items'] ?? null) ? $this->orders->normalizeItems($body['items']) : [];
        }
        $acceptedIds = array_map('intval', (array) ($body['accepted_regulation_ids'] ?? []));
        $required = array_merge(
            $this->regulations->pendingGlobalFor($user),
            $this->regulations->requiredForItems($items)
        );
        $missing = [];
        $seen = [];
        foreach ($required as $reg) {
            if (isset($seen[$reg->id])) {
                continue;
            }
            $seen[$reg->id] = true;
            if ($this->regulations->hasAccepted($user, $reg)) {
                continue;
            }
            if (in_array((int) $reg->id, $acceptedIds, true)) {
                continue;
            }
            $missing[] = (int) $reg->id;
        }
        if ($missing !== []) {
            throw new ApiException(409, 'regulation_acceptance_required', 'Devi accettare i regolamenti richiesti prima di inviare la richiesta.', [
                'regulation_ids' => $missing,
            ]);
        }
        return $handler->handle($request);
    }
}
