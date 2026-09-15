<?php

namespace Tests\Feature;

use App\Actions\RollUpSubscriptionUsage;
use App\Enums\CustomerSubscriptionStatus;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionPlanChange;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\UsageRecord;
use App\Models\UsageRollup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RollUpSubscriptionUsageTest extends TestCase
{
    use RefreshDatabase;

    private CustomerSubscription $subscription;

    private CustomerSubscriptionPlanChange $segment;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = Merchant::create(['name' => 'Acme', 'slug' => 'acme']);
        $customer = Customer::create(['merchant_id' => $merchant->id, 'name' => 'Bob', 'email' => 'bob@example.com']);
        $plan = Plan::create([
            'merchant_id' => $merchant->id, 'name' => 'Pro', 'slug' => 'pro',
            'price' => 29, 'included_usage' => 1000, 'overage_rate' => 0.01,
        ]);

        $this->subscription = CustomerSubscription::create([
            'merchant_id' => $merchant->id, 'customer_id' => $customer->id, 'plan_id' => $plan->id,
            'status' => CustomerSubscriptionStatus::Active,
            'current_period_start' => '2026-01-01', 'current_period_end' => '2026-01-31',
            'started_at' => '2026-01-01',
        ]);

        $this->segment = CustomerSubscriptionPlanChange::create([
            'customer_subscription_id' => $this->subscription->id, 'plan_id' => $plan->id,
            'price' => $plan->price, 'currency' => 'usd',
            'included_usage' => $plan->included_usage, 'overage_rate' => $plan->overage_rate,
            'starts_at' => '2026-01-01',
        ]);
    }

    private function recordUsage(string $date, float $quantity): void
    {
        UsageRecord::create([
            'merchant_id' => $this->subscription->merchant_id,
            'customer_id' => $this->subscription->customer_id,
            'customer_subscription_id' => $this->subscription->id,
            'plan_id' => $this->subscription->plan_id,
            'usage_date' => $date,
            'quantity' => $quantity,
        ]);
    }

    private function rollup(): ?UsageRollup
    {
        return UsageRollup::where('customer_subscription_plan_change_id', $this->segment->id)->first();
    }

    public function test_it_rolls_up_usage_through_the_given_date_and_advances_the_watermark(): void
    {
        $this->recordUsage('2026-01-01', 10);
        $this->recordUsage('2026-01-02', 20);
        $this->recordUsage('2026-01-03', 5); // beyond the "through" cutoff below

        (new RollUpSubscriptionUsage)->handle($this->subscription, CarbonImmutable::parse('2026-01-02'));

        $rollup = $this->rollup();
        $this->assertNotNull($rollup);
        $this->assertSame('30.0000', $rollup->quantity);
        $this->assertSame('2026-01-02', $rollup->rolled_up_through->toDateString());
    }

    public function test_rerunning_with_the_same_through_date_adds_nothing(): void
    {
        $this->recordUsage('2026-01-01', 10);
        $this->recordUsage('2026-01-02', 20);

        $roller = new RollUpSubscriptionUsage;
        $roller->handle($this->subscription, CarbonImmutable::parse('2026-01-02'));
        $roller->handle($this->subscription, CarbonImmutable::parse('2026-01-02'));
        $roller->handle($this->subscription, CarbonImmutable::parse('2026-01-02'));

        $this->assertSame('30.0000', $this->rollup()->quantity);
    }

    public function test_a_later_run_only_adds_the_new_days(): void
    {
        $this->recordUsage('2026-01-01', 10);
        $this->recordUsage('2026-01-02', 20);
        $this->recordUsage('2026-01-03', 5);

        $roller = new RollUpSubscriptionUsage;
        $roller->handle($this->subscription, CarbonImmutable::parse('2026-01-02'));
        $this->assertSame('30.0000', $this->rollup()->quantity);

        $roller->handle($this->subscription, CarbonImmutable::parse('2026-01-03'));
        $this->assertSame('35.0000', $this->rollup()->quantity);
        $this->assertSame('2026-01-03', $this->rollup()->rolled_up_through->toDateString());
    }

    public function test_it_never_rolls_up_past_the_current_billing_period_even_if_through_is_later(): void
    {
        $this->recordUsage('2026-01-31', 8);

        // Simulate a "through yesterday" call from a job running well after
        // this period should have already been invoiced.
        (new RollUpSubscriptionUsage)->handle($this->subscription, CarbonImmutable::parse('2026-03-15'));

        $this->assertSame('2026-01-31', $this->rollup()->rolled_up_through->toDateString());
    }
}
