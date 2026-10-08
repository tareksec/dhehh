<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Investor and channel-partner payouts paid together
        // (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §9, Phase 5): one user
        // makes the batch, a different user approves it, the payment list is
        // exported, then the results are recorded and one payout voucher is
        // booked for the batch.
        Schema::create('payout_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('batch_number');
            $table->string('kind', 20)->default('investor');
            $table->string('status', 20)->default('draft'); // draft | approved | exported | completed | cancelled
            $table->date('payout_date')->nullable();
            $table->foreignId('fund_source_id')->nullable()->constrained('fund_sources')->nullOnDelete();
            $table->string('file_format', 20)->default('manual_list');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->string('export_path')->nullable();
            $table->string('export_sha256', 64)->nullable();
            $table->string('bank_reference')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'batch_number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payout_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_batch_id')->constrained()->cascadeOnDelete();
            $table->morphs('payable'); // SettlementPayout | ChannelPartnerPayout
            $table->morphs('payee'); // Investor | ChannelPartner
            $table->foreignId('investor_bank_account_id')->nullable()->constrained()->nullOnDelete();
            // Copy of the payment account when the item was added.
            $table->string('payee_name');
            $table->string('account_type', 10);
            $table->string('bank_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('routing_number', 9)->nullable();
            $table->string('account_name')->nullable();
            $table->text('account_number')->nullable(); // encrypted
            $table->string('account_last4', 4)->nullable();
            $table->string('mfs_provider', 20)->nullable();
            $table->string('mfs_number', 20)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('pending'); // pending | paid | failed
            $table->string('bank_reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->date('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batch_items');
        Schema::dropIfExists('payout_batches');
    }
};
