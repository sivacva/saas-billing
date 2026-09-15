<?php

namespace App\Actions;

use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionPlanChange;
use App\Models\UsageRecord;
use App\Models\UsageRollup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rolls a subscription's usage_records into per-(plan segment, billing
 * period) running totals, advancing a watermark so re-running this only
 * ever adds usage for days not yet folded in.
 *
 * This is what keeps it cheap against a huge usage_records table: it never
 * scans that table wholesale. Each call issues one narrow, indexed
 * SUM(quantity) per plan segment, filtered to that one subscription and a
 * date range bounded by the watermark - a slice of the table, not the
 * table. The daily job fans this out across subscriptions in chunks so no
 * single run has to hold more than a page of subscriptions in memory.
 */
class RollUpSubscriptionUsage
{
    public function handle(CustomerSubscription $subscription, CarbonImmutable $through): void
    {
        $periodStart = CarbonImmutable::parse($subscription->current_period_start);
        $periodEnd = CarbonImmutable::parse($subscription->current_period_end);
        $through = $through->min($periodEnd);

        if ($through->lt($periodStart)) {
            return;
        }

        $segments = $subscription->planChanges()
            ->where('starts_at', '<=', $periodEnd)
            ->where(function ($query) use ($periodStart) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $periodStart);
            })
            ->get();

        foreach ($segments as $segment) {
            $this->rollUpSegment($subscription, $segment, $periodStart, $periodEnd, $through);
        }
    }

    private function rollUpSegment(
        CustomerSubscription $subscription,
        CustomerSubscriptionPlanChange $segment,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $through,
    ): void {
        $segmentStart = $periodStart->max(CarbonImmutable::parse($segment->starts_at));
        $segmentEnd = $segment->ends_at
            ? $periodEnd->min(CarbonImmutable::parse($segment->ends_at))
            : $periodEnd;
        $segmentEnd = $segmentEnd->min($through);

        DB::transaction(function () use ($subscription, $segment, $periodStart, $periodEnd, $segmentStart, $segmentEnd) {
            // insertOrIgnore, not firstOrCreate: two overlapping runs racing to
            // create the same row must not throw - see LogUsageEvent for why
            // an unexpected exception mid-transaction is the thing to avoid.
            DB::table('usage_rollups')->insertOrIgnore([[
                'customer_subscription_id' => $subscription->id,
                'customer_subscription_plan_change_id' => $segment->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'quantity' => 0,
                'rolled_up_through' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);

            /** @var UsageRollup $rollup */
            $rollup = UsageRollup::where('customer_subscription_plan_change_id', $segment->id)
                ->where('period_start', $periodStart->toDateString())
                ->lockForUpdate()
                ->first();

            $rangeStart = $rollup->rolled_up_through
                ? CarbonImmutable::parse($rollup->rolled_up_through)->addDay()->max($segmentStart)
                : $segmentStart;

            if ($rangeStart->gt($segmentEnd)) {
                // Already caught up through $segmentEnd - a rerun (or a second
                // job that raced us for the lock and ran second) has nothing
                // left to add. This, not a "has this already run?" check, is
                // what makes reruns safe.
                return;
            }

            $delta = UsageRecord::where('customer_subscription_id', $subscription->id)
                ->whereBetween('usage_date', [$rangeStart->toDateString(), $segmentEnd->toDateString()])
                ->sum('quantity');

            $rollup->update([
                'quantity' => bcadd((string) $rollup->quantity, (string) $delta, 4),
                'rolled_up_through' => $segmentEnd->toDateString(),
            ]);
        });
    }
}
