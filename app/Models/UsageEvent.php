<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'merchant_id',
    'customer_id',
    'customer_subscription_id',
    'idempotency_key',
    'quantity',
    'occurred_at',
    'usage_date',
])]
class UsageEvent extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'occurred_at' => 'datetime',
            // See UsageRecord::casts() for why this must be 'date:Y-m-d', not bare 'date'.
            'usage_date' => 'date:Y-m-d',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerSubscription::class, 'customer_subscription_id');
    }
}
