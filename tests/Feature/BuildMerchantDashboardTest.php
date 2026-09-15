<?php

namespace Tests\Feature;

use App\Actions\BuildMerchantDashboard;
use App\Enums\CustomerSubscriptionStatus;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionPlanChange;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\UsageRollup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildMerchantDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->plan = Plan::create([
            'merchant_id' => $this->merchant->id, 'name' => 'Pro', 'slug' => 'pro',
            'price' => 100, 'included_usage' => 1000, 'overage_rate' => 0.10,
        ]);
    }

    /**
     * @return array{subscription: CustomerSubscription, segment: CustomerSubscriptionPlanChange}
     */
    private function makeSubscription(string $customerEmail, float $usageSoFar): array
    {
        $customer = Customer::create(['merchant_id' => $this->merchant->id, 'name' => ucfirst(explode('@', $customerEmail)[0]), 'email' => $customerEmail]);

        $subscription = CustomerSubscription::create([
            'merchant_id' => $this->merchant->id, 'customer_id' => $customer->id, 'plan_id' => $this->plan->id,
            'status' => CustomerSubscriptionStatus::Active,
            'current_period_start' => '2026-01-01', 'current_period_end' => '2026-01-30',
            'started_at' => '2026-01-01',
        ]);

        $segment = CustomerSubscriptionPlanChange::create([
            'customer_subscription_id' => $subscription->id, 'plan_id' => $this->plan->id,
            'price' => $this->plan->price, 'currency' => 'usd',
            'included_usage' => $this->plan->included_usage, 'overage_rate' => $this->plan->overage_rate,
            'starts_at' => '2026-01-01',
        ]);

        // 10 days elapsed into a 30-day period, via the summary table -
        // never touching usage_records.
        UsageRollup::create([
            'customer_subscription_id' => $subscription->id,
            'customer_subscription_plan_change_id' => $segment->id,
            'period_start' => '2026-01-01', 'period_end' => '2026-01-30',
            'quantity' => $usageSoFar, 'rolled_up_through' => '2026-01-10',
        ]);

        return ['subscription' => $subscription, 'segment' => $segment];
    }

    private function givePreviousInvoice(CustomerSubscription $subscription, float $previousUsage): void
    {
        $invoice = Invoice::create([
            'merchant_id' => $this->merchant->id, 'customer_id' => $subscription->customer_id,
            'customer_subscription_id' => $subscription->id,
            'period_start' => '2025-12-01', 'period_end' => '2025-12-31',
            'currency' => 'usd', 'total' => 100,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'customer_subscription_plan_change_id' => CustomerSubscriptionPlanChange::where('customer_subscription_id', $subscription->id)->first()->id,
            'description' => 'Pro', 'segment_start' => '2025-12-01', 'segment_end' => '2025-12-31',
            'price' => 100, 'included_usage' => 1000, 'overage_rate' => 0.10,
            'prorated_included_usage' => 1000, 'usage_quantity' => $previousUsage,
            'overage_quantity' => 0, 'base_amount' => 100, 'overage_amount' => 0, 'amount' => 100,
        ]);
    }

    public function test_it_ranks_top_customers_by_actual_usage_so_far(): void
    {
        // 30-day period, 10 elapsed days.
        ['subscription' => $a] = $this->makeSubscription('alice@example.com', 600);
        ['subscription' => $b] = $this->makeSubscription('bob@example.com', 100);
        ['subscription' => $c] = $this->makeSubscription('carol@example.com', 400);

        $data = app(BuildMerchantDashboard::class)->handle($this->merchant);

        $names = array_map(fn ($top) => $top->customerName, $data->topCustomersByUsage);
        $this->assertSame(['Alice', 'Carol', 'Bob'], $names);
        $this->assertSame('600.0000', $data->topCustomersByUsage[0]->usageQuantity); // normalized to a consistent scale
    }

    public function test_top_customers_list_is_capped_at_five_even_with_more_subscribers(): void
    {
        foreach (range(1, 7) as $i) {
            $this->makeSubscription("customer{$i}@example.com", 100 * $i);
        }

        $data = app(BuildMerchantDashboard::class)->handle($this->merchant);

        $this->assertCount(5, $data->topCustomersByUsage);
        // Still correctly ranked - the top 5 of the 7 by usage, highest first.
        $this->assertSame('700.0000', $data->topCustomersByUsage[0]->usageQuantity);
        $this->assertSame('300.0000', $data->topCustomersByUsage[4]->usageQuantity);
    }

    public function test_percent_of_allowance_is_usage_against_the_full_plan_allowance(): void
    {
        // included_usage is 1000 on the plan used by makeSubscription().
        $this->makeSubscription('alice@example.com', 600);

        $data = app(BuildMerchantDashboard::class)->handle($this->merchant);

        $this->assertSame('60.0', $data->topCustomersByUsage[0]->percentOfAllowance);
    }

    public function test_current_cycle_usage_and_allowance_sum_across_all_subscriptions(): void
    {
        $this->makeSubscription('alice@example.com', 600);
        $this->makeSubscription('bob@example.com', 100);

        $data = app(BuildMerchantDashboard::class)->handle($this->merchant);

        $this->assertSame('700.0000', $data->currentCycleUsage);
        $this->assertSame('2000.0000', $data->currentCycleAllowance); // 1000 included_usage x 2 subscriptions
    }

    public function test_it_projects_overage_revenue_across_all_subscriptions(): void
    {
        // projected = usageSoFar * 30/10 = usageSoFar * 3.
        $this->makeSubscription('alice@example.com', 600); // projects to 1800 -> 800 over @ 0.10 = 80.00
        $this->makeSubscription('carol@example.com', 400); // projects to 1200 -> 200 over @ 0.10 = 20.00
        $this->makeSubscription('bob@example.com', 100);   // projects to 300 -> within 1000 included, no overage

        $data = app(BuildMerchantDashboard::class)->handle($this->merchant);

        $this->assertSame('100.00', $data->projectedOverageRevenueThisCycle);
    }

    public function test_it_flags_a_customer_projected_to_land_well_below_last_cycle(): void
    {
        ['subscription' => $bob] = $this->makeSubscription('bob@example.com', 100); // projects to 300
        $this->givePreviousInvoice($bob, 1000); // 300/1000 = 0.3 -> below the 0.5 threshold

        $data = app(BuildMerchantDashboard::class)->handle($this->merchant);

        $this->assertCount(1, $data->churnRiskCustomers);
        $this->assertSame('Bob', $data->churnRiskCustomers[0]->customerName);
        $this->assertSame('0.300000', $data->churnRiskCustomers[0]->changeRatio);
    }

    public function test_it_does_not_flag_a_customer_with_no_previous_cycle_to_compare(): void
    {
        $this->makeSubscription('alice@example.com', 600); // no previous invoice at all

        $data = app(BuildMerchantDashboard::class)->handle($this->merchant);

        $this->assertCount(0, $data->churnRiskCustomers);
    }

    public function test_it_does_not_flag_a_customer_within_the_drop_threshold(): void
    {
        ['subscription' => $carol] = $this->makeSubscription('carol@example.com', 400); // projects to 1200
        $this->givePreviousInvoice($carol, 1500); // 1200/1500 = 0.8 -> not a big enough drop

        $data = app(BuildMerchantDashboard::class)->handle($this->merchant);

        $this->assertCount(0, $data->churnRiskCustomers);
    }

    public function test_an_empty_merchant_returns_an_empty_dashboard_without_erroring(): void
    {
        $data = app(BuildMerchantDashboard::class)->handle($this->merchant);

        $this->assertSame([], $data->topCustomersByUsage);
        $this->assertSame('0.00', $data->projectedOverageRevenueThisCycle);
        $this->assertSame([], $data->churnRiskCustomers);
        $this->assertSame('0.00', $data->currentCycleUsage);
        $this->assertSame('0.00', $data->currentCycleAllowance);
    }
}
