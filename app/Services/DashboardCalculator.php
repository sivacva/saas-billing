<?php

namespace App\Services;

use App\Support\Money;

/**
 * Pure projection math for the merchant dashboard: no Eloquent, no DB - just
 * bcmath over plain inputs, same reasoning as InvoiceCalculator. The data
 * gathering (which subscriptions, their rollup totals, their previous
 * invoice) lives in BuildMerchantDashboard; this class only ever sees
 * numbers it's handed.
 *
 * "Projected" here means a simple linear run-rate extrapolation: whatever
 * pace usage has kept for the days elapsed so far this period is assumed
 * to continue for the rest of it. It is deliberately not the precise,
 * segment-aware proration InvoiceCalculator does for an actual invoice -
 * this is a forward-looking estimate for a dashboard, not a billing
 * figure, and it's allowed to be approximate in a way a real invoice
 * cannot be.
 */
final class DashboardCalculator
{
    private const int SCALE = 6;

    /**
     * The default line for "usage dropped a lot": projected to land at less
     * than half of what the previous period actually used.
     */
    private const string DEFAULT_CHURN_DROP_THRESHOLD = '0.5';

    /**
     * Extrapolate usage-so-far to a full-period estimate: usageSoFar *
     * (periodDays / elapsedDays). Returns '0' if there's no elapsed time to
     * extrapolate from (nothing rolled up yet this period).
     */
    public function projectUsage(int $periodDays, int $elapsedDays, string $usageSoFar): string
    {
        if ($elapsedDays <= 0) {
            return '0';
        }

        return bcdiv(bcmul($usageSoFar, (string) $periodDays, self::SCALE), (string) $elapsedDays, self::SCALE);
    }

    /**
     * @return array{overage_quantity: string, overage_revenue: string}
     */
    public function projectedOverage(string $projectedUsage, string $includedUsage, string $overageRate): array
    {
        $overageQuantity = bccomp($projectedUsage, $includedUsage, self::SCALE) > 0
            ? bcsub($projectedUsage, $includedUsage, self::SCALE)
            : bcmul('0', '1', self::SCALE);

        $overageRevenue = Money::round(bcmul($overageQuantity, $overageRate, self::SCALE));

        return ['overage_quantity' => $overageQuantity, 'overage_revenue' => $overageRevenue];
    }

    /**
     * projectedUsage as a fraction of previousUsage - e.g. '0.35' means this
     * cycle is projected to land at 35% of last cycle's total. Null if there
     * was no previous usage to compare against (nothing to divide by, and
     * "dropped a lot from zero" isn't a meaningful signal).
     */
    public function usageChangeRatio(string $projectedUsage, string $previousUsage): ?string
    {
        if (bccomp($previousUsage, '0', self::SCALE) <= 0) {
            return null;
        }

        return bcdiv($projectedUsage, $previousUsage, self::SCALE);
    }

    public function isChurnRisk(?string $changeRatio, string $dropThreshold = self::DEFAULT_CHURN_DROP_THRESHOLD): bool
    {
        if ($changeRatio === null) {
            return false;
        }

        return bccomp($changeRatio, $dropThreshold, self::SCALE) < 0;
    }
}
