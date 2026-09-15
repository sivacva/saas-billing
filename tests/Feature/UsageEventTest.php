<?php

namespace Tests\Feature;

use App\Enums\CustomerSubscriptionStatus;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\User;
use App\Models\UsageEvent;
use App\Models\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsageEventTest extends TestCase
{
    use RefreshDatabase;

    private CustomerSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = Merchant::create(['name' => 'Acme Inc', 'slug' => 'acme']);
        $customer = Customer::create(['merchant_id' => $merchant->id, 'name' => 'Bob', 'email' => 'bob@example.com']);
        $plan = Plan::create([
            'merchant_id' => $merchant->id,
            'name' => 'Pro',
            'slug' => 'pro',
            'price' => 29.00,
            'included_usage' => 1000,
            'overage_rate' => 0.01,
        ]);

        $this->subscription = CustomerSubscription::create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => CustomerSubscriptionStatus::Active,
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
            'started_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_it_records_a_usage_event_and_increments_the_daily_aggregate(): void
    {
        $response = $this->postJson('/api/usage-events', [
            'customer_subscription_id' => $this->subscription->id,
            'idempotency_key' => 'evt-1',
            'quantity' => 10,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.duplicate', false);

        $this->assertSame(1, UsageEvent::count());
        $this->assertSame('10.0000', UsageRecord::first()->quantity);
    }

    public function test_retrying_the_same_idempotency_key_does_not_double_count(): void
    {
        $payload = [
            'customer_subscription_id' => $this->subscription->id,
            'idempotency_key' => 'evt-retry',
            'quantity' => 10,
        ];

        $first = $this->postJson('/api/usage-events', $payload);
        $first->assertCreated();
        $first->assertJsonPath('data.duplicate', false);

        // Simulates a client retry after e.g. a dropped response.
        $second = $this->postJson('/api/usage-events', $payload);
        $second->assertOk();
        $second->assertJsonPath('data.duplicate', true);
        $second->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, UsageEvent::count());
        $this->assertSame('10.0000', UsageRecord::first()->quantity);
    }

    public function test_distinct_events_on_the_same_day_accumulate(): void
    {
        $this->postJson('/api/usage-events', [
            'customer_subscription_id' => $this->subscription->id,
            'idempotency_key' => 'evt-a',
            'quantity' => 10,
        ])->assertCreated();

        $this->postJson('/api/usage-events', [
            'customer_subscription_id' => $this->subscription->id,
            'idempotency_key' => 'evt-b',
            'quantity' => 5,
        ])->assertCreated();

        $this->assertSame(2, UsageEvent::count());
        $this->assertSame('15.0000', UsageRecord::first()->quantity);
    }

    public function test_it_rejects_a_missing_idempotency_key(): void
    {
        $this->postJson('/api/usage-events', [
            'customer_subscription_id' => $this->subscription->id,
            'quantity' => 10,
        ])->assertJsonValidationErrors('idempotency_key');
    }

    public function test_it_rejects_an_unknown_subscription(): void
    {
        $this->postJson('/api/usage-events', [
            'customer_subscription_id' => 999999,
            'idempotency_key' => 'evt-1',
            'quantity' => 10,
        ])->assertJsonValidationErrors('customer_subscription_id');
    }
}
