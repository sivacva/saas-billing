<?php

namespace App\Actions;

use App\DTOs\InvoiceSegmentInput;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionPlanChange;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\UsageRollup;
use App\Services\InvoiceCalculator;
use App\Services\PlanCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Closes out a subscription's just-ended billing period: rolls up any
 * usage not yet folded in, prices it (base + overage, prorated per plan
 * segment - see InvoiceCalculator for that math), writes one invoice with
 * one line per segment, and advances the subscription to its next period.
 *
 * Idempotent: the whole thing is one transaction guarded by the unique
 * index on (customer_subscription_id, period_start). A rerun for a period
 * that's already invoiced does nothing and returns the existing invoice -
 * no lines are re-inserted, no double-advance of the period.
 */
class GenerateInvoiceForSubscription
{
    public function __construct(
        private RollUpSubscriptionUsage $roller,
        private InvoiceCalculator $calculator,
        private PlanCache $plans,
    ) {}

    /**
     * @return array{invoice: Invoice, duplicate: bool}
     */
    public function handle(CustomerSubscription $subscription): array
    {
        $periodStart = CarbonImmutable::parse($subscription->current_period_start);
        $periodEnd = CarbonImmutable::parse($subscription->current_period_end);

        // Safe to do outside (and before) the transaction below: rolling up
        // usage is idempotent on its own and has no bearing on whether this
        // period turns out to already be invoiced.
        $this->roller->handle($subscription, $periodEnd);

        $segments = $subscription->planChanges()
            ->where('starts_at', '<=', $periodEnd)
            ->where(function ($query) use ($periodStart) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $periodStart);
            })
            ->orderBy('starts_at')
            ->get();

        return DB::transaction(function () use ($subscription, $periodStart, $periodEnd, $segments) {
            $lineInputs = $segments->map(fn ($segment) => $this->buildSegmentInput($subscription, $segment, $periodStart, $periodEnd));

            $result = $this->calculator->calculate($periodStart, $periodEnd, $lineInputs->all());

            $inserted = DB::table('invoices')->insertOrIgnore([[
                'merchant_id' => $subscription->merchant_id,
                'customer_id' => $subscription->customer_id,
                'customer_subscription_id' => $subscription->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'currency' => $segments->first()?->currency ?? 'usd',
                'total' => $result->total,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);

            /** @var Invoice $invoice */
            $invoice = Invoice::where('customer_subscription_id', $subscription->id)
                ->where('period_start', $periodStart->toDateString())
                ->firstOrFail();

            if ($inserted === 0) {
                // Already invoiced by an earlier run - don't write lines
                // again or advance the period a second time.
                return ['invoice' => $invoice, 'duplicate' => true];
            }

            foreach ($result->lines as $index => $line) {
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'customer_subscription_plan_change_id' => $line->planChangeId,
                    'description' => $line->description,
                    'segment_start' => $line->segmentStart->toDateString(),
                    'segment_end' => $line->segmentEnd->toDateString(),
                    'price' => $line->price,
                    'included_usage' => $line->includedUsage,
                    'overage_rate' => $line->overageRate,
                    'prorated_included_usage' => $line->proratedIncludedUsage,
                    'usage_quantity' => $line->usageQuantity,
                    'overage_quantity' => $line->overageQuantity,
                    'base_amount' => $line->baseAmount,
                    'overage_amount' => $line->overageAmount,
                    'amount' => $line->amount,
                ]);
            }

            $this->advanceToNextPeriod($subscription, $periodEnd);

            return ['invoice' => $invoice, 'duplicate' => false];
        });
    }

    private function buildSegmentInput(
        CustomerSubscription $subscription,
        CustomerSubscriptionPlanChange $segment,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): InvoiceSegmentInput {
        $segmentStart = $periodStart->max(CarbonImmutable::parse($segment->starts_at));
        $segmentEnd = $segment->ends_at
            ? $periodEnd->min(CarbonImmutable::parse($segment->ends_at))
            : $periodEnd;

        $rollup = UsageRollup::where('customer_subscription_plan_change_id', $segment->id)
            ->where('period_start', $periodStart->toDateString())
            ->first();

        return new InvoiceSegmentInput(
            planChangeId: $segment->id,
            description: sprintf(
                '%s (%s - %s)',
                $this->plans->find($segment->plan_id)?->name ?? 'Plan',
                $segmentStart->toDateString(),
                $segmentEnd->toDateString(),
            ),
            segmentStart: $segmentStart,
            segmentEnd: $segmentEnd,
            price: (string) $segment->price,
            includedUsage: (string) $segment->included_usage,
            overageRate: (string) $segment->overage_rate,
            usageQuantity: (string) ($rollup?->quantity ?? '0'),
        );
    }

    private function advanceToNextPeriod(CustomerSubscription $subscription, CarbonImmutable $periodEnd): void
    {
        $nextStart = $periodEnd->addDay();

        $nextEnd = $this->plans->find($subscription->plan_id)?->billing_interval === 'year'
            ? $nextStart->addYearNoOverflow()->subDay()
            : $nextStart->addMonthNoOverflow()->subDay();

        $subscription->update([
            'current_period_start' => $nextStart->toDateString(),
            'current_period_end' => $nextEnd->toDateString(),
        ]);
    }
}
