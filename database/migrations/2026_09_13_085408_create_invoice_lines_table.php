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
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            // Which plan/rate this line bills for - one line per plan-change
            // segment that overlapped the invoiced period. A period with no
            // mid-cycle switch has exactly one line; a period where the
            // customer switched plans has one line per side of the switch.
            $table->foreignId('customer_subscription_plan_change_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->date('segment_start');
            $table->date('segment_end');
            // Snapshot of the segment's un-prorated terms, for audit/transparency.
            $table->decimal('price', 10, 2);
            $table->decimal('included_usage', 20, 4);
            $table->decimal('overage_rate', 10, 4);
            // What was actually charged for this segment.
            $table->decimal('prorated_included_usage', 20, 4);
            $table->decimal('usage_quantity', 20, 4);
            $table->decimal('overage_quantity', 20, 4);
            $table->decimal('base_amount', 12, 2);
            $table->decimal('overage_amount', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index('customer_subscription_plan_change_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
