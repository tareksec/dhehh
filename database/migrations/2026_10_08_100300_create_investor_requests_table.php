<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Requests an investor sends from the portal (owner's draft: top-up,
        // withdrawal, reinvest, support; pending → approved / rejected with
        // notes and audit). Approval runs the existing staff flows.
        Schema::create('investor_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_account_id')->constrained('investor_portal_accounts')->cascadeOnDelete();
            $table->foreignId('investor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30); // top_up | withdrawal | reinvest | support
            $table->foreignId('project_id')->nullable()->constrained('investment_projects')->nullOnDelete();
            $table->foreignId('settlement_payout_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2)->nullable();
            $table->json('payload')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $table->text('admin_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->nullableMorphs('result');
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_requests');
    }
};
