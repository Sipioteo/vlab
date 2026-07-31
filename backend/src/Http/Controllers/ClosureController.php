<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ClosureResource;
use App\Models\Closure;
use App\Models\Order;
use App\Support\ApiException;
use App\Support\AuditLogger;
use App\Support\Dates;
use App\Support\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ClosureController extends Controller
{
    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $builder = Closure::query()->orderBy('start_date');
        if (isset($query['from']) && Dates::isValidDate((string) $query['from'])) {
            $builder->where('end_date', '>=', (string) $query['from']);
        }
        if (isset($query['to']) && Dates::isValidDate((string) $query['to'])) {
            $builder->where('start_date', '<=', (string) $query['to']);
        }
        if (!self::boolParam($query, 'include_past', false)) {
            $today = Dates::todayInTz('UTC');
            $builder->where(static function ($b) use ($today) {
                $b->where('end_date', '>=', $today)->orWhere('is_recurring_yearly', true);
            });
        }
        $paginator = new Paginator($query);
        [$rows, $meta] = $paginator->paginateBuilder($builder);
        return $this->json($response, [
            'data' => array_map(static fn ($c) => ClosureResource::toArray($c), $rows),
            'meta' => $meta,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $data = $this->validate($body, true);
        $closure = Closure::create($data + ['created_by' => $user->id]);
        AuditLogger::log($user, 'closure.create', 'Closure', (string) $closure->id, ['after' => $data]);
        $out = ClosureResource::toArray($closure);
        $out['affected_orders'] = $this->affectedOrders($closure);
        return $this->json($response, $out, 201)
            ->withHeader('Location', '/api/v1/closures/' . $closure->id);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $closure = Closure::find((int) $args['id']);
        if ($closure === null) {
            throw ApiException::notFound('Chiusura non trovata.');
        }
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $data = $this->validate($body + [
            'title' => $body['title'] ?? $closure->title,
            'start_date' => $body['start_date'] ?? Dates::datePart($closure->start_date),
            'end_date' => $body['end_date'] ?? Dates::datePart($closure->end_date),
        ], true);
        $closure->fill($data);
        $closure->save();
        AuditLogger::log($user, 'closure.update', 'Closure', (string) $closure->id, null);
        $out = ClosureResource::toArray($closure);
        $out['affected_orders'] = $this->affectedOrders($closure);
        return $this->json($response, $out);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $closure = Closure::find((int) $args['id']);
        if ($closure === null) {
            throw ApiException::notFound('Chiusura non trovata.');
        }
        $closure->delete();
        AuditLogger::log($this->user($request), 'closure.delete', 'Closure', (string) $closure->id, null);
        return $response->withStatus(204);
    }

    /** @return array<string,mixed> */
    private function validate(array $body, bool $requireAll): array
    {
        $errors = [];
        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        if ($title === '' || mb_strlen($title) > 191) {
            $errors['title'] = ['Il titolo è obbligatorio (max 191 caratteri).'];
        }
        $start = isset($body['start_date']) ? (string) $body['start_date'] : '';
        $end = isset($body['end_date']) ? (string) $body['end_date'] : '';
        if (!Dates::isValidDate($start)) {
            $errors['start_date'] = ['Data di inizio non valida (YYYY-MM-DD).'];
        }
        if (!Dates::isValidDate($end)) {
            $errors['end_date'] = ['Data di fine non valida (YYYY-MM-DD).'];
        }
        if ($errors === [] && $end < $start) {
            $errors['end_date'] = ['La data di fine deve essere successiva o uguale all\'inizio.'];
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return [
            'title' => $title,
            'description' => $body['description'] ?? null,
            'start_date' => $start,
            'end_date' => $end,
            'blocks_pickup' => (bool) ($body['blocks_pickup'] ?? true),
            'blocks_return' => (bool) ($body['blocks_return'] ?? true),
            'is_recurring_yearly' => (bool) ($body['is_recurring_yearly'] ?? false),
        ];
    }

    /** Approved orders overlapping the closure (SPEC §7.11 note). @return array<int,mixed> */
    private function affectedOrders(Closure $closure): array
    {
        $orders = Order::where('status', 'approved')
            ->whereNotNull('pickup_date')
            ->where('pickup_date', '<=', Dates::datePart($closure->end_date))
            ->where('return_date', '>=', Dates::datePart($closure->start_date))
            ->get();
        return array_map(static fn ($o) => [
            'id' => (int) $o->id,
            'code' => $o->code,
            'pickup_date' => Dates::datePart($o->pickup_date),
        ], $orders->all());
    }
}
