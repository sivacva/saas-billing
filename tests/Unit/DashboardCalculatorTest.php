<?php

namespace Tests\Unit;

use App\Services\DashboardCalculator;
use PHPUnit\Framework\TestCase;

class DashboardCalculatorTest extends TestCase
{
    private DashboardCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new DashboardCalculator;
    }

    public function test_it_extrapolates_usage_from_the_current_pace(): void
    {
        // 10 days into a 30-day period, 300 used so far -> pace projects to 900.
        $this->assertSame('900.000000', $this->calculator->projectUsage(30, 10, '300'));
    }

    public function test_it_projects_zero_usage_when_nothing_has_been_rolled_up_yet(): void
    {
        $this->assertSame('0', $this->calculator->projectUsage(30, 0, '0'));
    }

    public function test_projected_overage_when_the_projection_exceeds_included_usage(): void
    {
        $result = $this->calculator->projectedOverage(
            projectedUsage: '900',
            includedUsage: '500',
            overageRate: '0.10',
        );

        $this->assertSame('400.000000', $result['overage_quantity']);
        $this->assertSame('40.00', $result['overage_revenue']);
    }

    public function test_projected_overage_is_zero_within_the_included_allowance(): void
    {
        $result = $this->calculator->projectedOverage(
            projectedUsage: '400',
            includedUsage: '500',
            overageRate: '0.10',
        );

        $this->assertSame('0.000000', $result['overage_quantity']);
        $this->assertSame('0.00', $result['overage_revenue']);
    }

    public function test_usage_change_ratio_and_default_churn_threshold(): void
    {
        // Projected to land at 30% of last cycle - well past the 50% drop line.
        $ratio = $this->calculator->usageChangeRatio('300', '1000');
        $this->assertSame('0.300000', $ratio);
        $this->assertTrue($this->calculator->isChurnRisk($ratio));

        // Projected to land at 60% - a real drop, but not past the default threshold.
        $ratio = $this->calculator->usageChangeRatio('600', '1000');
        $this->assertSame('0.600000', $ratio);
        $this->assertFalse($this->calculator->isChurnRisk($ratio));
    }

    public function test_a_custom_threshold_can_flag_a_smaller_drop(): void
    {
        $ratio = $this->calculator->usageChangeRatio('600', '1000'); // 0.6

        $this->assertFalse($this->calculator->isChurnRisk($ratio));
        $this->assertTrue($this->calculator->isChurnRisk($ratio, dropThreshold: '0.7'));
    }

    public function test_no_previous_usage_means_no_ratio_and_no_churn_flag(): void
    {
        $this->assertNull($this->calculator->usageChangeRatio('300', '0'));
        $this->assertFalse($this->calculator->isChurnRisk(null));
    }

    public function test_growth_is_never_flagged_as_churn_risk(): void
    {
        $ratio = $this->calculator->usageChangeRatio('1500', '1000'); // grew 50%

        $this->assertFalse($this->calculator->isChurnRisk($ratio));
    }
}
