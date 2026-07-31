<?php

declare(strict_types=1);

namespace App\Domain\Regulations;

use App\Domain\Settings\SettingsRepository;
use App\Models\Regulation;
use App\Models\RegulationAcceptance;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Regulation resolution & acceptance (SPEC §5.5).
 */
class RegulationService
{
    public function __construct(private SettingsRepository $settings)
    {
    }

    /**
     * Global, active, published, requires_acceptance regulations the user has
     * not accepted at the current version.
     *
     * @return array<int,Regulation>
     */
    public function pendingGlobalFor(User $user): array
    {
        if (!(bool) ($this->settings->get('regulations.enforce_global_acceptance', true) ?? true)) {
            return [];
        }
        $regs = Regulation::where('scope', 'global')
            ->where('is_active', true)
            ->where('requires_acceptance', true)
            ->whereNotNull('published_at')
            ->orderBy('position')
            ->get();
        return $this->filterUnaccepted($regs->all(), $user);
    }

    /**
     * Scoped (category/product) regulations required for a set of cart items,
     * de-duplicated.
     *
     * @param array<int,array{product_id:int, product?:object}> $items each SHOULD carry `product` with category_id
     * @return array<int,Regulation>
     */
    public function requiredForItems(array $items): array
    {
        if (!(bool) ($this->settings->get('regulations.enforce_checkout_acceptance', true) ?? true)) {
            return [];
        }
        $productIds = [];
        $categoryIds = [];
        foreach ($items as $item) {
            $productIds[] = (int) $item['product_id'];
            $product = $item['product'] ?? null;
            if ($product !== null && $product->category_id !== null) {
                $categoryIds[] = (int) $product->category_id;
            }
        }
        if ($productIds === []) {
            return [];
        }
        $regIds = Capsule::table('regulation_targets')
            ->where(function ($q) use ($productIds, $categoryIds) {
                $q->where(function ($q2) use ($productIds) {
                    $q2->where('target_type', 'product')->whereIn('target_id', $productIds);
                });
                if ($categoryIds !== []) {
                    $q->orWhere(function ($q2) use ($categoryIds) {
                        $q2->where('target_type', 'category')->whereIn('target_id', $categoryIds);
                    });
                }
            })
            ->pluck('regulation_id')
            ->unique()
            ->all();
        if ($regIds === []) {
            return [];
        }
        return Regulation::whereIn('id', $regIds)
            ->where('is_active', true)
            ->where('requires_acceptance', true)
            ->whereNotNull('published_at')
            ->orderBy('position')
            ->get()
            ->all();
    }

    /** @param array<int,Regulation> $regs @return array<int,Regulation> */
    public function filterUnaccepted(array $regs, User $user): array
    {
        return array_values(array_filter($regs, fn (Regulation $r) => !$this->hasAccepted($user, $r)));
    }

    public function hasAccepted(User $user, Regulation $regulation): bool
    {
        return RegulationAcceptance::where('regulation_id', $regulation->id)
            ->where('user_id', $user->id)
            ->where('version', $regulation->version)
            ->exists();
    }

    public function acceptance(User $user, Regulation $regulation): ?RegulationAcceptance
    {
        return RegulationAcceptance::where('regulation_id', $regulation->id)
            ->where('user_id', $user->id)
            ->where('version', $regulation->version)
            ->first();
    }

    /** Idempotent accept at the regulation's current version. */
    public function accept(User $user, Regulation $regulation, ?int $orderId = null, ?string $ip = null, ?string $userAgent = null): RegulationAcceptance
    {
        $existing = $this->acceptance($user, $regulation);
        if ($existing !== null) {
            return $existing;
        }
        return RegulationAcceptance::create([
            'regulation_id' => $regulation->id,
            'user_id' => $user->id,
            'version' => $regulation->version,
            'order_id' => $orderId,
            'accepted_at' => Dates::nowDb(),
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);
    }
}
