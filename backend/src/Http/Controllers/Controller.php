<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\JsonRenderer;
use App\Models\User;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class Controller
{
    protected function user(Request $request): ?User
    {
        $user = $request->getAttribute('user');
        return $user instanceof User ? $user : null;
    }

    protected function requireUser(Request $request): User
    {
        $user = $this->user($request);
        if ($user === null) {
            throw ApiException::unauthenticated();
        }
        return $user;
    }

    /** @return array<string,mixed> */
    protected function body(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    protected function json(Response $response, mixed $data, int $status = 200): Response
    {
        return JsonRenderer::json($response, $data, $status);
    }

    protected static function boolParam(array $query, string $key, ?bool $default = null): ?bool
    {
        if (!array_key_exists($key, $query)) {
            return $default;
        }
        $raw = $query[$key];
        if (is_bool($raw)) {
            return $raw;
        }
        $value = filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $value ?? $default;
    }

    /** @param string[] $allowed */
    protected static function sortParams(array $query, array $allowed, string $defaultSort, string $defaultOrder = 'asc'): array
    {
        $sort = (string) ($query['sort'] ?? $defaultSort);
        if (!in_array($sort, $allowed, true)) {
            throw new ApiException(422, 'invalid_sort', 'Campo di ordinamento non valido.', ['sort' => ['Campo non ordinabile: ' . $sort]]);
        }
        $order = strtolower((string) ($query['order'] ?? $defaultOrder));
        if (!in_array($order, ['asc', 'desc'], true)) {
            $order = $defaultOrder;
        }
        return [$sort, $order];
    }
}
