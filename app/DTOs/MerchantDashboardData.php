<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class MerchantDashboardData
{
    /**
     * @param TopCustomerUsage[] $topCustomersByUsage
     * @param ChurnRiskCustomer[] $churnRiskCustomers
     */
    public function __construct(
        public string $currentCycleUsage,
        public string $currentCycleAllowance,
        public array $topCustomersByUsage,
        public string $projectedOverageRevenueThisCycle,
        public array $churnRiskCustomers,
        /** When this snapshot was actually computed - stays fixed across cache hits, so a cached response doesn't lie about its own freshness. */
        public CarbonImmutable $generatedAt,
    ) {}
}
