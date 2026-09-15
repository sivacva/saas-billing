<?php

namespace App\Services;

use App\Actions\BuildMerchantDashboard;
use App\DTOs\MerchantDashboardData;
use App\Models\Merchant;
use Illuminate\Support\Facades\Cache;

/**
 * Caches the merchant dashboard behind a plain TTL - deliberately not the
 * observer-based instant invalidation PlanCache uses. That pattern fits a
 * Plan, which changes rarely and where staleness is directly visible to a
 * customer. It would be actively counterproductive here: the dashboard's
 * inputs (usage_rollups) are written once per subscription *every single
 * day* by the rollup job, across however many subscriptions a merchant
 * has. Busting on every write would mean the cache barely survives between
 * requests - the opposite of what caching this was for.
 *
 * A plain TTL is also the honest answer to what this data actually is:
 * usage_rollups and invoice_lines only change on the daily batch jobs'
 * schedule, so the dashboard is never fresher than "as of the last rollup"
 * regardless of caching. A hazard, not a mechanism, is not needed to keep
 * a snapshot honest that's already only daily-fresh by construction - the
 * TTL just bounds how long a single computed snapshot gets reused.
 */
class MerchantDashboardCache
{
    private const int TTL_SECONDS = 3600;

    public function __construct(private BuildMerchantDashboard $builder) {}

    public function remember(Merchant $merchant): MerchantDashboardData
    {
        return Cache::remember(
            $this->key($merchant->id),
            self::TTL_SECONDS,
            fn () => $this->builder->handle($merchant),
        );
    }

    private function key(int $merchantId): string
    {
        return "dashboard:merchant:{$merchantId}";
    }
}
