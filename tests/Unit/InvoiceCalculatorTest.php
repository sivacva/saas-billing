<?php

namespace Tests\Unit;

use App\DTOs\InvoiceSegmentInput;
use App\Services\InvoiceCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class InvoiceCalculatorTest extends TestCase
{
    private InvoiceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new InvoiceCalculator;
    }

    public function test_full_period_with_no_switch_is_not_prorated(): void
    {
        $result = $this->calculator->calculate(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-31'),
            [
                new InvoiceSegmentInput(
                    planChangeId: 1,
                    description: 'Pro',
                    segmentStart: CarbonImmutable::parse('2026-01-01'),
                    segmentEnd: CarbonImmutable::parse('2026-01-31'),
                    price: '29.00',
                    includedUsage: '1000',
                    overageRate: '0.01',
                    usageQuantity: '500',
                ),
            ]
        );

        $this->assertSame('29.00', $result->total);
        $this->assertCount(1, $result->lines);
        $this->assertSame('29.00', $result->lines[0]->baseAmount);
        $this->assertSame('0.000000', $result->lines[0]->overageQuantity);
        $this->assertSame('0.00', $result->lines[0]->overageAmount);
    }

    public function test_full_period_charges_overage_beyond_included_usage(): void
    {
        $result = $this->calculator->calculate(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-31'),
            [
                new InvoiceSegmentInput(
                    planChangeId: 1,
                    description: 'Pro',
                    segmentStart: CarbonImmutable::parse('2026-01-01'),
                    segmentEnd: CarbonImmutable::parse('2026-01-31'),
                    price: '29.00',
                    includedUsage: '1000',
                    overageRate: '0.01',
                    usageQuantity: '1200',
                ),
            ]
        );

        $this->assertSame('1200', $result->lines[0]->usageQuantity);
        $this->assertSame('200.000000', $result->lines[0]->overageQuantity);
        $this->assertSame('2.00', $result->lines[0]->overageAmount);
        $this->assertSame('31.00', $result->total);
    }

    public function test_joining_mid_cycle_prorates_price_and_included_usage(): void
    {
        // 31-day period, subscription started on day 11 - a 21-day segment.
        $result = $this->calculator->calculate(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-31'),
            [
                new InvoiceSegmentInput(
                    planChangeId: 1,
                    description: 'Pro',
                    segmentStart: CarbonImmutable::parse('2026-01-11'),
                    segmentEnd: CarbonImmutable::parse('2026-01-31'),
                    price: '62.00',
                    includedUsage: '310',
                    overageRate: '0.50',
                    usageQuantity: '250',
                ),
            ]
        );

        $line = $result->lines[0];
        // day fraction = 21/31; 62 * 21/31 = 42.00 exactly.
        $this->assertSame('42.00', $line->baseAmount);
        // included usage prorated the same way: 310 * 21/31 = 210.
        $this->assertSame('210.000000', $line->proratedIncludedUsage);
        // usage 250 - prorated allowance 210 = 40 units of overage at $0.50.
        $this->assertSame('40.000000', $line->overageQuantity);
        $this->assertSame('20.00', $line->overageAmount);
        $this->assertSame('62.00', $line->amount);
        $this->assertSame('62.00', $result->total);
    }

    public function test_mid_cycle_plan_switch_splits_and_prorates_each_side(): void
    {
        // Same 31-day period as above, but the customer was on a cheaper
        // plan for the first 10 days before switching to the plan from the
        // previous test for the remaining 21 days.
        $result = $this->calculator->calculate(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-31'),
            [
                new InvoiceSegmentInput(
                    planChangeId: 1,
                    description: 'Starter (Jan 1 - Jan 10)',
                    segmentStart: CarbonImmutable::parse('2026-01-01'),
                    segmentEnd: CarbonImmutable::parse('2026-01-10'),
                    price: '31.00',
                    includedUsage: '155',
                    overageRate: '1.00',
                    usageQuantity: '70',
                ),
                new InvoiceSegmentInput(
                    planChangeId: 2,
                    description: 'Pro (Jan 11 - Jan 31)',
                    segmentStart: CarbonImmutable::parse('2026-01-11'),
                    segmentEnd: CarbonImmutable::parse('2026-01-31'),
                    price: '62.00',
                    includedUsage: '310',
                    overageRate: '0.50',
                    usageQuantity: '250',
                ),
            ]
        );

        $this->assertCount(2, $result->lines);

        [$starter, $pro] = $result->lines;

        // Starter: day fraction 10/31; base = 31 * 10/31 = 10.00 exactly.
        // Included usage prorated: 155 * 10/31 = 50. Overage: 70-50=20 @ $1.
        $this->assertSame('10.00', $starter->baseAmount);
        $this->assertSame('20.000000', $starter->overageQuantity);
        $this->assertSame('20.00', $starter->overageAmount);
        $this->assertSame('30.00', $starter->amount);

        // Pro side: identical to the joined-mid-cycle case above.
        $this->assertSame('42.00', $pro->baseAmount);
        $this->assertSame('20.00', $pro->overageAmount);
        $this->assertSame('62.00', $pro->amount);

        // Combined into one invoice total.
        $this->assertSame('92.00', $result->total);
    }

    public function test_zero_usage_bills_only_the_prorated_base_price(): void
    {
        $result = $this->calculator->calculate(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-31'),
            [
                new InvoiceSegmentInput(
                    planChangeId: 1,
                    description: 'Pro',
                    segmentStart: CarbonImmutable::parse('2026-01-11'),
                    segmentEnd: CarbonImmutable::parse('2026-01-31'),
                    price: '62.00',
                    includedUsage: '310',
                    overageRate: '0.50',
                    usageQuantity: '0',
                ),
            ]
        );

        $this->assertSame('0.000000', $result->lines[0]->overageQuantity);
        $this->assertSame('0.00', $result->lines[0]->overageAmount);
        $this->assertSame('42.00', $result->total);
    }

    public function test_amounts_that_do_not_divide_evenly_round_to_the_nearest_cent(): void
    {
        // 3-day period, 1-day segment: day fraction = 1/3 -> 10 * 1/3 = 3.3333...
        $result = $this->calculator->calculate(
            CarbonImmutable::parse('2026-02-01'),
            CarbonImmutable::parse('2026-02-03'),
            [
                new InvoiceSegmentInput(
                    planChangeId: 1,
                    description: 'Pro',
                    segmentStart: CarbonImmutable::parse('2026-02-01'),
                    segmentEnd: CarbonImmutable::parse('2026-02-01'),
                    price: '10.00',
                    includedUsage: '0',
                    overageRate: '0',
                    usageQuantity: '0',
                ),
            ]
        );

        $this->assertSame('3.33', $result->lines[0]->baseAmount);
    }
}
