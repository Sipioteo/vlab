<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\JwtService;
use App\Domain\Calendar\CalendarService;
use App\Domain\Orders\OrderPdfService;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Regulations\RegulationService;
use App\Http\Middleware\AuthenticateMiddleware;
use App\Http\Resources\OrderEventResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\ApiException;
use App\Support\Dates;
use App\Support\Enums;
use App\Support\Paginator;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OrderController extends Controller
{
    public function __construct(
        private OrderService $orders,
        private OrderStateMachine $machine,
        private RegulationService $regulations,
        private CalendarService $calendar,
        private OrderPdfService $pdf,
        private JwtService $jwt,
    ) {
    }

    /** POST /orders — checkout (student). */
    public function store(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $serverParams = $request->getServerParams();
        $order = $this->orders->checkout(
            $user,
            $this->body($request),
            (string) ($serverParams['REMOTE_ADDR'] ?? '') ?: null,
            $request->getHeaderLine('User-Agent') ?: null
        );
        return $this->json($response, OrderResource::detail($order, $user, $this->machine, $this->regulations), 201)
            ->withHeader('Location', '/api/v1/orders/' . $order->id);
    }

    /** GET /orders (SPEC §7.9 #41). */
    public function index(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $query = $request->getQueryParams();
        return $this->listOrders($user, $query, $response, null);
    }

    /** GET /users/{id}/orders — same contract with user_id forced. */
    public function userOrders(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $query = $request->getQueryParams();
        return $this->listOrders($user, $query, $response, (int) $args['id']);
    }

    private function listOrders(User $viewer, array $query, Response $response, ?int $forcedUserId): Response
    {
        $isStaff = $viewer->isStaff();
        $this->orders->refreshOverdueAll();

        [$sort, $order] = self::sortParams($query, ['created_at', 'submitted_at', 'pickup_date', 'return_date', 'code', 'status'], 'submitted_at', 'desc');

        $builder = Order::query()->with('user');

        $statusFilter = [];
        if (isset($query['status']) && $query['status'] !== '') {
            $statusFilter = array_values(array_filter(array_map('trim', explode(',', (string) $query['status'])), static fn ($s) => in_array($s, Enums::ORDER_STATUSES, true)));
        }

        // Scope: students always see only their own orders.
        if (!$isStaff) {
            $builder->where('user_id', $viewer->id);
        } else {
            $scope = (string) ($query['scope'] ?? 'all');
            if ($scope === 'mine') {
                $builder->where('user_id', $viewer->id);
            }
            if ($forcedUserId !== null) {
                $builder->where('user_id', $forcedUserId);
            } elseif (isset($query['user_id']) && $query['user_id'] !== '') {
                $builder->where('user_id', (int) $query['user_id']);
            }
        }

        // Drafts excluded unless explicitly requested.
        if (!in_array('draft', $statusFilter, true)) {
            $builder->where('status', '!=', 'draft');
        }

        if (isset($query['product_id']) && $query['product_id'] !== '') {
            $orderIds = Capsule::table('order_items')->where('product_id', (int) $query['product_id'])->pluck('order_id')->all();
            $builder->whereIn('id', $orderIds !== [] ? $orderIds : [0]);
        }
        if (isset($query['from']) && Dates::isValidDate((string) $query['from'])) {
            $builder->where('pickup_date', '>=', (string) $query['from']);
        }
        if (isset($query['to']) && Dates::isValidDate((string) $query['to'])) {
            $builder->where('pickup_date', '<=', (string) $query['to']);
        }
        if (self::boolParam($query, 'late_only', false)) {
            $builder->whereIn('status', ['overdue', 'returned_late']);
        }
        $exceeds = self::boolParam($query, 'exceeds_limits');
        if ($exceeds !== null) {
            $builder->where('exceeds_limits', $exceeds);
        }
        if (isset($query['q']) && trim((string) $query['q']) !== '') {
            $like = '%' . mb_strtolower(trim((string) $query['q'])) . '%';
            $builder->where(function ($b) use ($like, $isStaff) {
                $b->whereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(subject, \'\')) LIKE ?', [$like]);
                if ($isStaff) {
                    $userIds = Capsule::table('users')
                        ->whereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(ldap_uid) LIKE ?', [$like])
                        ->pluck('id')->all();
                    if ($userIds !== []) {
                        $b->orWhereIn('user_id', $userIds);
                    }
                }
            });
        }

        // Summary counts over the filtered set ignoring the status filter itself.
        $summary = [];
        foreach ((clone $builder)->get(['status']) as $row) {
            $summary[$row->status] = ($summary[$row->status] ?? 0) + 1;
        }

        if ($statusFilter !== []) {
            $builder->whereIn('status', $statusFilter);
        }

        $builder->orderBy($sort, $order)->orderBy('id', $order);
        $paginator = new Paginator($query);
        [$page, $meta] = $paginator->paginateBuilder($builder);

        return $this->json($response, [
            'data' => array_map(fn ($o) => OrderResource::summary($o, $viewer), $page),
            'meta' => $meta,
            'summary' => $summary,
        ]);
    }

    /** GET /orders/{id}. */
    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $order = $this->findOrder((int) $args['id'], $user);
        $this->orders->refreshOverdue([$order]);
        return $this->json($response, OrderResource::detail($order, $user, $this->machine, $this->regulations));
    }

    /** PUT /orders/{id} — staff edit before pickup. */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $order = $this->findOrder((int) $args['id'], $user);
        $order = $this->orders->editOrder($order, $user, $this->body($request));
        return $this->json($response, OrderResource::detail($order, $user, $this->machine, $this->regulations));
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $order = $this->findOrder((int) $args['id'], $user);
        $order = $this->orders->approve($order, $user, $this->body($request));
        return $this->json($response, OrderResource::detail($order, $user, $this->machine, $this->regulations));
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $order = $this->findOrder((int) $args['id'], $user);
        $body = $this->body($request);
        $reason = isset($body['reason']) ? trim((string) $body['reason']) : '';
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            throw ApiException::validation(['reason' => ['Il motivo del rifiuto è obbligatorio (3..2000 caratteri).']]);
        }
        $order = $this->orders->reject($order, $user, $reason, isset($body['comment']) ? (string) $body['comment'] : null);
        return $this->json($response, OrderResource::detail($order, $user, $this->machine, $this->regulations));
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $order = $this->findOrder((int) $args['id'], $user);
        $body = $this->body($request);
        $order = $this->orders->cancel($order, $user, isset($body['reason']) ? (string) $body['reason'] : null);
        return $this->json($response, OrderResource::detail($order, $user, $this->machine, $this->regulations));
    }

    public function pickup(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $order = $this->findOrder((int) $args['id'], $user);
        $order = $this->orders->pickup($order, $user, $this->body($request));
        return $this->json($response, OrderResource::detail($order, $user, $this->machine, $this->regulations));
    }

    public function returnOrder(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $order = $this->findOrder((int) $args['id'], $user);
        $this->orders->refreshOverdue([$order]);
        $order = $this->orders->returnOrder($order->refresh(), $user, $this->body($request));
        return $this->json($response, OrderResource::detail($order, $user, $this->machine, $this->regulations));
    }

    public function noShow(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $order = $this->findOrder((int) $args['id'], $user);
        $body = $this->body($request);
        $order = $this->orders->markNoShow($order, $user, isset($body['comment']) ? (string) $body['comment'] : null);
        return $this->json($response, OrderResource::detail($order, $user, $this->machine, $this->regulations));
    }

    public function reopen(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $order = $this->findOrder((int) $args['id'], $user);
        $body = $this->body($request);
        $reason = isset($body['reason']) ? trim((string) $body['reason']) : '';
        if ($reason === '') {
            throw ApiException::validation(['reason' => ['Il motivo è obbligatorio.']]);
        }
        $toStatus = (string) ($body['to_status'] ?? '');
        $order = $this->orders->reopen($order, $user, $toStatus, $reason);
        return $this->json($response, OrderResource::detail($order, $user, $this->machine, $this->regulations));
    }

    public function notes(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $order = $this->findOrder((int) $args['id'], $user);
        $body = $this->body($request);
        if (array_key_exists('staff_notes', $body)) {
            $order->staff_notes = $body['staff_notes'] !== null ? (string) $body['staff_notes'] : null;
            $order->save();
        }
        $order = $this->orders->addNotes($order, $user, null, isset($body['comment']) ? (string) $body['comment'] : null);
        return $this->json($response, OrderResource::detail($order, $user, $this->machine, $this->regulations));
    }

    public function events(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $order = $this->findOrder((int) $args['id'], $user);
        $events = $order->events()->orderBy('created_at')->orderBy('id')->get();
        return $this->json($response, [
            'data' => array_map(static fn ($e) => OrderEventResource::toArray($e), $events->all()),
            'meta' => null,
        ]);
    }

    /**
     * GET /orders/{id}/pdf — printable loan form ("modulo di ritiro/riconsegna").
     *
     * Auth: the owner student or any staff role — the very same rule as
     * GET /orders/{id} (see findOrder()). Accepts the Authorization header OR
     * `?token=` (access tokens only), like GET /regulations/{id}/file, so the
     * browser can open it in a new tab.
     */
    public function pdf(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        if ($user === null) {
            $token = (string) ($request->getQueryParams()['token'] ?? '');
            if ($token !== '') {
                try {
                    $user = AuthenticateMiddleware::resolveUser($this->jwt, $token);
                } catch (ApiException $e) {
                    $user = null;
                }
            }
        }
        if ($user === null) {
            throw ApiException::unauthenticated();
        }

        $order = $this->findOrder((int) $args['id'], $user);
        $this->orders->refreshOverdue([$order]);
        if (!OrderPdfService::isPrintable($order)) {
            throw ApiException::conflict(
                'pdf_not_available',
                'Il modulo di prestito è disponibile solo dopo la conferma della richiesta.',
                [
                    'current_status' => (string) $order->status,
                    'available_from_statuses' => OrderPdfService::PRINTABLE_STATUSES,
                ]
            );
        }

        $pdf = $this->pdf->render($order);
        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . OrderPdfService::filename($order) . '"')
            ->withHeader('Content-Length', (string) strlen($pdf));
    }

    /** GET /orders/calendar — staff planning view (SPEC §7.9 #53). */
    public function calendar(Request $request, Response $response): Response
    {
        $this->requireUser($request);
        $query = $request->getQueryParams();
        $from = isset($query['from']) ? (string) $query['from'] : null;
        $to = isset($query['to']) ? (string) $query['to'] : null;
        $errors = [];
        if ($from === null || !Dates::isValidDate($from)) {
            $errors['from'] = ['Il campo from è obbligatorio (YYYY-MM-DD).'];
        }
        if ($to === null || !Dates::isValidDate($to)) {
            $errors['to'] = ['Il campo to è obbligatorio (YYYY-MM-DD).'];
        }
        if ($errors === [] && ($to < $from || Dates::inclusiveDays($from, $to) > 186)) {
            $errors['to'] = ['Intervallo non valido (massimo 186 giorni).'];
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $this->orders->refreshOverdueAll();
        $statuses = ['pending', 'approved', 'picked_up', 'overdue'];
        if (isset($query['status']) && $query['status'] !== '') {
            $requested = array_values(array_filter(array_map('trim', explode(',', (string) $query['status'])), static fn ($s) => in_array($s, Enums::ORDER_STATUSES, true)));
            if ($requested !== []) {
                $statuses = $requested;
            }
        }
        $orders = Order::with('user')
            ->whereIn('status', $statuses)
            ->where(static function ($b) use ($from, $to) {
                $b->whereBetween('pickup_date', [$from, $to])
                    ->orWhereBetween('return_date', [$from, $to]);
            })
            ->get();

        $days = [];
        $totals = ['pickups' => 0, 'returns' => 0, 'overdue' => 0];
        foreach (Dates::range($from, $to) as $date) {
            $pickups = [];
            $returns = [];
            $overdue = [];
            foreach ($orders as $o) {
                $entry = [
                    'order_id' => (int) $o->id,
                    'code' => $o->code,
                    'time' => null,
                    'user_display_name' => $o->user?->displayName(),
                    'items_count' => (int) $o->items_count,
                    'status' => $o->status,
                ];
                if (Dates::datePart($o->pickup_date) === $date && in_array($o->status, ['pending', 'approved', 'picked_up'], true)) {
                    $entry['time'] = $o->pickup_time;
                    $pickups[] = $entry;
                    $totals['pickups']++;
                }
                if (Dates::datePart($o->return_date) === $date) {
                    if ($o->status === 'overdue') {
                        $entry['time'] = $o->return_time;
                        $overdue[] = $entry;
                        $totals['overdue']++;
                    } elseif (in_array($o->status, ['approved', 'picked_up'], true)) {
                        $entry['time'] = $o->return_time;
                        $returns[] = $entry;
                        $totals['returns']++;
                    }
                }
            }
            $closure = $this->calendar->closureOn($date, 'any');
            $days[] = [
                'date' => $date,
                'is_open' => $this->calendar->isOpen($date),
                'closure_id' => $closure !== null ? (int) $closure->id : null,
                'pickups' => $pickups,
                'returns' => $returns,
                'overdue' => $overdue,
            ];
        }
        return $this->json($response, [
            'range' => ['from' => $from, 'to' => $to],
            'days' => $days,
            'totals' => $totals,
        ]);
    }

    private function findOrder(int $id, User $viewer): Order
    {
        $order = Order::find($id);
        if ($order === null) {
            throw ApiException::notFound('Richiesta non trovata.');
        }
        if (!$viewer->isStaff() && (int) $order->user_id !== (int) $viewer->id) {
            throw ApiException::forbidden('Puoi consultare solo le tue richieste.');
        }
        return $order;
    }
}
