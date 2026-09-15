<?php

namespace Tests\Feature;

use App\Actions\GenerateInvoiceForSubscription;
use App\Enums\CustomerSubscriptionStatus;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionPlanChange;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateInvoiceForSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->customer = Customer::create(['merchant_id' => $this->merchant->id, 'name' => 'Bob', 'email' => 'bob@example.com']);
    }

    private function recordUsage(CustomerSubscription $subscription, string $date, float $quantity): void
    {
        UsageRecord::create([
            'merchant_id' => $subscription->merchant_id,
            'customer_id' => $subscription->customer_id,
            'customer_subscription_id' => $subscription->id,
            'plan_id' => $subscription->plan_id,
            'usage_date' => $date,
            'quantity' => $quantity,
        ]);
    }

    public function test_it_generates_an_invoice_with_base_price_and_overage_for_a_full_period(): void
    {
        $plan = Plan::create([
            'merchant_id' => $this->merchant->id, 'name' => 'Pro', 'slug' => 'pro',
            'price' => 29, 'included_usage' => 1000, 'overage_rate' => 0.01,
        ]);

        $subscription = CustomerSubscription::create([
            'merchant_id' => $this->merchant->id, 'customer_id' => $this->customer->id, 'plan_id' => $plan->id,
            'status' => CustomerSubscriptionStatus::Active,
            'current_period_start' => '2026-01-01', 'current_period_end' => '2026-01-31',
            'started_at' => '2026-01-01',
        ]);

        CustomerSubscriptionPlanChange::create([
            'customer_subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'price' => $plan->price, 'currency' => 'usd',
            'included_usage' => $plan->included_usage, 'overage_rate' => $plan->overage_rate,
            'starts_at' => '2026-01-01',
        ]);

        $this->recordUsage($subscription, '2026-01-15', 1200);

        ['invoice' => $invoice, 'duplicate' => $duplicate] = app(GenerateInvoiceForSubscription::class)->handle($subscription);

        $this->assertFalse($duplicate);
        $this->assertSame('31.00', $invoice->total);
        $this->assertCount(1, $invoice->lines);
        $this->assertSame('29.00', $invoice->lines[0]->base_amount);
        $this->assertSame('2.00', $invoice->lines[0]->overage_amount);

        $subscription->refresh();
        $this->assertSame('2026-02-01', $subscription->current_period_start->toDateString());
        $this->assertSame('2026-02-28', $subscription->current_period_end->toDateString());
    }

    public function test_it_prorates_the_first_period_when_the_subscription_joined_mid_cycle(): void
    {
        $plan = Plan::create([
            'merchant_id' => $this->merchant->id, 'name' => 'Pro', 'slug' => 'pro',
            'price' => 62, 'included_usage' => 310, 'overage_rate' => 0.50,
        ]);

        // Billing is calendar-aligned (Jan 1 - Jan 31); the customer joined on the 11th.
        $subscription = CustomerSubscription::create([
            'merchant_id' => $this->merchant->id, 'customer_id' => $this->customer->id, 'plan_id' => $plan->id,
            'status' => CustomerSubscriptionStatus::Active,
            'current_period_start' => '2026-01-01', 'current_period_end' => '2026-01-31',
            'started_at' => '2026-01-11',
        ]);

        CustomerSubscriptionPlanChange::create([
            'customer_subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'price' => $plan->price, 'currency' => 'usd',
            'included_usage' => $plan->included_usage, 'overage_rate' => $plan->overage_rate,
            'starts_at' => '2026-01-11',
        ]);

        $this->recordUsage($subscription, '2026-01-20', 250);

        ['invoice' => $invoice] = app(GenerateInvoiceForSubscription::class)->handle($subscription);

        // 21-of-31-day segment: base 62 * 21/31 = 42.00, included prorated to 210, overage 40 @ $0.50 = 20.00.
        $this->assertSame('62.00', $invoice->total);
        $this->assertSame('42.00', $invoice->lines[0]->base_amount);
        $this->assertSame('20.00', $invoice->lines[0]->overage_amount);
        $this->assertSame('2026-01-11', $invoice->lines[0]->segment_start->toDateString());
    }

    public function test_it_splits_and_prorates_billing_across_a_mid_cycle_plan_switch(): void
    {
        $starter = Plan::create([
            'merchant_id' => $this->merchant->id, 'name' => 'Starter', 'slug' => 'starter',
            'price' => 31, 'included_usage' => 155, 'overage_rate' => 1.00,
        ]);
        $pro = Plan::create([
            'merchant_id' => $this->merchant->id, 'name' => 'Pro', 'slug' => 'pro',
            'price' => 62, 'included_usage' => 310, 'overage_rate' => 0.50,
        ]);

        $subscription = CustomerSubscription::create([
            'merchant_id' => $this->merchant->id, 'customer_id' => $this->customer->id, 'plan_id' => $starter->id,
            'status' => CustomerSubscriptionStatus::Active,
            'current_period_start' => '2026-01-01', 'current_period_end' => '2026-01-31',
            'started_at' => '2026-01-01',
        ]);

        $oldSegment = CustomerSubscriptionPlanChange::create([
            'customer_subscription_id' => $subscription->id, 'plan_id' => $starter->id,
            'price' => $starter->price, 'currency' => 'usd',
            'included_usage' => $starter->included_usage, 'overage_rate' => $starter->overage_rate,
            'starts_at' => '2026-01-01', 'ends_at' => '2026-01-10',
        ]);
        CustomerSubscriptionPlanChange::create([
            'customer_subscription_id' => $subscription->id, 'plan_id' => $pro->id,
            'price' => $pro->price, 'currency' => 'usd',
            'included_usage' => $pro->included_usage, 'overage_rate' => $pro->overage_rate,
            'starts_at' => '2026-01-11',
        ]);
        // The switch itself would update the subscription's "current plan" pointer.
        $subscription->update(['plan_id' => $pro->id]);

        $this->recordUsage($subscription, '2026-01-05', 70);
        $this->recordUsage($subscription, '2026-01-20', 250);

        ['invoice' => $invoice] = app(GenerateInvoiceForSubscription::class)->handle($subscription);

        $this->assertCount(2, $invoice->lines);
        $starterLine = $invoice->lines->firstWhere('customer_subscription_plan_change_id', $oldSegment->id);
        $proLine = $invoice->lines->firstWhere('customer_subscription_plan_change_id', '!=', $oldSegment->id);

        $this->assertSame('30.00', $starterLine->amount); // 10.00 base + 20.00 overage
        $this->assertSame('62.00', $proLine->amount); // 42.00 base + 20.00 overage
        $this->assertSame('92.00', $invoice->total);

        // Next period advances from the plan active at period end (Pro = monthly).
        $subscription->refresh();
        $this->assertSame('2026-02-01', $subscription->current_period_start->toDateString());
    }

    public function test_rerunning_for_an_already_invoiced_period_does_not_duplicate(): void
    {
        $plan = Plan::create([
            'merchant_id' => $this->merchant->id, 'name' => 'Pro', 'slug' => 'pro',
            'price' => 29, 'included_usage' => 1000, 'overage_rate' => 0.01,
        ]);

        $subscription = CustomerSubscription::create([
            'merchant_id' => $this->merchant->id, 'customer_id' => $this->customer->id, 'plan_id' => $plan->id,
            'status' => CustomerSubscriptionStatus::Active,
            'current_period_start' => '2026-01-01', 'current_period_end' => '2026-01-31',
            'started_at' => '2026-01-01',
        ]);

        CustomerSubscriptionPlanChange::create([
            'customer_subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'price' => $plan->price, 'currency' => 'usd',
            'included_usage' => $plan->included_usage, 'overage_rate' => $plan->overage_rate,
            'starts_at' => '2026-01-01',
        ]);

        $action = app(GenerateInvoiceForSubscription::class);

        $first = $action->handle($subscription);
        $this->assertFalse($first['duplicate']);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InvoiceLine::count());

        // Simulate a rerun that still sees the original period - e.g. a queue
        // retry of a job attempt that had actually already committed. Force
        // the in-memory subscription back to the invoiced period rather than
        // relying on a fresh fetch, since a fresh fetch would correctly (and
        // less interestingly) see the already-advanced next period.
        $subscription->current_period_start = '2026-01-01';
        $subscription->current_period_end = '2026-01-31';

        $second = $action->handle($subscription);

        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['invoice']->id, $second['invoice']->id);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InvoiceLine::count());
    }
}
