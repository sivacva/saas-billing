<?php

namespace App\Http\Controllers\Api;

use App\Actions\LogUsageEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUsageEventRequest;
use App\Models\CustomerSubscription;
use Illuminate\Http\JsonResponse;

class UsageEventController extends Controller
{
    public function store(StoreUsageEventRequest $request, LogUsageEvent $action): JsonResponse
    {
        $data = $request->toDto();

        $subscription = CustomerSubscription::findOrFail($data->customerSubscriptionId);

        ['event' => $event, 'duplicate' => $duplicate] = $action->handle($subscription, $data);

        return new JsonResponse([
            'data' => [
                'id' => $event->id,
                'customer_subscription_id' => $event->customer_subscription_id,
                'idempotency_key' => $event->idempotency_key,
                'quantity' => (string) $event->quantity,
                'usage_date' => $event->usage_date->toDateString(),
                'duplicate' => $duplicate,
            ],
        ], $duplicate ? 200 : 201);
    }
}
