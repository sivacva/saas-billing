<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\MerchantDashboardCache;
use Illuminate\Http\JsonResponse;

class MerchantDashboardController extends Controller
{
    public function show(Merchant $merchant, MerchantDashboardCache $cache): JsonResponse
    {
        $data = $cache->remember($merchant);

        return new JsonResponse([
            'data' => [
                'generated_at' => $data->generatedAt->toIso8601String(),
                'current_cycle_usage' => $data->currentCycleUsage,
                'current_cycle_allowance' => $data->currentCycleAllowance,
                'top_customers_by_usage' => array_map(fn ($top) => [
                    'customer_id' => $top->customerId,
                    'name' => $top->customerName,
                    'email' => $top->customerEmail,
                    'usage_quantity' => $top->usageQuantity,
                    'percent_of_allowance' => $top->percentOfAllowance,
                ], $data->topCustomersByUsage),
                'projected_overage_revenue_this_cycle' => $data->projectedOverageRevenueThisCycle,
                'churn_risk_customers' => array_map(fn ($risk) => [
                    'customer_id' => $risk->customerId,
                    'name' => $risk->customerName,
                    'email' => $risk->customerEmail,
                    'projected_usage_this_cycle' => $risk->projectedUsageThisCycle,
                    'previous_cycle_usage' => $risk->previousCycleUsage,
                    'change_ratio' => $risk->changeRatio,
                ], $data->churnRiskCustomers),
            ],
        ]);
    }
}
