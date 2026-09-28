<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            // The reseller (a Customer row with reseller_status='approved').
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('sales_amount', 12, 2)->default(0);
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            // pending (order not delivered yet) -> payable (delivered)
            // or cancelled (order cancelled/returned/refunded).
            $table->string('status', 20)->default('pending');
            $table->timestamp('payable_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_commissions');
    }
};
