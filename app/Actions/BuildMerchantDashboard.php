<?php

namespace App\Actions;

use App\DTOs\ChurnRiskCustomer;
use App\DTOs\MerchantDashboardData;
use App\DTOs\TopCustomerUsage;
use App\Enums\CustomerSubscriptionStatus;
use App\Models\Merchant;
use App\Services\DashboardCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gathers everything the merchant dashboard shows, from summary tables only
 * - usage_rollups for this cycle's usage-to-date, invoice_lines for the
 * previous cycle's finalized usage - never usage_records directly, so this
 * stays fast regardless of how large that table gets.
 *
 * Exactly four queries run regardless of how many subscriptions the
 * merchant has: the active subscriptions themselves, one aggregate over
 * usage_rollups, one over the currently-open plan-change segments, and one
 * over invoice_lines for each subscription's most recent invoice. All the
 * per-subscription projection math happens in PHP via DashboardCalculator
 * once those four results are in hand - no N+1.
 */
class BuildMerchantDashboard
{
    private const int TOP_CUSTOMERS_LIMIT = 5;

    public function __construct(private DashboardCalculator $calculator) {}

    public function handle(Merchant $merchant): MerchantDashboardData
    {
        $subscriptions = DB::table('customer_subscriptions as cs')
            ->join('customers as c', 'c.id', '=', 'cs.customer_id')
            ->where('cs.merchant_id', $merchant->id)
            ->where('cs.status', CustomerSubscriptionStatus::Active->value)
            ->select('cs.id', 'cs.customer_id', 'cs.current_period_start', 'cs.current_period_end', 'c.name', 'c.email')
            ->get()
            ->keyBy('id');

        if ($subscriptions->isEmpty()) {
            return new MerchantDashboardData('0.00', '0.00', [], '0.00', [], CarbonImmutable::now());
        }

        $usageSoFar = $this->currentPeriodUsage($merchant);
        $currentSegments = $this->currentSegments($merchant);
        $previousUsage = $this->previousInvoicedUsage($merchant);

        $topCustomers = [];
        $churnRisks = [];
        $totalProjectedOverageRevenue = '0';
        $totalCurrentCycleUsage = '0';
        $totalCurrentCycleAllowance = '0';

        foreach ($subscriptions as $subscriptionId => $subscription) {
            $usage = $usageSoFar->get($subscriptionId);
            $segment = $currentSegments->get($subscriptionId);

            if ($usage === null || $segment === null) {
                // No usage rolled up yet this cycle, or no open segment
                // (shouldn't happen for an active subscription) - nothing to
                // project for this one.
                continue;
            }

            // SUM() returns its own natural numeric string, not one padded to
            // the column's declared scale (SQLite in particular: '600', not
            // '600.0000') - normalize here so every quantity in the response
            // has a consistent, predictable format regardless of which value
            // happened to sum to a whole number.
            $usageQuantity = bcadd($usage->quantity, '0', 4);
            $includedUsage = bcadd($segment->included_usage, '0', 4);

            $totalCurrentCycleUsage = bcadd($totalCurrentCycleUsage, $usageQuantity, 4);
            $totalCurrentCycleAllowance = bcadd($totalCurrentCycleAllowance, $includedUsage, 4);

            // Against the plan's full allowance, not a prorated slice of it -
            // "how much of your quota have you used" is what this number
            // means to a merchant, regardless of when in the cycle they're
            // looking.
            $percentOfAllowance = bccomp($includedUsage, '0', 4) > 0
                ? Money::round(bcmul(bcdiv($usageQuantity, $includedUsage, 6), '100', 6), 1)
                : '0.0';

            $topCustomers[] = new TopCustomerUsage(
                customerId: $subscription->customer_id,
                customerName: $subscription->name,
                customerEmail: $subscription->email,
                usageQuantity: $usageQuantity,
                percentOfAllowance: $percentOfAllowance,
            );

            $periodDays = $this->inclusiveDays(
                CarbonImmutable::parse($subscription->current_period_start),
                CarbonImmutable::parse($subscription->current_period_end),
            );
            $elapsedDays = $usage->rolled_up_through
                ? $this->inclusiveDays(CarbonImmutable::parse($subscription->current_period_start), CarbonImmutable::parse($usage->rolled_up_through))
                : 0;

            $projectedUsage = $this->calculator->projectUsage($periodDays, $elapsedDays, $usageQuantity);

            $overage = $this->calculator->projectedOverage($projectedUsage, $segment->included_usage, $segment->overage_rate);
            $totalProjectedOverageRevenue = bcadd($totalProjectedOverageRevenue, $overage['overage_revenue'], 2);

            $previous = $previousUsage->get($subscriptionId);

            if ($previous !== null) {
                $previousUsageQuantity = bcadd($previous->usage_quantity, '0', 4);
                $ratio = $this->calculator->usageChangeRatio($projectedUsage, $previousUsageQuantity);

                if ($this->calculator->isChurnRisk($ratio)) {
                    $churnRisks[] = new ChurnRiskCustomer(
                        customerId: $subscription->customer_id,
                        customerName: $subscription->name,
                        customerEmail: $subscription->email,
                        projectedUsageThisCycle: $projectedUsage,
                        previousCycleUsage: $previousUsageQuantity,
                        changeRatio: $ratio,
                    );
                }
            }
        }

        usort($topCustomers, fn ($a, $b) => bccomp($b->usageQuantity, $a->usageQuantity, 6));
        usort($churnRisks, fn ($a, $b) => bccomp($a->changeRatio, $b->changeRatio, 6));

        return new MerchantDashboardData(
            currentCycleUsage: $totalCurrentCycleUsage,
            currentCycleAllowance: $totalCurrentCycleAllowance,
            topCustomersByUsage: array_slice($topCustomers, 0, self::TOP_CUSTOMERS_LIMIT),
            projectedOverageRevenueThisCycle: $totalProjectedOverageRevenue,
            churnRiskCustomers: $churnRisks,
            generatedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Usage-to-date for the current cycle, summed across segments in case a
     * mid-cycle plan switch split it across more than one usage_rollups row.
     */
    private function currentPeriodUsage(Merchant $merchant)
    {
        return DB::table('usage_rollups as ur')
            ->join('customer_subscriptions as cs', function ($join) {
                $join->on('cs.id', '=', 'ur.customer_subscription_id')
                    ->on('cs.current_period_start', '=', 'ur.period_start');
            })
            ->where('cs.merchant_id', $merchant->id)
            ->where('cs.status', CustomerSubscriptionStatus::Active->value)
            ->groupBy('ur.customer_subscription_id')
            ->select(
                'ur.customer_subscription_id',
                DB::raw('SUM(ur.quantity) as quantity'),
                DB::raw('MAX(ur.rolled_up_through) as rolled_up_through'),
            )
            ->get()
            ->keyBy('customer_subscription_id');
    }

    /**
     * The currently-open plan-change segment per subscription - its rate is
     * what future usage this cycle will actually be billed at.
     */
    private function currentSegments(Merchant $merchant)
    {
        return DB::table('customer_subscription_plan_changes as pc')
            ->join('customer_subscriptions as cs', 'cs.id', '=', 'pc.customer_subscription_id')
            ->where('cs.merchant_id', $merchant->id)
            ->where('cs.status', CustomerSubscriptionStatus::Active->value)
            ->whereNull('pc.ends_at')
            ->select('pc.customer_subscription_id', 'pc.included_usage', 'pc.overage_rate')
            ->get()
            ->keyBy('customer_subscription_id');
    }

    /**
     * Total usage from each subscription's most recently invoiced period -
     * the "previous cycle" baseline for churn comparison. Reads
     * invoice_lines, the already-finalized historical record, rather than
     * re-deriving it from usage_rollups for an old period.
     */
    private function previousInvoicedUsage(Merchant $merchant)
    {
        $latestPeriodPerSubscription = DB::table('invoices')
            ->where('merchant_id', $merchant->id)
            ->groupBy('customer_subscription_id')
            ->select('customer_subscription_id', DB::raw('MAX(period_start) as period_start'));

        return DB::table('invoice_lines as il')
            ->join('invoices as inv', 'inv.id', '=', 'il.invoice_id')
            ->joinSub($latestPeriodPerSubscription, 'latest', function ($join) {
                $join->on('latest.customer_subscription_id', '=', 'inv.customer_subscription_id')
                    ->on('latest.period_start', '=', 'inv.period_start');
            })
            ->groupBy('inv.customer_subscription_id')
            ->select('inv.customer_subscription_id', DB::raw('SUM(il.usage_quantity) as usage_quantity'))
            ->get()
            ->keyBy('customer_subscription_id');
    }

    private function inclusiveDays(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $start->diffInDays($end) + 1;
    }
}
