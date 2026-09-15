<?php

namespace Tests\Feature;

use App\Enums\CustomerSubscriptionStatus;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionPlanChange;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\User;
use App\Models\UsageRollup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantDashboardEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_dashboard_payload_for_an_authenticated_request(): void
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
            'quantity' => 1600, 'rolled_up_through' => '2026-01-10',
        ]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson("/api/merchants/{$merchant->id}/dashboard");

        $response->assertOk();
        $response->assertJsonPath('data.top_customers_by_usage.0.name', 'Alice');
        $response->assertJsonPath('data.top_customers_by_usage.0.usage_quantity', '1600.0000');
        // 1600 of 1000 included = 160%.
        $response->assertJsonPath('data.top_customers_by_usage.0.percent_of_allowance', '160.0');
        $response->assertJsonPath('data.current_cycle_usage', '1600.0000');
        $response->assertJsonPath('data.current_cycle_allowance', '1000.0000');
        // projected = 1600 * 30/10 = 4800; overage = 3800 @ 0.10 = 380.00
        $response->assertJsonPath('data.projected_overage_revenue_this_cycle', '380.00');
        $response->assertJsonStructure(['data' => [
            'generated_at', 'current_cycle_usage', 'current_cycle_allowance',
            'top_customers_by_usage', 'projected_overage_revenue_this_cycle', 'churn_risk_customers',
        ]]);
    }

    public function test_it_requires_authentication(): void
    {
        $merchant = Merchant::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->getJson("/api/merchants/{$merchant->id}/dashboard")->assertUnauthorized();
    }
}
