<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\JsonRenderer;
use App\Support\ApiException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;

/**
 * Outermost middleware: converts every exception into the SPEC §7.3 envelope.
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private bool $debug,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ApiException $e) {
            $response = $this->responseFactory->createResponse();
            return JsonRenderer::error($response, $e->getStatus(), $e->getErrorCode(), $e->getMessage(), $e->getDetails());
        } catch (HttpMethodNotAllowedException $e) {
            $response = $this->responseFactory->createResponse();
            return JsonRenderer::error($response, 405, 'method_not_allowed', 'Metodo non consentito.');
        } catch (HttpNotFoundException $e) {
            $response = $this->responseFactory->createResponse();
            return JsonRenderer::error($response, 404, 'not_found', 'Risorsa non trovata.');
        } catch (\Throwable $e) {
            $response = $this->responseFactory->createResponse();
            $debug = null;
            if ($this->debug) {
                $debug = [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
                    'message' => $e->getMessage(),
                ];
            }
            $this->logError($e);
            return JsonRenderer::error($response, 500, 'server_error', 'Errore interno del server.', null, $debug);
        }
    }

    private function logError(\Throwable $e): void
    {
        $dir = dirname(__DIR__, 3) . '/storage/logs';
        if (is_dir($dir) || @mkdir($dir, 0777, true)) {
            @file_put_contents(
                $dir . '/error.log',
                sprintf("[%s] %s: %s in %s:%d\n", date('c'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()),
                FILE_APPEND
            );
        }
    }
}
