<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_subscription_id',
    'customer_subscription_plan_change_id',
    'period_start',
    'period_end',
    'quantity',
    'rolled_up_through',
])]
class UsageRollup extends Model
{
    protected function casts(): array
    {
        return [
            // See UsageRecord::casts() for why this must be 'date:Y-m-d', not bare 'date'.
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'quantity' => 'decimal:4',
            'rolled_up_through' => 'date:Y-m-d',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerSubscription::class, 'customer_subscription_id');
    }

    public function planChange(): BelongsTo
    {
        return $this->belongsTo(CustomerSubscriptionPlanChange::class, 'customer_subscription_plan_change_id');
    }
}
