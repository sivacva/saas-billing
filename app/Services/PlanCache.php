<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

/**
 * The one place that knows how a Plan is cached: the key format, the read
 * path, and the invalidation path all live here. Nothing else in the app
 * should build a "plan:{id}" cache key by hand - callers that want a plan
 * go through find(), and anything that needs to bust the cache (currently
 * just PlanObserver) goes through forget(). That way there's exactly one
 * definition of "how a plan is cached" to keep in sync, not one copy per
 * caller.
 *
 * The TTL is a safety net, not the invalidation mechanism: PlanObserver
 * busts a plan's entry the moment it's saved, deleted, or restored, so a
 * cache hit is never more than a heartbeat stale in the normal case. The
 * TTL only matters if something writes to the plans table outside Eloquent
 * (a raw query, a direct DB edit) and so never fires the observer - it
 * bounds how long that kind of write could stay invisible, rather than
 * caching forever and trusting every future write path to remember Eloquent.
 */
class PlanCache
{
    private const TTL_SECONDS = 86400;

    public function find(int $planId): ?Plan
    {
        return Cache::remember(
            $this->key($planId),
            self::TTL_SECONDS,
            fn () => Plan::find($planId),
        );
    }

    public function forget(int $planId): void
    {
        Cache::forget($this->key($planId));
    }

    private function key(int $planId): string
    {
        return "plan:{$planId}";
    }
}
