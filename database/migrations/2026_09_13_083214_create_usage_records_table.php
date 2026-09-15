<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            // merchant_id/customer_id are denormalized off customer_subscription_id
            // so the two hottest read paths - "this merchant's usage in a date
            // range" and "this customer's usage in a date range" - are a single
            // indexed lookup instead of a join through customer_subscriptions.
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_subscription_id')->constrained()->restrictOnDelete();
            // Snapshot of whichever plan was active on the subscription when
            // this row was recorded. Lets billing sum usage per plan/rate
            // directly, without re-deriving it from plan-change date ranges.
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->date('usage_date');
            $table->decimal('quantity', 20, 4)->default(0);
            $table->timestamps();

            // One aggregated row per subscription per day - also makes usage
            // ingestion idempotent (upsert on this key) if a day's usage is
            // recomputed or re-delivered.
            $table->unique(['customer_subscription_id', 'usage_date']);
            $table->index(['merchant_id', 'usage_date']);
            $table->index(['customer_id', 'usage_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
