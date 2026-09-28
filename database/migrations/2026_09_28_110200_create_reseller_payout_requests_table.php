<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_payout_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->string('account_details', 500);
            // requested -> approved -> paid, or requested/approved -> rejected.
            $table->string('status', 20)->default('requested');
            $table->string('payment_reference')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            // The Finance debit voucher booked when the request is marked paid.
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_payout_requests');
    }
};
