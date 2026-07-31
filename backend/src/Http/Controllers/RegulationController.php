<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\JwtService;
use App\Domain\Regulations\RegulationService;
use App\Http\Middleware\AuthenticateMiddleware;
use App\Http\Resources\RegulationResource;
use App\Http\Resources\UserResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Regulation;
use App\Models\RegulationAcceptance;
use App\Models\RegulationTarget;
use App\Models\User;
use App\Support\ApiException;
use App\Support\AuditLogger;
use App\Support\Dates;
use App\Support\Paginator;
use App\Support\Str;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RegulationController extends Controller
{
    public function __construct(
        private RegulationService $regulations,
        private JwtService $jwt,
        private array $config,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        $isStaff = $user !== null && $user->isStaff();
        $query = $request->getQueryParams();
        [$sort, $order] = self::sortParams($query, ['position', 'title', 'version', 'published_at'], 'position');
        $builder = Regulation::query();
        if (!$isStaff) {
            $builder->where('is_active', true)->whereNotNull('published_at');
        } else {
            $isActive = self::boolParam($query, 'is_active');
            if ($isActive !== null) {
                $builder->where('is_active', $isActive);
            }
        }
        if (isset($query['scope']) && in_array((string) $query['scope'], ['global', 'category', 'product'], true)) {
            $builder->where('scope', (string) $query['scope']);
        }
        $requiresAcceptance = self::boolParam($query, 'requires_acceptance');
        if ($requiresAcceptance !== null) {
            $builder->where('requires_acceptance', $requiresAcceptance);
        }
        foreach (['product_id' => 'product', 'category_id' => 'category'] as $param => $targetType) {
            if (isset($query[$param]) && $query[$param] !== '') {
                $regIds = RegulationTarget::where('target_type', $targetType)
                    ->where('target_id', (int) $query[$param])
                    ->pluck('regulation_id')->all();
                $builder->whereIn('id', $regIds !== [] ? $regIds : [0]);
            }
        }
        $builder->orderBy($sort, $order);
        $paginator = new Paginator($query);
        [$rows, $meta] = $paginator->paginateBuilder($builder);
        return $this->json($response, [
            'data' => array_map(fn ($r) => RegulationResource::toArray($r, $user, false), $rows),
            'meta' => $meta,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        $reg = $this->find((string) $args['idOrSlug'], $user);
        return $this->json($response, RegulationResource::toArray($reg, $user, true));
    }

    /** GET /regulations/{id}/file — accepts Authorization header OR ?token= (access tokens only). */
    public function file(Request $request, Response $response, array $args): Response
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
        $reg = $this->find((string) $args['id'], $user);
        if ($reg->content_type !== 'pdf' || $reg->file_path === null) {
            throw ApiException::notFound('Il regolamento non ha un file PDF.');
        }
        $path = $this->storagePath() . '/' . ltrim((string) $reg->file_path, '/');
        if (!is_file($path)) {
            throw ApiException::notFound('File non trovato.');
        }
        $contents = (string) file_get_contents($path);
        $response->getBody()->write($contents);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . addslashes((string) ($reg->file_name ?? 'regolamento.pdf')) . '"')
            ->withHeader('Content-Length', (string) strlen($contents));
    }

    public function store(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $data = $this->validatePayload($body, null);
        $reg = Regulation::create([
            'title' => $data['title'],
            'slug' => $data['slug'],
            'summary' => $body['summary'] ?? null,
            'scope' => $data['scope'],
            'content_type' => in_array($body['content_type'] ?? 'markdown', ['markdown', 'pdf'], true) ? ($body['content_type'] ?? 'markdown') : 'markdown',
            'body' => $body['body'] ?? null,
            'requires_acceptance' => (bool) ($body['requires_acceptance'] ?? true),
            'is_active' => (bool) ($body['is_active'] ?? true),
            'position' => (int) ($body['position'] ?? 0),
            'version' => 1,
            'published_at' => ((bool) ($body['publish'] ?? false)) ? Dates::nowDb() : null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $this->replaceTargets($reg, $data['targets']);
        AuditLogger::log($user, 'regulation.create', 'Regulation', (string) $reg->id, null);
        return $this->json($response, RegulationResource::toArray($reg->refresh(), $user, true), 201)
            ->withHeader('Location', '/api/v1/regulations/' . $reg->id);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $reg = Regulation::find((int) $args['id']);
        if ($reg === null) {
            throw ApiException::notFound('Regolamento non trovato.');
        }
        $body = $this->body($request);
        if (array_key_exists('title', $body) && $body['title'] !== null) {
            $reg->title = trim((string) $body['title']);
        }
        if (array_key_exists('slug', $body) && $body['slug'] !== null && $body['slug'] !== $reg->slug) {
            if (Regulation::withTrashed()->where('slug', (string) $body['slug'])->where('id', '!=', $reg->id)->exists()) {
                throw ApiException::conflict('duplicate_slug', 'Slug già in uso.');
            }
            $reg->slug = (string) $body['slug'];
        }
        foreach (['summary', 'body'] as $field) {
            if (array_key_exists($field, $body)) {
                $reg->{$field} = $body[$field];
            }
        }
        if (array_key_exists('scope', $body) && in_array((string) $body['scope'], ['global', 'category', 'product'], true)) {
            $reg->scope = (string) $body['scope'];
        }
        if (array_key_exists('content_type', $body) && in_array((string) $body['content_type'], ['markdown', 'pdf'], true)) {
            $reg->content_type = (string) $body['content_type'];
        }
        foreach (['requires_acceptance', 'is_active'] as $field) {
            if (array_key_exists($field, $body) && $body[$field] !== null) {
                $reg->{$field} = (bool) $body[$field];
            }
        }
        if (array_key_exists('position', $body) && $body['position'] !== null) {
            $reg->position = (int) $body['position'];
        }
        $reg->updated_by = $user->id;
        $reg->save();

        if (isset($body['targets']) && is_array($body['targets'])) {
            if ($reg->scope === 'global' && $body['targets'] !== []) {
                throw ApiException::validation(['targets' => ['I regolamenti globali non hanno destinatari.']]);
            }
            $this->replaceTargets($reg, $this->validateTargets($reg->scope, $body['targets']));
        }
        AuditLogger::log($user, 'regulation.update', 'Regulation', (string) $reg->id, null);
        return $this->json($response, RegulationResource::toArray($reg->refresh(), $user, true));
    }

    /** POST /regulations/{id}/file — multipart upload. */
    public function upload(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $reg = Regulation::find((int) $args['id']);
        if ($reg === null) {
            throw ApiException::notFound('Regolamento non trovato.');
        }
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            throw ApiException::validation(['file' => ['Il file è obbligatorio.']]);
        }
        $maxBytes = (int) ($this->config['storage']['upload_max_bytes'] ?? 10485760);
        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            throw new ApiException(413, 'payload_too_large', 'Il file supera la dimensione massima consentita.');
        }
        $contents = (string) $file->getStream();
        if (strlen($contents) > $maxBytes) {
            throw new ApiException(413, 'payload_too_large', 'Il file supera la dimensione massima consentita.');
        }
        $mime = (string) $file->getClientMediaType();
        if ($mime !== 'application/pdf' || !str_starts_with($contents, '%PDF')) {
            throw new ApiException(415, 'unsupported_media_type', 'È consentito solo il formato PDF.');
        }
        $sha1 = sha1($contents);
        $relative = 'uploads/regulations/' . $reg->id . '/' . $sha1 . '.pdf';
        $full = $this->storagePath() . '/' . $relative;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $contents);
        $reg->content_type = 'pdf';
        $reg->file_path = $relative;
        $reg->file_name = $file->getClientFilename() ?: 'regolamento.pdf';
        $reg->file_size = strlen($contents);
        $reg->file_mime = 'application/pdf';
        $reg->updated_by = $user->id;
        $reg->save();
        return $this->json($response, RegulationResource::toArray($reg, $user, true));
    }

    public function publish(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $reg = Regulation::find((int) $args['id']);
        if ($reg === null) {
            throw ApiException::notFound('Regolamento non trovato.');
        }
        $body = $this->body($request);
        $bump = (bool) ($body['bump_version'] ?? true);
        $wasPublished = $reg->published_at !== null;
        if ($bump && $wasPublished) {
            $reg->version = (int) $reg->version + 1;
        }
        $reg->published_at = Dates::nowDb();
        $reg->updated_by = $user->id;
        $reg->save();
        AuditLogger::log($user, 'regulation.publish', 'Regulation', (string) $reg->id, [
            'after' => ['version' => (int) $reg->version, 'note' => $body['note'] ?? null],
        ]);
        return $this->json($response, RegulationResource::toArray($reg, $user, true));
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $reg = Regulation::find((int) $args['id']);
        if ($reg === null) {
            throw ApiException::notFound('Regolamento non trovato.');
        }
        $reg->delete();
        AuditLogger::log($this->user($request), 'regulation.delete', 'Regulation', (string) $reg->id, null);
        return $response->withStatus(204);
    }

    public function acceptances(Request $request, Response $response, array $args): Response
    {
        $this->requireUser($request);
        $reg = Regulation::find((int) $args['id']);
        if ($reg === null) {
            throw ApiException::notFound('Regolamento non trovato.');
        }
        $query = $request->getQueryParams();
        $builder = RegulationAcceptance::where('regulation_id', $reg->id)->with('user')->orderByDesc('accepted_at');
        if (isset($query['version']) && $query['version'] !== '') {
            $builder->where('version', (int) $query['version']);
        }
        if (isset($query['user_id']) && $query['user_id'] !== '') {
            $builder->where('user_id', (int) $query['user_id']);
        }
        $paginator = new Paginator($query);
        [$rows, $meta] = $paginator->paginateBuilder($builder);
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id' => (int) $row->id,
                'user' => $row->user !== null ? UserResource::mini($row->user) : null,
                'version' => (int) $row->version,
                'order_id' => $row->order_id !== null ? (int) $row->order_id : null,
                'accepted_at' => Dates::iso($row->accepted_at),
                'ip' => $row->ip,
            ];
        }
        return $this->json($response, [
            'data' => $data,
            'meta' => $meta,
            'stats' => [
                'current_version' => (int) $reg->version,
                'accepted_current_version' => (int) RegulationAcceptance::where('regulation_id', $reg->id)->where('version', $reg->version)->count(),
                'total_users' => (int) Capsule::table('users')->whereNull('deleted_at')->count(),
            ],
        ]);
    }

    /** GET /me/regulations/pending — global scope only (SPEC §7.10 #63). */
    public function pendingMine(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $pending = $this->regulations->pendingGlobalFor($user);
        return $this->json($response, [
            'data' => array_map(static fn ($r) => RegulationResource::pendingItem($r, true), $pending),
            'meta' => null,
        ]);
    }

    /** POST /me/regulations/{id}/accept. */
    public function accept(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $reg = Regulation::find((int) $args['id']);
        if ($reg === null || !$reg->is_active || $reg->published_at === null) {
            throw ApiException::notFound('Regolamento non trovato.');
        }
        $body = $this->body($request);
        $version = isset($body['version']) ? (int) $body['version'] : null;
        if ($version === null) {
            throw ApiException::validation(['version' => ['Il campo version è obbligatorio.']]);
        }
        if ($version !== (int) $reg->version) {
            throw ApiException::conflict('conflict', 'Il regolamento è stato aggiornato, ricarica la pagina.');
        }
        $serverParams = $request->getServerParams();
        $acceptance = $this->regulations->accept(
            $user,
            $reg,
            isset($body['order_id']) && $body['order_id'] !== null ? (int) $body['order_id'] : null,
            (string) ($serverParams['REMOTE_ADDR'] ?? '') ?: null,
            $request->getHeaderLine('User-Agent') ?: null
        );
        return $this->json($response, [
            'accepted' => true,
            'regulation_id' => (int) $reg->id,
            'version' => (int) $acceptance->version,
            'accepted_at' => Dates::iso($acceptance->accepted_at),
            'pending_regulations' => array_map(
                static fn ($r) => RegulationResource::pendingItem($r, true),
                $this->regulations->pendingGlobalFor($user)
            ),
        ]);
    }

    // ---------------------------------------------------------------- utils

    private function find(string $idOrSlug, ?User $user): Regulation
    {
        $reg = ctype_digit($idOrSlug)
            ? Regulation::find((int) $idOrSlug)
            : Regulation::where('slug', $idOrSlug)->first();
        $isStaff = $user !== null && $user->isStaff();
        if ($reg === null || (!$isStaff && (!$reg->is_active || $reg->published_at === null))) {
            throw ApiException::notFound('Regolamento non trovato.');
        }
        return $reg;
    }

    /** @return array{title:string, slug:string, scope:string, targets:array<int,array{target_type:string,target_id:int}>} */
    private function validatePayload(array $body, ?Regulation $existing): array
    {
        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        if (mb_strlen($title) < 2 || mb_strlen($title) > 191) {
            throw ApiException::validation(['title' => ['Il titolo è obbligatorio (2..191 caratteri).']]);
        }
        $scope = (string) ($body['scope'] ?? 'global');
        if (!in_array($scope, ['global', 'category', 'product'], true)) {
            throw ApiException::validation(['scope' => ['Ambito non valido.']]);
        }
        $targets = $this->validateTargets($scope, is_array($body['targets'] ?? null) ? $body['targets'] : []);
        $slug = isset($body['slug']) && $body['slug'] !== null && $body['slug'] !== '' ? (string) $body['slug'] : null;
        if ($slug !== null) {
            if (Regulation::withTrashed()->where('slug', $slug)->exists()) {
                throw ApiException::conflict('duplicate_slug', 'Slug già in uso.');
            }
        } else {
            $slug = Str::uniqueSlug(Str::slug($title), static fn ($s) => Regulation::withTrashed()->where('slug', $s)->exists());
        }
        return ['title' => $title, 'slug' => $slug, 'scope' => $scope, 'targets' => $targets];
    }

    /** @return array<int,array{target_type:string,target_id:int}> */
    private function validateTargets(string $scope, array $targets): array
    {
        if ($scope === 'global') {
            if ($targets !== []) {
                throw ApiException::validation(['targets' => ['I regolamenti globali non hanno destinatari.']]);
            }
            return [];
        }
        if ($targets === []) {
            throw ApiException::validation(['targets' => ['Specificare almeno un destinatario.']]);
        }
        $out = [];
        foreach ($targets as $target) {
            $target = (array) $target;
            $type = (string) ($target['target_type'] ?? '');
            $id = (int) ($target['target_id'] ?? 0);
            if (!in_array($type, ['category', 'product'], true) || $id <= 0) {
                throw ApiException::validation(['targets' => ['Destinatario non valido.']]);
            }
            $exists = $type === 'category' ? Category::find($id) !== null : Product::find($id) !== null;
            if (!$exists) {
                throw ApiException::validation(['targets' => ['Destinatario inesistente: ' . $type . ' #' . $id]]);
            }
            $out[] = ['target_type' => $type, 'target_id' => $id];
        }
        return $out;
    }

    /** @param array<int,array{target_type:string,target_id:int}> $targets */
    private function replaceTargets(Regulation $reg, array $targets): void
    {
        RegulationTarget::where('regulation_id', $reg->id)->delete();
        foreach ($targets as $target) {
            RegulationTarget::create([
                'regulation_id' => $reg->id,
                'target_type' => $target['target_type'],
                'target_id' => $target['target_id'],
            ]);
        }
    }

    private function storagePath(): string
    {
        $path = (string) ($this->config['storage']['path'] ?? 'storage');
        if (!str_starts_with($path, '/')) {
            $path = dirname(__DIR__, 3) . '/' . $path;
        }
        return rtrim($path, '/');
    }
}
