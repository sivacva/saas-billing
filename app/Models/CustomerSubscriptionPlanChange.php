<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_subscription_id',
    'plan_id',
    'price',
    'currency',
    'included_usage',
    'overage_rate',
    'starts_at',
    'ends_at',
])]
class CustomerSubscriptionPlanChange extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'included_usage' => 'decimal:4',
            'overage_rate' => 'decimal:4',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerSubscription::class, 'customer_subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
