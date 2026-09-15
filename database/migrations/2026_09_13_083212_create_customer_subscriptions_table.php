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
        Schema::create('customer_subscriptions', function (Blueprint $table) {
            $table->id();
            // Denormalized alongside customer_id/plan_id so merchant-scoped
            // queries (e.g. "all active subscriptions for merchant X") don't
            // need to join through customers or plans.
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // Current plan pointer, kept in sync with the open row in
            // customer_subscription_plan_changes whenever the customer switches.
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('active');
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->timestamp('started_at');
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['merchant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_subscriptions');
    }
};
