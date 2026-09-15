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
use App\Services\PlanCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The default test cache store (array, per phpunit.xml) never actually
 * serializes its values - it just holds the live PHP object in memory. That
 * means these tests, unlike every other cache test in the suite, deliberately
 * switch to the 'file' store, which does serialize, to catch a real bug this
 * session ran into: Laravel's config('cache.serializable_classes') defaults
 * to false, which makes unserialize() silently downgrade ANY cached object
 * to __PHP_Incomplete_Class - it only ever surfaced once this app was run
 * against a real Redis server for the first time, because that's the first
 * cache store in this app's whole history that actually serializes.
 *
 * If a future DTO gets added to what either cache service stores without
 * also being added to config/cache.php's serializable_classes allow-list,
 * these tests fail loudly instead of the bug waiting for the next person to
 * run this against real Redis/Memcached/file storage.
 */
class CacheSerializationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'file']);
        app('cache')->forgetDriver('file');
    }

    public function test_a_cached_plan_survives_a_real_serialize_round_trip(): void
    {
        $merchant = Merchant::create(['name' => 'Acme', 'slug' => 'acme']);
        $plan = Plan::create([
            'merchant_id' => $merchant->id, 'name' => 'Pro', 'slug' => 'pro',
            'price' => 29, 'included_usage' => 1000, 'overage_rate' => 0.01,
        ]);

        app(PlanCache::class)->find($plan->id); // warms the cache via a real serialize()

        $cached = app(PlanCache::class)->find($plan->id); // reads it back via a real unserialize()

        $this->assertInstanceOf(Plan::class, $cached);
        $this->assertSame('Pro', $cached->name);
    }

    public function test_a_cached_dashboard_survives_a_real_serialize_round_trip(): void
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
        UsageRollup::create([
            'customer_subscription_id' => $subscription->id,
            'customer_subscription_plan_change_id' => $segment->id,
            'period_start' => '2026-01-01', 'period_end' => '2026-01-30',
            'quantity' => 600, 'rolled_up_through' => '2026-01-10',
        ]);

        app(MerchantDashboardCache::class)->remember($merchant); // real serialize()

        $cached = app(MerchantDashboardCache::class)->remember($merchant); // real unserialize()

        $this->assertSame('Alice', $cached->topCustomersByUsage[0]->customerName);
        $this->assertSame('600.0000', $cached->topCustomersByUsage[0]->usageQuantity);
    }
}
