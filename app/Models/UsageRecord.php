<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'merchant_id',
    'customer_id',
    'customer_subscription_id',
    'plan_id',
    'usage_date',
    'quantity',
])]
class UsageRecord extends Model
{
    protected function casts(): array
    {
        return [
            // Explicit format, not bare 'date': the plain 'date' cast still
            // *stores* a full "Y-m-d H:i:s" string, which silently breaks
            // whereBetween/<=/>= comparisons against plain 'Y-m-d' bounds at
            // exact boundary dates. 'date:Y-m-d' stores and reads back a bare
            // date, matching how every query in this app compares it.
            'usage_date' => 'date:Y-m-d',
            'quantity' => 'decimal:4',
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

    /**
     * The plan that was active on the subscription when this usage was
     * recorded - a snapshot, not necessarily the subscription's plan today.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
