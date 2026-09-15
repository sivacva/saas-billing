<?php

namespace Tests\Feature;

use App\Enums\CustomerSubscriptionStatus;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsageEventsRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private CustomerSubscription $subscription;

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
            'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->endOfMonth(),
            'started_at' => now(),
        ]);
    }

    private function postUsageEvent(string $idempotencyKey): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/usage-events', [
            'customer_subscription_id' => $this->subscription->id,
            'idempotency_key' => $idempotencyKey,
            'quantity' => 1,
        ]);
    }

    public function test_requests_within_the_configured_limit_succeed(): void
    {
        config(['billing.usage_events.rate_limit.max_attempts' => 3]);
        Sanctum::actingAs(User::factory()->create());

        for ($i = 1; $i <= 3; $i++) {
            $this->postUsageEvent("evt-{$i}")->assertCreated();
        }
    }

    public function test_a_request_beyond_the_configured_limit_is_throttled(): void
    {
        config(['billing.usage_events.rate_limit.max_attempts' => 3]);
        Sanctum::actingAs(User::factory()->create());

        for ($i = 1; $i <= 3; $i++) {
            $this->postUsageEvent("evt-{$i}")->assertCreated();
        }

        $this->postUsageEvent('evt-4')->assertStatus(429);
    }

    public function test_the_limit_is_driven_by_config_not_a_hardcoded_number(): void
    {
        config(['billing.usage_events.rate_limit.max_attempts' => 1]);
        Sanctum::actingAs(User::factory()->create());

        $this->postUsageEvent('evt-1')->assertCreated();
        $this->postUsageEvent('evt-2')->assertStatus(429);
    }

    private function actingAsTokenOwnedBy(User $user): void
    {
        // Sanctum::actingAs() stubs a Mockery mock for the token with no
        // real `id`, which would make a token-vs-IP test pass for the wrong
        // reason (every mocked "token" falling through to the same
        // IP-based key). Attaching a real, persisted token gives it a
        // genuine distinct id to key on, the same way production requests do.
        $user->withAccessToken($user->createToken('integration')->accessToken);
        Auth::guard('sanctum')->setUser($user);
    }

    public function test_the_limit_is_keyed_by_api_token_not_shared_across_tokens_on_the_same_ip(): void
    {
        config(['billing.usage_events.rate_limit.max_attempts' => 1]);

        $this->actingAsTokenOwnedBy(User::factory()->create());
        $this->postUsageEvent('token-a-evt-1')->assertCreated();
        $this->postUsageEvent('token-a-evt-2')->assertStatus(429); // token A is now exhausted

        // A second, different token - same test client, same "IP" - must get
        // its own independent bucket. If this were keyed by IP instead of
        // the token, it would already be throttled here too.
        $this->actingAsTokenOwnedBy(User::factory()->create());
        $this->postUsageEvent('token-b-evt-1')->assertCreated();
    }
}
