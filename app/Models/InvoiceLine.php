<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'invoice_id',
    'customer_subscription_plan_change_id',
    'description',
    'segment_start',
    'segment_end',
    'price',
    'included_usage',
    'overage_rate',
    'prorated_included_usage',
    'usage_quantity',
    'overage_quantity',
    'base_amount',
    'overage_amount',
    'amount',
])]
class InvoiceLine extends Model
{
    protected function casts(): array
    {
        return [
            // See UsageRecord::casts() for why this must be 'date:Y-m-d', not bare 'date'.
            'segment_start' => 'date:Y-m-d',
            'segment_end' => 'date:Y-m-d',
            'price' => 'decimal:2',
            'included_usage' => 'decimal:4',
            'overage_rate' => 'decimal:4',
            'prorated_included_usage' => 'decimal:4',
            'usage_quantity' => 'decimal:4',
            'overage_quantity' => 'decimal:4',
            'base_amount' => 'decimal:2',
            'overage_amount' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function planChange(): BelongsTo
    {
        return $this->belongsTo(CustomerSubscriptionPlanChange::class, 'customer_subscription_plan_change_id');
    }
}
