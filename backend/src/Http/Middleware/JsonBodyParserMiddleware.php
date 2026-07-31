<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class JsonBodyParserMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $contents = (string) $request->getBody();
            if (trim($contents) !== '') {
                $parsed = json_decode($contents, true);
                if (!is_array($parsed)) {
                    throw new ApiException(400, 'invalid_json', 'Il corpo della richiesta non è JSON valido.');
                }
                $request = $request->withParsedBody($parsed);
            } else {
                $request = $request->withParsedBody([]);
            }
        }
        return $handler->handle($request);
    }
}
