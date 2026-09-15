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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_subscription_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            $table->decimal('total', 12, 2);
            $table->timestamps();

            // The idempotency guard for the whole invoice-generation job: one
            // invoice per subscription per billing period, full stop. A rerun
            // (cron misfire, queue retry) that reaches this insert a second
            // time for the same period is rejected by this index, not by an
            // application-level "does one already exist?" check.
            $table->unique(['customer_subscription_id', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
