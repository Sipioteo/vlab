<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProductLogResource;
use App\Models\Product;
use App\Models\ProductLog;
use App\Models\ProductUnit;
use App\Support\ApiException;
use App\Support\AuditLogger;
use App\Support\Dates;
use App\Support\Enums;
use App\Support\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LogController extends Controller
{
    /** GET /products/{id}/logs — public, filtered for non-staff. */
    public function productLogs(Request $request, Response $response, array $args): Response
    {
        $product = Product::find((int) $args['id']);
        if ($product === null) {
            throw ApiException::notFound('Prodotto non trovato.');
        }
        $user = $this->user($request);
        $isStaff = $user !== null && $user->isStaff();
        $query = $request->getQueryParams();
        [$sort, $order] = self::sortParams($query, ['occurred_at'], 'occurred_at', 'desc');

        $builder = ProductLog::where('product_id', $product->id);
        if (!$isStaff) {
            $builder->where('is_public', true);
        }
        $this->applyCommonFilters($builder, $query);
        $builder->orderBy($sort, $order);
        $paginator = new Paginator($query);
        [$logs, $meta] = $paginator->paginateBuilder($builder);
        return $this->json($response, [
            'data' => array_map(static fn ($l) => ProductLogResource::toArray($l, $isStaff), $logs),
            'meta' => $meta,
        ]);
    }

    /** POST /products/{id}/logs — T/B/AD. */
    public function store(Request $request, Response $response, array $args): Response
    {
        $product = Product::find((int) $args['id']);
        if ($product === null) {
            throw ApiException::notFound('Prodotto non trovato.');
        }
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $errors = [];
        $type = (string) ($body['type'] ?? '');
        if (!in_array($type, Enums::LOG_TYPES, true)) {
            $errors['type'] = ['Tipo di voce non valido.'];
        }
        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        if ($title === '' || mb_strlen($title) > 191) {
            $errors['title'] = ['Il titolo è obbligatorio (max 191 caratteri).'];
        }
        $unitId = isset($body['product_unit_id']) && $body['product_unit_id'] !== null ? (int) $body['product_unit_id'] : null;
        $unit = null;
        if ($unitId !== null) {
            $unit = ProductUnit::find($unitId);
            if ($unit === null || (int) $unit->product_id !== (int) $product->id) {
                $errors['product_unit_id'] = ['L\'unità deve appartenere al prodotto.'];
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $severity = in_array($body['severity'] ?? 'info', Enums::LOG_SEVERITIES, true) ? (string) ($body['severity'] ?? 'info') : 'info';
        $occurredAt = Dates::nowDb();
        if (isset($body['occurred_at']) && $body['occurred_at'] !== null) {
            try {
                $occurredAt = (new \DateTimeImmutable((string) $body['occurred_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                throw ApiException::validation(['occurred_at' => ['Formato data/ora non valido.']]);
            }
        }
        $log = ProductLog::create([
            'product_id' => $product->id,
            'product_unit_id' => $unitId,
            'order_id' => isset($body['order_id']) && $body['order_id'] !== null ? (int) $body['order_id'] : null,
            'user_id' => $user->id,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'body' => isset($body['body']) && $body['body'] !== null ? (string) $body['body'] : null,
            'occurred_at' => $occurredAt,
            'is_public' => (bool) ($body['is_public'] ?? true),
        ]);

        // Side effect (SPEC §7.7 #24): critical damage/loss flips the unit status.
        if ($unit !== null && $severity === 'critical' && in_array($type, ['damage', 'loss'], true)) {
            $newStatus = $type === 'damage' ? 'maintenance' : 'missing';
            $before = $unit->status;
            $unit->status = $newStatus;
            $unit->save();
            AuditLogger::log($user, 'unit.status_change', 'ProductUnit', (string) $unit->id, [
                'before' => ['status' => $before],
                'after' => ['status' => $newStatus],
            ]);
        }
        return $this->json($response, ProductLogResource::toArray($log, true), 201)
            ->withHeader('Location', '/api/v1/products/' . $product->id . '/logs');
    }

    /** PUT /logs/{logId} — author (T/B) or T/AD. */
    public function update(Request $request, Response $response, array $args): Response
    {
        $log = ProductLog::find((int) $args['logId']);
        if ($log === null) {
            throw ApiException::notFound('Voce di registro non trovata.');
        }
        $user = $this->requireUser($request);
        $isAuthor = (int) $log->user_id === (int) $user->id;
        $isSenior = in_array($user->role, ['technician', 'admin'], true);
        if (!$isSenior && !$isAuthor) {
            throw ApiException::forbidden('Puoi modificare solo le tue voci di registro.');
        }
        $body = $this->body($request);
        if (array_key_exists('type', $body) && in_array((string) $body['type'], Enums::LOG_TYPES, true)) {
            $log->type = (string) $body['type'];
        }
        if (array_key_exists('severity', $body) && in_array((string) $body['severity'], Enums::LOG_SEVERITIES, true)) {
            $log->severity = (string) $body['severity'];
        }
        if (array_key_exists('title', $body) && $body['title'] !== null) {
            $log->title = mb_substr(trim((string) $body['title']), 0, 191);
        }
        if (array_key_exists('body', $body)) {
            $log->body = $body['body'] !== null ? (string) $body['body'] : null;
        }
        foreach (['occurred_at', 'resolved_at'] as $field) {
            if (array_key_exists($field, $body)) {
                if ($body[$field] === null) {
                    $log->{$field} = null;
                } else {
                    try {
                        $log->{$field} = (new \DateTimeImmutable((string) $body[$field]))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                    } catch (\Throwable $e) {
                        throw ApiException::validation([$field => ['Formato data/ora non valido.']]);
                    }
                }
            }
        }
        if (array_key_exists('is_public', $body) && $body['is_public'] !== null) {
            $log->is_public = (bool) $body['is_public'];
        }
        $log->save();
        return $this->json($response, ProductLogResource::toArray($log, true));
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $log = ProductLog::find((int) $args['logId']);
        if ($log === null) {
            throw ApiException::notFound('Voce di registro non trovata.');
        }
        $log->delete();
        return $response->withStatus(204);
    }

    /** GET /logs — staff-wide feed (endpoint #86). */
    public function feed(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        [$sort, $order] = self::sortParams($query, ['occurred_at', 'severity'], 'occurred_at', 'desc');
        $builder = ProductLog::query();
        if (isset($query['product_id']) && $query['product_id'] !== '') {
            $builder->where('product_id', (int) $query['product_id']);
        }
        if (isset($query['category_id']) && $query['category_id'] !== '') {
            $productIds = Product::where('category_id', (int) $query['category_id'])->pluck('id')->all();
            $builder->whereIn('product_id', $productIds !== [] ? $productIds : [0]);
        }
        if (isset($query['product_unit_id']) && $query['product_unit_id'] !== '') {
            $builder->where('product_unit_id', (int) $query['product_unit_id']);
        }
        if (isset($query['user_id']) && $query['user_id'] !== '') {
            $builder->where('user_id', (int) $query['user_id']);
        }
        if (self::boolParam($query, 'unresolved', false)) {
            $builder->whereNull('resolved_at');
        }
        if (isset($query['q']) && trim((string) $query['q']) !== '') {
            $like = '%' . mb_strtolower(trim((string) $query['q'])) . '%';
            $builder->where(static function ($b) use ($like) {
                $b->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(body, \'\')) LIKE ?', [$like]);
            });
        }
        $this->applyCommonFilters($builder, $query);
        $builder->orderBy($sort, $order);

        // Summary over the full filtered set, ignoring type filter for counts by type.
        $summary = ['damage' => 0, 'maintenance' => 0, 'inspection' => 0, 'note' => 0, 'loss' => 0, 'repair' => 0, 'unresolved' => 0];
        foreach ((clone $builder)->get(['type', 'resolved_at']) as $row) {
            if (isset($summary[$row->type])) {
                $summary[$row->type]++;
            }
            if ($row->resolved_at === null) {
                $summary['unresolved']++;
            }
        }

        $paginator = new Paginator($query);
        [$logs, $meta] = $paginator->paginateBuilder($builder);
        return $this->json($response, [
            'data' => array_map(static fn ($l) => ProductLogResource::toArray($l, true), $logs),
            'meta' => $meta,
            'summary' => $summary,
        ]);
    }

    private function applyCommonFilters($builder, array $query): void
    {
        if (isset($query['type']) && in_array((string) $query['type'], Enums::LOG_TYPES, true)) {
            $builder->where('type', (string) $query['type']);
        }
        if (isset($query['severity']) && in_array((string) $query['severity'], Enums::LOG_SEVERITIES, true)) {
            $builder->where('severity', (string) $query['severity']);
        }
        if (isset($query['unit_id']) && $query['unit_id'] !== '') {
            $builder->where('product_unit_id', (int) $query['unit_id']);
        }
        if (isset($query['from']) && Dates::isValidDate((string) $query['from'])) {
            $builder->where('occurred_at', '>=', $query['from'] . ' 00:00:00');
        }
        if (isset($query['to']) && Dates::isValidDate((string) $query['to'])) {
            $builder->where('occurred_at', '<=', $query['to'] . ' 23:59:59');
        }
    }
}
