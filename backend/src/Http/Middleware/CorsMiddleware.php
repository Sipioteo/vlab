<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS per SPEC §3.4. Bearer tokens only, no credentials.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private array $config,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = $this->responseFactory->createResponse(204);
            return $this->withCors($request, $response);
        }
        $response = $handler->handle($request);
        return $this->withCors($request, $response);
    }

    private function withCors(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allowed = [$this->config['app']['frontend_url'] ?? 'http://localhost:8080'];
        if (in_array($this->config['app']['env'] ?? 'local', ['local', 'test'], true)) {
            $allowed[] = 'http://localhost:8080';
            $allowed[] = 'http://127.0.0.1:8080';
        }
        $allowOrigin = in_array($origin, $allowed, true) ? $origin : $allowed[0];
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,PATCH,DELETE,OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization,Content-Type,Accept,X-Requested-With')
            ->withHeader('Vary', 'Origin');
    }
}
