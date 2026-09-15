<?php

namespace App\Observers;

use App\Models\Plan;
use App\Services\PlanCache;

/**
 * Keeps PlanCache honest without anyone having to remember to call it.
 *
 * Busts on any change, not just a price edit: the cache stores the whole
 * Plan, so a name/slug/is_active edit left uninvalidated would just be a
 * different kind of staleness than a price edit. "The moment a plan's
 * price changes" is the case that matters most for billing correctness,
 * but it falls out of the simpler, always-correct rule for free.
 *
 * `deleted` and `restored` are both needed alongside `saved` because
 * Plan uses SoftDeletes: a soft delete fires `deleted`, not `saved`, even
 * though it's implemented as an UPDATE under the hood.
 */
class PlanObserver
{
    public function __construct(private PlanCache $cache) {}

    public function saved(Plan $plan): void
    {
        $this->cache->forget($plan->id);
    }

    public function deleted(Plan $plan): void
    {
        $this->cache->forget($plan->id);
    }

    public function restored(Plan $plan): void
    {
        $this->cache->forget($plan->id);
    }
}
