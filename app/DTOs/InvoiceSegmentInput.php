<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * One plan-change segment's contribution to a billing period, as input to
 * InvoiceCalculator. Money/quantity fields are decimal strings (not float)
 * so the calculator can do bcmath arithmetic without float rounding error.
 */
final readonly class InvoiceSegmentInput
{
    public function __construct(
        public int $planChangeId,
        public string $description,
        public CarbonImmutable $segmentStart,
        public CarbonImmutable $segmentEnd,
        public string $price,
        public string $includedUsage,
        public string $overageRate,
        public string $usageQuantity,
    ) {}
}
