<?php

namespace App\Http\Requests;

use App\DTOs\UsageEventData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreUsageEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_subscription_id' => ['required', 'integer', 'exists:customer_subscriptions,id'],
            // Deliberately no `unique` rule here. A validation-time uniqueness
            // check is a SELECT that races with a concurrent request's SELECT -
            // both can see "not taken" and both proceed to insert. Duplicate
            // detection instead happens at the database's unique index during
            // the actual insert (see LogUsageEvent) - see that class for why.
            'idempotency_key' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    public function toDto(): UsageEventData
    {
        return UsageEventData::from([
            'customer_subscription_id' => $this->integer('customer_subscription_id'),
            'idempotency_key' => $this->string('idempotency_key')->toString(),
            'quantity' => $this->float('quantity'),
            'occurred_at' => $this->date('occurred_at') ?? now(),
        ]);
    }
}
