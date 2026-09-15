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
        Schema::create('usage_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_subscription_id')->constrained()->restrictOnDelete();
            // Which plan/rate this usage falls under - a segment can span at
            // most part of one billing period here; a segment that runs for
            // several periods without a plan switch gets one rollup row per
            // period, not one row that grows forever.
            $table->foreignId('customer_subscription_plan_change_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            // Running total, advanced incrementally by the daily rollup job.
            $table->decimal('quantity', 20, 4)->default(0);
            // Watermark: the last usage_date already folded into quantity.
            // Null means nothing rolled up yet. This - not a fresh SUM each
            // run - is what makes reruns cheap and safe: a run that finds
            // rolled_up_through already caught up to its target date adds
            // nothing.
            $table->date('rolled_up_through')->nullable();
            $table->timestamps();

            $table->unique(['customer_subscription_plan_change_id', 'period_start']);
            $table->index(['customer_subscription_id', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_rollups');
    }
};
