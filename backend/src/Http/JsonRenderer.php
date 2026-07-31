<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;

final class JsonRenderer
{
    public static function json(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    /**
     * Standard error envelope (SPEC §7.3).
     *
     * @param array<string,mixed>|null $details
     */
    public static function error(
        ResponseInterface $response,
        int $status,
        string $code,
        string $message,
        ?array $details = null,
        ?array $debug = null
    ): ResponseInterface {
        $body = [
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'trace_id' => substr(bin2hex(random_bytes(4)), 0, 8),
            ],
        ];
        if ($debug !== null) {
            $body['error']['debug'] = $debug;
        }
        return self::json($response, $body, $status);
    }
}
