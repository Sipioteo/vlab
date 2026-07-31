<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Settings\SettingsRepository;
use App\Domain\Stats\StatsService;
use App\Http\Resources\OrderEventResource;
use App\Models\OrderEvent;
use App\Support\ApiException;
use App\Support\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class StatsController extends Controller
{
    public function __construct(
        private StatsService $stats,
        private SettingsRepository $settings,
    ) {
    }

    public function overview(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $query = $request->getQueryParams();
        [$from, $to] = $this->stats->resolveRange($query['from'] ?? null, $query['to'] ?? null);
        $limited = $user->role === 'assistant';
        return $this->json($response, $this->stats->overview($from, $to, $limited));
    }

    public function loansOverTime(Request $request, Response $response): Response
    {
        $this->requireUser($request);
        $query = $request->getQueryParams();
        [$from, $to] = $this->stats->resolveRange($query['from'] ?? null, $query['to'] ?? null);
        $granularity = $this->granularity($query);
        $metric = in_array($query['metric'] ?? 'orders', ['orders', 'items'], true) ? (string) ($query['metric'] ?? 'orders') : 'orders';
        $categoryId = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;
        return $this->json($response, $this->stats->loansOverTime($from, $to, $granularity, $categoryId, $metric));
    }

    public function topProducts(Request $request, Response $response): Response
    {
        $this->requireUser($request);
        $query = $request->getQueryParams();
        [$from, $to] = $this->stats->resolveRange($query['from'] ?? null, $query['to'] ?? null);
        $default = (int) ($this->settings->get('stats.top_products_limit', 10) ?? 10);
        $limit = min(100, max(1, (int) ($query['limit'] ?? $default)));
        $metric = in_array($query['metric'] ?? 'orders', ['orders', 'quantity', 'days'], true) ? (string) ($query['metric'] ?? 'orders') : 'orders';
        $categoryId = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;
        return $this->json($response, $this->stats->topProducts($from, $to, $limit, $categoryId, $metric));
    }

    public function byCategory(Request $request, Response $response): Response
    {
        $this->requireUser($request);
        $query = $request->getQueryParams();
        [$from, $to] = $this->stats->resolveRange($query['from'] ?? null, $query['to'] ?? null);
        return $this->json($response, $this->stats->byCategory($from, $to));
    }

    public function lateReturns(Request $request, Response $response): Response
    {
        $this->requireUser($request);
        $query = $request->getQueryParams();
        [$from, $to] = $this->stats->resolveRange($query['from'] ?? null, $query['to'] ?? null);
        $minDays = max(1, (int) ($query['min_days'] ?? 1));
        $includeOpen = self::boolParam($query, 'include_open', true);
        [$entries, $summary] = $this->stats->lateReturns($from, $to, $minDays, $includeOpen ?? true);
        $paginator = new Paginator($query);
        [$page, $meta] = $paginator->paginateArray($entries);
        return $this->json($response, ['data' => $page, 'meta' => $meta, 'summary' => $summary]);
    }

    public function utilization(Request $request, Response $response): Response
    {
        $this->requireUser($request);
        $query = $request->getQueryParams();
        [$from, $to] = $this->stats->resolveRange($query['from'] ?? null, $query['to'] ?? null);
        $granularity = $this->granularity($query);
        $categoryId = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;
        $productId = isset($query['product_id']) && $query['product_id'] !== '' ? (int) $query['product_id'] : null;
        return $this->json($response, $this->stats->utilization($from, $to, $granularity, $categoryId, $productId));
    }

    public function myActivity(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $query = $request->getQueryParams();
        [$from, $to] = $this->stats->resolveRange($query['from'] ?? null, $query['to'] ?? null);
        $out = $this->stats->myActivity($user, $from, $to, $this->granularity($query));
        $recent = OrderEvent::with('order')
            ->where('actor_id', $user->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(10)->get();
        $out['recent_events'] = array_map(static function ($e) {
            $event = OrderEventResource::toArray($e);
            $event['order'] = $e->order !== null ? ['id' => (int) $e->order->id, 'code' => $e->order->code] : null;
            return $event;
        }, $recent->all());
        return $this->json($response, $out);
    }

    public function export(Request $request, Response $response): Response
    {
        $this->requireUser($request);
        $query = $request->getQueryParams();
        $dataset = (string) ($query['dataset'] ?? '');
        if (!in_array($dataset, ['orders', 'products', 'late_returns', 'logs'], true)) {
            throw ApiException::validation(['dataset' => ['Dataset non valido (orders|products|late_returns|logs).']]);
        }
        [$from, $to] = $this->stats->resolveRange($query['from'] ?? null, $query['to'] ?? null);
        [, $rows] = $this->stats->exportRows($dataset, $from, $to);

        // CSV: BOM + CRLF + quoted fields (SPEC §7.12 #84).
        $csv = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $cells = array_map(static function ($cell) {
                $cell = $cell === null ? '' : (string) $cell;
                return '"' . str_replace('"', '""', $cell) . '"';
            }, $row);
            $csv .= implode(',', $cells) . "\r\n";
        }
        $filename = sprintf('vlab-%s-%s_%s.csv', $dataset, $from, $to);
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    private function granularity(array $query): string
    {
        $default = (string) ($this->settings->get('stats.default_granularity', 'week') ?? 'week');
        $granularity = (string) ($query['granularity'] ?? $default);
        return in_array($granularity, ['day', 'week', 'month'], true) ? $granularity : 'week';
    }
}
