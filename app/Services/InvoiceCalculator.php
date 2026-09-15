<?php

namespace App\Services;

use App\DTOs\InvoiceCalculationResult;
use App\DTOs\InvoiceLineResult;
use App\DTOs\InvoiceSegmentInput;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Pure proration + overage math for one billing period. No Eloquent, no DB,
 * no queue - just bcmath arithmetic over plain inputs, so this can be unit
 * tested with hand-picked numbers and no database.
 *
 * The key idea: "joined mid-cycle" and "switched plans mid-cycle" are the
 * same calculation, not two special cases. A billing period is covered by
 * one or more plan-change segments, each contributing base_price *
 * (its days in the period / total days in the period) plus overage on
 * whatever usage exceeded its own prorated allowance. A normal period with
 * no switch just happens to have exactly one segment whose overlap is the
 * whole period (day fraction = 1, no proration). A first partial period or
 * a mid-cycle switch is the identical formula with a shorter overlap.
 *
 * included_usage is prorated by the same day fraction as the base price.
 * Otherwise a customer who switches plans partway through a period would
 * get the *full* included-usage allowance from both plans in a single
 * period - effectively double the free quota they'd get by staying put.
 */
final class InvoiceCalculator
{
    private const int CALC_SCALE = 6;

    private const int MONEY_SCALE = 2;

    /**
     * @param InvoiceSegmentInput[] $segments Must all overlap [$periodStart, $periodEnd];
     *                                          the caller is responsible for only passing segments that do.
     */
    public function calculate(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array $segments): InvoiceCalculationResult
    {
        $periodDays = $this->inclusiveDays($periodStart, $periodEnd);

        $lines = [];
        $total = '0';

        foreach ($segments as $segment) {
            $line = $this->calculateLine($segment, $periodDays);
            $lines[] = $line;
            $total = bcadd($total, $line->amount, self::MONEY_SCALE);
        }

        return new InvoiceCalculationResult($lines, $total);
    }

    private function calculateLine(InvoiceSegmentInput $segment, int $periodDays): InvoiceLineResult
    {
        $segmentDays = $this->inclusiveDays($segment->segmentStart, $segment->segmentEnd);

        $proratedIncludedUsage = $this->prorate($segment->includedUsage, $segmentDays, $periodDays);
        $proratedPrice = $this->prorate($segment->price, $segmentDays, $periodDays);

        $overageQuantity = bccomp($segment->usageQuantity, $proratedIncludedUsage, self::CALC_SCALE) > 0
            ? bcsub($segment->usageQuantity, $proratedIncludedUsage, self::CALC_SCALE)
            : bcmul('0', '1', self::CALC_SCALE);

        $baseAmount = Money::round($proratedPrice, self::MONEY_SCALE);
        $overageAmount = Money::round(bcmul($overageQuantity, $segment->overageRate, self::CALC_SCALE), self::MONEY_SCALE);
        $amount = bcadd($baseAmount, $overageAmount, self::MONEY_SCALE);

        return new InvoiceLineResult(
            planChangeId: $segment->planChangeId,
            description: $segment->description,
            segmentStart: $segment->segmentStart,
            segmentEnd: $segment->segmentEnd,
            price: $segment->price,
            includedUsage: $segment->includedUsage,
            overageRate: $segment->overageRate,
            proratedIncludedUsage: $proratedIncludedUsage,
            usageQuantity: $segment->usageQuantity,
            overageQuantity: $overageQuantity,
            baseAmount: $baseAmount,
            overageAmount: $overageAmount,
            amount: $amount,
        );
    }

    private function inclusiveDays(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $start->diffInDays($end) + 1;
    }

    /**
     * amount * (segmentDays / periodDays), computed as (amount * segmentDays)
     * / periodDays rather than amount * (segmentDays / periodDays). Dividing
     * last - instead of materializing a day fraction and multiplying by it -
     * avoids compounding the fraction's own truncation into a second
     * multiplication, so this is exact whenever the underlying ratio is exact
     * (e.g. a 21-of-31-day segment against a price that's a multiple of 31).
     */
    private function prorate(string $amount, int $segmentDays, int $periodDays): string
    {
        return bcdiv(bcmul($amount, (string) $segmentDays, self::CALC_SCALE), (string) $periodDays, self::CALC_SCALE);
    }
}
