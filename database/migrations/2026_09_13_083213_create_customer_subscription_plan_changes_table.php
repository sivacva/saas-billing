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
        Schema::create('customer_subscription_plan_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            // Snapshot of the plan's terms at the moment they took effect.
            // Billing accuracy depends on these staying frozen even if the
            // merchant edits the Plan row's price/overage_rate later - only
            // *new* plan changes pick up the new terms.
            $table->decimal('price', 10, 2);
            $table->char('currency', 3);
            $table->decimal('included_usage', 20, 4);
            $table->decimal('overage_rate', 10, 4);
            $table->timestamp('starts_at');
            // Null while this is the currently active period; set when the
            // customer switches plans again.
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['customer_subscription_id', 'starts_at']);
            $table->index(['customer_subscription_id', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_subscription_plan_changes');
    }
};
