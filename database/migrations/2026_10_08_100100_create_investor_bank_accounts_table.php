<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where an investor's or channel partner's money is sent (owner's draft:
        // bank account with BEFTN routing number, optional MFS number). One
        // added from the portal waits for staff approval.
        Schema::create('investor_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->morphs('holder');
            $table->string('type', 10); // bank | mfs
            $table->string('bank_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('routing_number', 9)->nullable();
            $table->string('account_name')->nullable();
            $table->text('account_number')->nullable(); // encrypted
            $table->string('account_last4', 4)->nullable();
            $table->string('mfs_provider', 20)->nullable();
            $table->string('mfs_number', 20)->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->boolean('is_default')->default(false);
            $table->string('source', 10)->default('portal'); // portal | admin
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_bank_accounts');
    }
};
