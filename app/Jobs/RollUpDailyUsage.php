<?php

namespace App\Jobs;

use App\Actions\RollUpSubscriptionUsage;
use App\Enums\CustomerSubscriptionStatus;
use App\Models\CustomerSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rolls up yesterday's usage for every active subscription.
 *
 * "Batches" here means chunking through subscriptions with chunkById, not
 * loading usage_records itself - each subscription's rollup is one small,
 * indexed SUM() query (see RollUpSubscriptionUsage), so this job's memory
 * footprint stays flat regardless of how many rows usage_records holds.
 *
 * Safe to rerun or run concurrently: RollUpSubscriptionUsage's watermark
 * means a subscription already caught up through yesterday is a no-op.
 */
class RollUpDailyUsage implements ShouldQueue
{
    use Queueable;

    public function handle(RollUpSubscriptionUsage $roller): void
    {
        $through = CarbonImmutable::now()->subDay();

        CustomerSubscription::query()
            ->where('status', CustomerSubscriptionStatus::Active)
            ->chunkById(200, function ($subscriptions) use ($roller, $through) {
                foreach ($subscriptions as $subscription) {
                    $roller->handle($subscription, $through);
                }
            });
    }
}
