<?php

namespace App\Actions;

use App\DTOs\UsageEventData;
use App\Models\CustomerSubscription;
use App\Models\UsageEvent;
use App\Models\UsageRecord;
use Illuminate\Support\Facades\DB;

/**
 * Records a single usage event exactly once, then folds it into that day's
 * usage_records aggregate.
 *
 * Safety under concurrency (see inline notes for the mechanics):
 *
 * 1. Duplicate detection is a database unique-index check, not an
 *    application-level "look then insert." Two requests racing with the same
 *    idempotency key both attempt the same insert; the unique index on
 *    (customer_subscription_id, idempotency_key) lets exactly one of them
 *    actually create the row - the database's storage engine, not our PHP
 *    code, is the arbiter, so there is no window between "check" and "act"
 *    for a second request to slip through.
 * 2. The daily total is updated with a single atomic upsert statement
 *    (INSERT ... ON CONFLICT/ON DUPLICATE KEY UPDATE quantity = quantity + ?)
 *    rather than "read quantity, add, write it back" - the increment happens
 *    inside the database against the live row under its own lock, so two
 *    concurrent increments to the same subscription+day can't overwrite one
 *    another (no lost update).
 * 3. Both writes happen in one transaction, so an event is never recorded
 *    without its contribution landing in the aggregate, or vice versa.
 */
class LogUsageEvent
{
    /**
     * @return array{event: UsageEvent, duplicate: bool}
     */
    public function handle(CustomerSubscription $subscription, UsageEventData $data): array
    {
        return DB::transaction(function () use ($subscription, $data) {
            $usageDate = $data->occurredAt->toDateString();

            // insertOrIgnore compiles to `ON CONFLICT ... DO NOTHING` (sqlite/pgsql)
            // or `INSERT IGNORE` (mysql) - a single statement that reports 0 rows
            // affected on a conflict instead of throwing. That matters: throwing
            // here would abort the surrounding transaction on Postgres, making it
            // unsafe to keep reading/writing in the same transaction afterwards.
            $inserted = DB::table('usage_events')->insertOrIgnore([[
                'merchant_id' => $subscription->merchant_id,
                'customer_id' => $subscription->customer_id,
                'customer_subscription_id' => $subscription->id,
                'idempotency_key' => $data->idempotencyKey,
                'quantity' => $data->quantity,
                'occurred_at' => $data->occurredAt,
                'usage_date' => $usageDate,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);

            $isDuplicate = $inserted === 0;

            if (! $isDuplicate) {
                $this->incrementDailyUsage($subscription, $usageDate, $data->quantity);
            }

            $event = UsageEvent::where('customer_subscription_id', $subscription->id)
                ->where('idempotency_key', $data->idempotencyKey)
                ->firstOrFail();

            return ['event' => $event, 'duplicate' => $isDuplicate];
        });
    }

    private function incrementDailyUsage(CustomerSubscription $subscription, string $usageDate, float $quantity): void
    {
        $table = (new UsageRecord)->getTable();
        $now = now();

        $columns = [
            $subscription->merchant_id,
            $subscription->customer_id,
            $subscription->id,
            $subscription->plan_id,
            $usageDate,
            $quantity,
            $now,
            $now,
        ];

        match (DB::connection()->getDriverName()) {
            'mysql' => DB::statement(
                "insert into {$table}
                    (merchant_id, customer_id, customer_subscription_id, plan_id, usage_date, quantity, created_at, updated_at)
                 values (?, ?, ?, ?, ?, ?, ?, ?)
                 on duplicate key update quantity = quantity + ?",
                [...$columns, $quantity]
            ),
            // sqlite and pgsql both support this ON CONFLICT syntax.
            default => DB::statement(
                "insert into {$table}
                    (merchant_id, customer_id, customer_subscription_id, plan_id, usage_date, quantity, created_at, updated_at)
                 values (?, ?, ?, ?, ?, ?, ?, ?)
                 on conflict (customer_subscription_id, usage_date)
                 do update set quantity = {$table}.quantity + excluded.quantity, updated_at = excluded.updated_at",
                $columns
            ),
        };
    }
}
