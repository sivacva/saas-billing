<?php

namespace App\Jobs;

use App\Actions\GenerateInvoiceForSubscription;
use App\Enums\CustomerSubscriptionStatus;
use App\Models\CustomerSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Invoices every active subscription whose billing period has ended.
 *
 * Safe to rerun or run concurrently: GenerateInvoiceForSubscription's
 * uniqueness guard on (customer_subscription_id, period_start) means a
 * subscription already invoiced for its just-closed period is a no-op, not
 * a duplicate invoice.
 */
class GenerateInvoicesAtCycleEnd implements ShouldQueue
{
    use Queueable;

    public function handle(GenerateInvoiceForSubscription $generator): void
    {
        $today = CarbonImmutable::now()->toDateString();

        CustomerSubscription::query()
            ->where('status', CustomerSubscriptionStatus::Active)
            ->where('current_period_end', '<', $today)
            ->chunkById(200, function ($subscriptions) use ($generator) {
                foreach ($subscriptions as $subscription) {
                    $generator->handle($subscription);
                }
            });
    }
}
