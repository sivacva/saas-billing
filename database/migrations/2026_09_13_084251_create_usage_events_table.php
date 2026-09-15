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
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_subscription_id')->constrained()->restrictOnDelete();
            // Caller-supplied key identifying this specific event. The unique
            // index below - not a validation rule - is what makes retries safe:
            // see LogUsageEvent for why that distinction matters.
            $table->string('idempotency_key');
            $table->decimal('quantity', 20, 4);
            // When the usage actually happened (client-supplied); usage_date is
            // derived from it and denormalized so this table stays queryable by
            // day without recomputing the date from a timestamp.
            $table->timestamp('occurred_at');
            $table->date('usage_date');
            $table->timestamps();

            $table->unique(['customer_subscription_id', 'idempotency_key']);
            $table->index(['customer_subscription_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
