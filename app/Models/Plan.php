<?php

namespace App\Models;

use App\Observers\PlanObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'merchant_id',
    'name',
    'slug',
    'price',
    'currency',
    'billing_interval',
    'included_usage',
    'overage_rate',
    'is_active',
])]
#[ObservedBy(PlanObserver::class)]
class Plan extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'included_usage' => 'decimal:4',
            'overage_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CustomerSubscription::class);
    }

    public function planChanges(): HasMany
    {
        return $this->hasMany(CustomerSubscriptionPlanChange::class);
    }
}
