<?php

namespace App\DTOs;

final readonly class TopCustomerUsage
{
    public function __construct(
        public int $customerId,
        public string $customerName,
        public string $customerEmail,
        public string $usageQuantity,
        /** Usage-so-far as a percentage of the current plan segment's full included_usage allowance. */
        public string $percentOfAllowance,
    ) {}
}
