<?php

namespace App\Models;

use App\Enums\CustomerSubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'merchant_id',
    'customer_id',
    'plan_id',
    'status',
    'current_period_start',
    'current_period_end',
    'started_at',
    'canceled_at',
    'ends_at',
])]
class CustomerSubscription extends Model
{
    protected function casts(): array
    {
        return [
            'status' => CustomerSubscriptionStatus::class,
            // See UsageRecord::casts() for why this must be 'date:Y-m-d', not bare 'date'.
            'current_period_start' => 'date:Y-m-d',
            'current_period_end' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'canceled_at' => 'datetime',
            'ends_at' => 'datetime',
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

    /**
     * The plan this subscription is currently on. Kept in sync with the
     * open (ends_at null) row in planChanges() whenever the plan switches.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function planChanges(): HasMany
    {
        return $this->hasMany(CustomerSubscriptionPlanChange::class);
    }

    public function currentPlanChange(): HasOne
    {
        return $this->hasOne(CustomerSubscriptionPlanChange::class)->whereNull('ends_at');
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }
}
