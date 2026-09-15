<?php

namespace Tests\Feature;

use App\Enums\CustomerSubscriptionStatus;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionPlanChange;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\UsageRollup;
use App\Services\MerchantDashboardCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantDashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_remember_serves_a_stale_snapshot_within_the_ttl_instead_of_recomputing(): void
    {
        $merchant = Merchant::create(['name' => 'Acme', 'slug' => 'acme']);
        $plan = Plan::create([
            'merchant_id' => $merchant->id, 'name' => 'Pro', 'slug' => 'pro',
            'price' => 100, 'included_usage' => 1000, 'overage_rate' => 0.10,
        ]);
        $customer = Customer::create(['merchant_id' => $merchant->id, 'name' => 'Alice', 'email' => 'alice@example.com']);
        $subscription = CustomerSubscription::create([
            'merchant_id' => $merchant->id, 'customer_id' => $customer->id, 'plan_id' => $plan->id,
            'status' => CustomerSubscriptionStatus::Active,
            'current_period_start' => '2026-01-01', 'current_period_end' => '2026-01-30',
            'started_at' => '2026-01-01',
        ]);
        $segment = CustomerSubscriptionPlanChange::create([
            'customer_subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'price' => $plan->price, 'currency' => 'usd',
            'included_usage' => $plan->included_usage, 'overage_rate' => $plan->overage_rate,
            'starts_at' => '2026-01-01',
        ]);
        $rollup = UsageRollup::create([
            'customer_subscription_id' => $subscription->id,
            'customer_subscription_plan_change_id' => $segment->id,
            'period_start' => '2026-01-01', 'period_end' => '2026-01-30',
            'quantity' => 600, 'rolled_up_through' => '2026-01-10',
        ]);

        $cache = app(MerchantDashboardCache::class);

        $first = $cache->remember($merchant);
        $this->assertSame('600.0000', $first->topCustomersByUsage[0]->usageQuantity);

        // Change the underlying summary data directly - the daily rollup job
        // would do exactly this. A live (uncached) rebuild would see 900; a
        // cache hit within the TTL should not.
        $rollup->update(['quantity' => 900]);

        $second = $cache->remember($merchant);
        $this->assertSame('600.0000', $second->topCustomersByUsage[0]->usageQuantity);
        $this->assertEquals($first->generatedAt, $second->generatedAt);
    }
}
