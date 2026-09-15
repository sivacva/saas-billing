<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Plan;
use App\Services\PlanCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanCacheTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private PlanCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = Merchant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->plan = Plan::create([
            'merchant_id' => $merchant->id, 'name' => 'Pro', 'slug' => 'pro',
            'price' => 29, 'included_usage' => 1000, 'overage_rate' => 0.01,
        ]);
        $this->cache = app(PlanCache::class);
    }

    public function test_find_actually_serves_from_cache_not_the_database(): void
    {
        $this->assertSame('29.00', $this->cache->find($this->plan->id)->price);

        // Bypass Eloquent entirely, so no model event (and no cache
        // invalidation) fires - this is the control that proves the next
        // read comes from cache, not that it just always happens to match.
        DB::table('plans')->where('id', $this->plan->id)->update(['price' => 99]);

        $this->assertSame('29.00', $this->cache->find($this->plan->id)->price);
    }

    public function test_updating_a_plan_through_eloquent_invalidates_the_cache_automatically(): void
    {
        $this->assertSame('29.00', $this->cache->find($this->plan->id)->price);

        $this->plan->update(['price' => 49]);

        $this->assertSame('49.00', $this->cache->find($this->plan->id)->price);
    }

    public function test_deleting_a_plan_invalidates_the_cache(): void
    {
        $this->cache->find($this->plan->id);

        $this->plan->delete();

        $this->assertNull($this->cache->find($this->plan->id));
    }

    public function test_restoring_a_plan_invalidates_the_cache(): void
    {
        $this->plan->delete();

        // Cache::remember() never durably caches a null result (it treats a
        // stored null the same as a miss and re-queries), so delete() alone
        // leaves nothing in the cache to prove restore()'s invalidation
        // against. Plant a stale sentinel directly to stand in for "whatever
        // was cached at delete time," so this test actually exercises
        // PlanObserver::restored() rather than passing by coincidence.
        Cache::put('plan:'.$this->plan->id, 'stale-sentinel', now()->addDay());

        $this->plan->restore();

        $this->assertNotSame('stale-sentinel', $this->cache->find($this->plan->id));
        $this->assertSame('29.00', $this->cache->find($this->plan->id)->price);
    }
}
