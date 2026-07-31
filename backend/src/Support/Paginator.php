<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pagination convention of SPEC §7.2.
 */
final class Paginator
{
    public int $page;
    public int $perPage;

    public function __construct(array $query, ?int $defaultPerPage = null)
    {
        $default = $defaultPerPage ?? (int) (SettingsRepository::instance()->get('ui.items_per_page', 24) ?? 24);
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? $default);
        $this->page = max(1, $page);
        $this->perPage = min(100, max(1, $perPage ?: $default));
    }

    /**
     * @return array{0: array<int,mixed>, 1: array<string,int>} [items, meta]
     */
    public function paginateBuilder(Builder $builder): array
    {
        $total = (clone $builder)->count();
        $items = $builder->skip(($this->page - 1) * $this->perPage)->take($this->perPage)->get()->all();
        return [$items, $this->meta($total)];
    }

    /**
     * @param array<int,mixed> $items full result set (already sorted)
     * @return array{0: array<int,mixed>, 1: array<string,int>}
     */
    public function paginateArray(array $items): array
    {
        $total = count($items);
        $slice = array_slice($items, ($this->page - 1) * $this->perPage, $this->perPage);
        return [$slice, $this->meta($total)];
    }

    /** @return array{page:int, per_page:int, total:int, total_pages:int} */
    public function meta(int $total): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $total,
            'total_pages' => (int) ceil($total / $this->perPage),
        ];
    }
}
