<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'merchant_id',
    'customer_id',
    'customer_subscription_id',
    'period_start',
    'period_end',
    'currency',
    'total',
])]
class Invoice extends Model
{
    protected function casts(): array
    {
        return [
            // See UsageRecord::casts() for why this must be 'date:Y-m-d', not bare 'date'.
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'total' => 'decimal:2',
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

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }
}
