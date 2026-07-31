<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Product;
use App\Support\ApiException;
use App\Support\AuditLogger;
use App\Support\Str;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CategoryController extends Controller
{
    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $user = $this->user($request);
        $includeInactive = self::boolParam($query, 'include_inactive', false) && $user !== null && $user->isStaff();
        $builder = Category::query()->orderBy('position')->orderBy('name');
        if (!$includeInactive) {
            $builder->where('is_active', true);
        }
        $data = array_map(static fn ($c) => CategoryResource::toArray($c), $builder->get()->all());
        return $this->json($response, ['data' => $data, 'meta' => null]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $category = $this->find((string) $args['idOrSlug']);
        $out = CategoryResource::toArray($category);
        $regulations = [];
        $regIds = \Illuminate\Database\Capsule\Manager::table('regulation_targets')
            ->where('target_type', 'category')->where('target_id', $category->id)
            ->pluck('regulation_id')->all();
        if ($regIds !== []) {
            foreach (\App\Models\Regulation::whereIn('id', $regIds)->where('is_active', true)->whereNotNull('published_at')->get() as $reg) {
                $regulations[] = [
                    'id' => (int) $reg->id,
                    'slug' => $reg->slug,
                    'title' => $reg->title,
                    'scope' => $reg->scope,
                    'version' => (int) $reg->version,
                    'requires_acceptance' => (bool) $reg->requires_acceptance,
                ];
            }
        }
        $out['regulations'] = $regulations;
        return $this->json($response, $out);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $name = isset($body['name']) ? trim((string) $body['name']) : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 191) {
            throw ApiException::validation(['name' => ['Il nome è obbligatorio (2..191 caratteri).']]);
        }
        $slug = isset($body['slug']) && $body['slug'] !== null && $body['slug'] !== '' ? (string) $body['slug'] : null;
        if ($slug !== null) {
            if (Category::withTrashed()->where('slug', $slug)->exists()) {
                throw ApiException::conflict('duplicate_slug', 'Slug già in uso.');
            }
        } else {
            $slug = Str::uniqueSlug(Str::slug($name), static fn ($s) => Category::withTrashed()->where('slug', $s)->exists());
        }
        $category = Category::create([
            'name' => $name,
            'slug' => $slug,
            'description' => $body['description'] ?? null,
            'icon' => $body['icon'] ?? null,
            'image_url' => $body['image_url'] ?? null,
            'parent_id' => $body['parent_id'] ?? null,
            'position' => (int) ($body['position'] ?? 0),
            'is_active' => (bool) ($body['is_active'] ?? true),
        ]);
        AuditLogger::log($this->user($request), 'category.create', 'Category', (string) $category->id, ['after' => ['name' => $name, 'slug' => $slug]]);
        return $this->json($response, CategoryResource::toArray($category), 201)
            ->withHeader('Location', '/api/v1/categories/' . $category->id);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $category = Category::find((int) $args['id']);
        if ($category === null) {
            throw ApiException::notFound();
        }
        $body = $this->body($request);
        if (array_key_exists('name', $body) && $body['name'] !== null) {
            $name = trim((string) $body['name']);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 191) {
                throw ApiException::validation(['name' => ['Il nome deve avere 2..191 caratteri.']]);
            }
            $category->name = $name;
        }
        if (array_key_exists('slug', $body) && $body['slug'] !== null && $body['slug'] !== $category->slug) {
            if (Category::withTrashed()->where('slug', (string) $body['slug'])->where('id', '!=', $category->id)->exists()) {
                throw ApiException::conflict('duplicate_slug', 'Slug già in uso.');
            }
            $category->slug = (string) $body['slug'];
        }
        foreach (['description', 'icon', 'image_url', 'parent_id'] as $field) {
            if (array_key_exists($field, $body)) {
                $category->{$field} = $body[$field];
            }
        }
        if (array_key_exists('position', $body) && $body['position'] !== null) {
            $category->position = (int) $body['position'];
        }
        if (array_key_exists('is_active', $body) && $body['is_active'] !== null) {
            $category->is_active = (bool) $body['is_active'];
        }
        $category->save();
        AuditLogger::log($this->user($request), 'category.update', 'Category', (string) $category->id, null);
        return $this->json($response, CategoryResource::toArray($category));
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $category = Category::find((int) $args['id']);
        if ($category === null) {
            throw ApiException::notFound();
        }
        $count = Product::where('category_id', $category->id)->count();
        if ($count > 0) {
            throw ApiException::conflict('category_not_empty', 'La categoria contiene ancora prodotti.', ['products_count' => $count]);
        }
        $category->delete();
        AuditLogger::log($this->user($request), 'category.delete', 'Category', (string) $category->id, null);
        return $response->withStatus(204);
    }

    private function find(string $idOrSlug): Category
    {
        $category = ctype_digit($idOrSlug)
            ? Category::find((int) $idOrSlug)
            : Category::where('slug', $idOrSlug)->first();
        if ($category === null) {
            throw ApiException::notFound('Categoria non trovata.');
        }
        return $category;
    }
}
