<?php

namespace App\DTOs;

final readonly class ChurnRiskCustomer
{
    public function __construct(
        public int $customerId,
        public string $customerName,
        public string $customerEmail,
        public string $projectedUsageThisCycle,
        public string $previousCycleUsage,
        /** Fraction of previous-cycle usage this cycle is projected to reach, e.g. '0.3' = 30%. */
        public string $changeRatio,
    ) {}
}
