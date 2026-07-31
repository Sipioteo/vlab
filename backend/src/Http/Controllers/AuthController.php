<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\JwtService;
use App\Domain\Auth\LdapAuthenticatorInterface;
use App\Domain\Auth\LdapUnavailableException;
use App\Domain\Auth\RoleResolver;
use App\Domain\Orders\OrderService;
use App\Domain\Regulations\RegulationService;
use App\Http\Resources\RegulationResource;
use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\ApiException;
use App\Support\Dates;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController extends Controller
{
    public function __construct(
        private LdapAuthenticatorInterface $ldap,
        private RoleResolver $roleResolver,
        private JwtService $jwt,
        private RegulationService $regulations,
        private OrderService $orders,
    ) {
    }

    public function login(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $username = isset($body['username']) ? trim((string) $body['username']) : '';
        $password = isset($body['password']) ? (string) $body['password'] : '';
        $errors = [];
        if ($username === '' || mb_strlen($username) > 191) {
            $errors['username'] = ['Il campo username è obbligatorio.'];
        }
        if ($password === '' || mb_strlen($password) > 255) {
            $errors['password'] = ['Il campo password è obbligatorio.'];
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        try {
            $ldapUser = $this->ldap->authenticate($username, $password);
        } catch (LdapUnavailableException $e) {
            throw new ApiException(503, 'ldap_unavailable', 'Il servizio di autenticazione non è raggiungibile.');
        }
        if ($ldapUser === null) {
            throw ApiException::unauthenticated('invalid_credentials', 'Credenziali non valide.');
        }

        $user = User::withTrashed()->where('ldap_uid', $ldapUser->uid)->first();
        if ($user !== null && $user->trashed()) {
            throw new ApiException(403, 'account_disabled', 'Account disabilitato.');
        }
        $role = $this->roleResolver->resolve($ldapUser, $user);
        if ($user === null) {
            $user = new User([
                'ldap_uid' => $ldapUser->uid,
                'role' => $role,
                'role_source' => 'ldap',
                'is_active' => true,
                'token_version' => 1,
            ]);
        }
        if (!$user->role_locked) {
            $user->role = $role;
        }
        $user->email = $ldapUser->email ?? $user->email;
        $user->first_name = $ldapUser->firstName ?? $user->first_name;
        $user->last_name = $ldapUser->lastName ?? $user->last_name;
        $user->display_name = $ldapUser->displayName ?? $user->display_name;
        if (isset($ldapUser->raw['matricola']) && $ldapUser->raw['matricola'] !== null) {
            $user->matricola = (string) $ldapUser->raw['matricola'];
        }
        $user->ldap_groups = json_encode($ldapUser->groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $user->last_login_at = Dates::nowDb();
        $user->save();

        if (!$user->is_active) {
            throw new ApiException(403, 'account_disabled', 'Account disabilitato.');
        }

        return $this->json($response, $this->tokenPayload($request, $user));
    }

    public function refresh(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $plain = isset($body['refresh_token']) ? (string) $body['refresh_token'] : '';
        if ($plain === '') {
            throw ApiException::unauthenticated('refresh_invalid', 'Token di rinnovo mancante.');
        }
        $row = $this->jwt->consumeRefreshToken($plain);
        $user = User::find($row->user_id);
        if ($user === null || !$user->is_active) {
            throw new ApiException(403, 'account_disabled', 'Account disabilitato.');
        }
        return $this->json($response, $this->tokenPayload($request, $user, (string) $row->family_id));
    }

    public function logout(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $plain = isset($body['refresh_token']) ? (string) $body['refresh_token'] : '';
        if ($plain !== '') {
            $this->jwt->revokeToken($plain);
        } else {
            $this->jwt->revokeAllForUser((int) $user->id);
        }
        return $response->withStatus(204);
    }

    public function me(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $cart = Order::where('user_id', $user->id)->where('status', 'draft')->first();
        $cartCount = $cart !== null ? (int) OrderItem::where('order_id', $cart->id)->sum('quantity') : 0;
        $activeCount = (int) Order::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved', 'picked_up', 'overdue'])
            ->count();
        return $this->json($response, [
            'user' => UserResource::toArray($user, $user->isStaff() ? $user : null),
            'permissions' => self::permissions($user),
            'pending_regulations' => array_map(
                static fn ($r) => RegulationResource::pendingItem($r),
                $this->regulations->pendingGlobalFor($user)
            ),
            'cart_items_count' => $cartCount,
            'active_orders_count' => $activeCount,
        ]);
    }

    public function updateMe(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        if (array_key_exists('phone', $body)) {
            $user->phone = $body['phone'] !== null ? mb_substr((string) $body['phone'], 0, 32) : null;
        }
        if (array_key_exists('course', $body)) {
            $user->course = $body['course'] !== null ? mb_substr((string) $body['course'], 0, 191) : null;
        }
        $user->save();
        return $this->json($response, ['user' => UserResource::toArray($user, $user->isStaff() ? $user : null)]);
    }

    /**
     * §9.3 permissions object — the definitive borsista answer.
     *
     * @return array<string,bool>
     */
    public static function permissions(User $user): array
    {
        $role = (string) $user->role;
        $is = static fn (string ...$roles): bool => in_array($role, $roles, true);
        return [
            'products.manage' => $is('technician', 'admin'),
            'orders.manage' => $is('assistant', 'technician', 'admin'),
            'orders.create' => $is('student'),
            'logs.create' => $is('assistant', 'technician', 'admin'),
            'settings.manage' => $is('admin'),
            'settings.view' => $is('assistant', 'technician', 'admin'),
            'stats.view_full' => $is('technician', 'admin'),
            'stats.view_limited' => $is('assistant', 'technician', 'admin'),
            'users.manage' => $is('admin'),
            'users.view' => $is('assistant', 'technician', 'admin'),
            'regulations.manage' => $is('technician', 'admin'),
            'regulations.delete' => $is('admin'),
            'closures.manage' => $is('technician', 'admin'),
            'orders.reopen' => $is('admin'),
            'audit.view' => $is('admin'),
        ];
    }

    /** @return array<string,mixed> */
    private function tokenPayload(Request $request, User $user, ?string $familyId = null): array
    {
        $serverParams = $request->getServerParams();
        $ip = (string) ($serverParams['REMOTE_ADDR'] ?? '');
        $userAgent = $request->getHeaderLine('User-Agent') ?: null;
        $access = $this->jwt->issueAccessToken($user);
        $refresh = $this->jwt->issueRefreshToken($user, $familyId, $ip !== '' ? $ip : null, $userAgent);
        return [
            'access_token' => $access['token'],
            'token_type' => 'Bearer',
            'expires_in' => $access['expires_in'],
            'expires_at' => $access['expires_at'],
            'refresh_token' => $refresh['token'],
            'refresh_expires_at' => $refresh['expires_at'],
            'user' => UserResource::toArray($user, $user->isStaff() ? $user : null),
            'pending_regulations' => array_map(
                static fn ($r) => RegulationResource::pendingItem($r),
                $this->regulations->pendingGlobalFor($user)
            ),
        ];
    }
}
