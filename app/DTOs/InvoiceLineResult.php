<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class InvoiceLineResult
{
    public function __construct(
        public int $planChangeId,
        public string $description,
        public CarbonImmutable $segmentStart,
        public CarbonImmutable $segmentEnd,
        public string $price,
        public string $includedUsage,
        public string $overageRate,
        public string $proratedIncludedUsage,
        public string $usageQuantity,
        public string $overageQuantity,
        public string $baseAmount,
        public string $overageAmount,
        public string $amount,
    ) {}
}
