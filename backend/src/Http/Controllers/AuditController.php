<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\Dates;
use App\Support\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuditController extends Controller
{
    public function index(Request $request, Response $response): Response
    {
        $this->requireUser($request);
        $query = $request->getQueryParams();
        $builder = AuditLog::query()->with('user')->orderByDesc('created_at')->orderByDesc('id');
        foreach (['action', 'entity_type', 'entity_id'] as $field) {
            if (isset($query[$field]) && $query[$field] !== '') {
                $builder->where($field, (string) $query[$field]);
            }
        }
        if (isset($query['user_id']) && $query['user_id'] !== '') {
            $builder->where('user_id', (int) $query['user_id']);
        }
        if (isset($query['from']) && Dates::isValidDate((string) $query['from'])) {
            $builder->where('created_at', '>=', $query['from'] . ' 00:00:00');
        }
        if (isset($query['to']) && Dates::isValidDate((string) $query['to'])) {
            $builder->where('created_at', '<=', $query['to'] . ' 23:59:59');
        }
        $paginator = new Paginator($query);
        [$rows, $meta] = $paginator->paginateBuilder($builder);
        $data = [];
        foreach ($rows as $row) {
            $changes = null;
            if ($row->changes !== null && $row->changes !== '') {
                $decoded = json_decode((string) $row->changes, true);
                $changes = is_array($decoded) ? $decoded : null;
            }
            $data[] = [
                'id' => (int) $row->id,
                'action' => $row->action,
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id,
                'user' => $row->user !== null ? ['id' => (int) $row->user->id, 'display_name' => $row->user->displayName()] : null,
                'changes' => $changes,
                'ip' => $row->ip,
                'created_at' => Dates::iso($row->created_at),
            ];
        }
        return $this->json($response, ['data' => $data, 'meta' => $meta]);
    }
}
