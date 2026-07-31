<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\JwtService;
use App\Http\Resources\OrderResource;
use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Models\User;
use App\Support\ApiException;
use App\Support\AuditLogger;
use App\Support\Enums;
use App\Support\Paginator;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UserController extends Controller
{
    public function __construct(private JwtService $jwt)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $viewer = $this->requireUser($request);
        $query = $request->getQueryParams();
        [$sort, $order] = self::sortParams($query, ['display_name', 'last_login_at', 'created_at'], 'display_name');
        $builder = User::query();
        if (isset($query['q']) && trim((string) $query['q']) !== '') {
            $like = '%' . mb_strtolower(trim((string) $query['q'])) . '%';
            $builder->where(static function ($b) use ($like) {
                $b->whereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(ldap_uid) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(matricola, \'\')) LIKE ?', [$like]);
            });
        }
        if (isset($query['role']) && in_array((string) $query['role'], Enums::ROLES, true)) {
            $builder->where('role', (string) $query['role']);
        }
        $isActive = self::boolParam($query, 'is_active');
        if ($isActive !== null) {
            $builder->where('is_active', $isActive);
        }
        if (self::boolParam($query, 'has_active_orders', false)) {
            $userIds = Capsule::table('orders')
                ->whereIn('status', ['pending', 'approved', 'picked_up', 'overdue'])
                ->whereNull('deleted_at')
                ->pluck('user_id')->unique()->all();
            $builder->whereIn('id', $userIds !== [] ? $userIds : [0]);
        }
        $builder->orderBy($sort, $order);
        $paginator = new Paginator($query);
        [$rows, $meta] = $paginator->paginateBuilder($builder);
        $data = array_map(fn ($u) => $this->withAggregates($u, $viewer), $rows);
        return $this->json($response, ['data' => $data, 'meta' => $meta]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $viewer = $this->requireUser($request);
        $user = User::find((int) $args['id']);
        if ($user === null) {
            throw ApiException::notFound('Utente non trovato.');
        }
        $out = $this->withAggregates($user, $viewer);
        $recent = Order::with('user')
            ->where('user_id', $user->id)
            ->where('status', '!=', 'draft')
            ->orderByDesc('submitted_at')
            ->limit(10)->get();
        $out['recent_orders'] = array_map(static fn ($o) => OrderResource::summary($o, $viewer), $recent->all());
        return $this->json($response, $out);
    }

    /** PUT /users/{id} — admin only (SPEC §7.11 #75). */
    public function update(Request $request, Response $response, array $args): Response
    {
        $admin = $this->requireUser($request);
        $user = User::find((int) $args['id']);
        if ($user === null) {
            throw ApiException::notFound('Utente non trovato.');
        }
        $body = $this->body($request);

        $roleChanged = array_key_exists('role', $body) && $body['role'] !== null && (string) $body['role'] !== $user->role;
        $deactivating = array_key_exists('is_active', $body) && $body['is_active'] === false && $user->is_active;

        if ((int) $user->id === (int) $admin->id && ($roleChanged || $deactivating)) {
            throw ApiException::validation(['role' => ['Non puoi modificare il tuo stesso ruolo.']]);
        }

        $before = ['role' => $user->role, 'role_locked' => (bool) $user->role_locked, 'is_active' => (bool) $user->is_active];

        if ($roleChanged) {
            if (!in_array((string) $body['role'], Enums::ROLES, true)) {
                throw ApiException::validation(['role' => ['Ruolo non valido.']]);
            }
            $user->role = (string) $body['role'];
            $user->role_source = 'manual';
        }
        if (array_key_exists('role_locked', $body) && $body['role_locked'] !== null) {
            $user->role_locked = (bool) $body['role_locked'];
        }
        if (array_key_exists('is_active', $body) && $body['is_active'] !== null) {
            $user->is_active = (bool) $body['is_active'];
        }
        if (array_key_exists('notes', $body)) {
            $user->notes = $body['notes'] !== null ? (string) $body['notes'] : null;
        }
        if ($roleChanged || $deactivating) {
            $user->token_version = (int) $user->token_version + 1;
            $this->jwt->revokeAllForUser((int) $user->id);
        }
        $user->save();
        AuditLogger::log($admin, $roleChanged ? 'user.role_change' : 'user.update', 'User', (string) $user->id, [
            'before' => $before,
            'after' => ['role' => $user->role, 'role_locked' => (bool) $user->role_locked, 'is_active' => (bool) $user->is_active],
        ]);
        return $this->json($response, UserResource::toArray($user, $admin));
    }

    /** @return array<string,mixed> */
    private function withAggregates(User $user, User $viewer): array
    {
        $out = UserResource::toArray($user, $viewer);
        $out['orders_count'] = (int) Order::where('user_id', $user->id)->where('status', '!=', 'draft')->count();
        $out['active_orders_count'] = (int) Order::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved', 'picked_up', 'overdue'])->count();
        $out['late_returns_count'] = (int) Order::where('user_id', $user->id)
            ->whereIn('status', ['returned_late', 'overdue'])->count();
        return $out;
    }
}
