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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            // Flat recurring fee for the plan.
            $table->decimal('price', 10, 2);
            $table->char('currency', 3)->default('usd');
            $table->string('billing_interval')->default('month');
            // Usage units included before overage kicks in, and the per-unit
            // overage rate. decimal(20,4) covers both integer counts (API
            // calls) and fractional metrics (GB, compute-seconds) without a
            // future migration.
            $table->decimal('included_usage', 20, 4)->default(0);
            $table->decimal('overage_rate', 10, 4)->default(0);
            // Retire a plan from being subscribed to without deleting it -
            // existing subscriptions still reference it by FK.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'slug']);
            $table->index(['merchant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
