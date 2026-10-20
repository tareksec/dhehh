<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMS v2 money rules (IMS-V2/TRD.md §3.2–3.3, §4): investments and group
 * deposits can wait as "pending" until the money is confirmed and can carry
 * an optional tenor; a loss share is taken from the capital
 * (`capital_loss_amount`); a settled cycle is frozen (`locked_at`); a project
 * can be linked to several ERP purchases; capital paid back is recorded in
 * `capital_returns`; the system's profit proposals in `profit_proposals`.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['investments', 'investor_group_members'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedSmallInteger('tenor_months')->nullable();
                $table->date('maturity_date')->nullable();
                $table->timestamp('maturity_reminder_sent_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        Schema::table('settlement_payouts', function (Blueprint $table): void {
            $table->decimal('capital_loss_amount', 15, 2)->default(0)->after('profit_share_amount');
        });

        Schema::table('project_settlements', function (Blueprint $table): void {
            $table->decimal('investor_loss_percent', 5, 2)->nullable()->after('outcome');
            $table->timestamp('locked_at')->nullable()->after('settled_at');
        });

        Schema::create('investment_project_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('investment_projects')->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'purchase_id'], 'inv_project_purchases_project_purchase_unique');
        });

        Schema::create('capital_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('group_member_id')->nullable()->constrained('investor_group_members')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('returned_on');
            $table->string('method', 30)->nullable();
            $table->string('reference')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('status', 20)->default('paid'); // paid | voided
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'investor_id', 'status'], 'capital_returns_company_investor_status_idx');
        });

        Schema::create('profit_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('investment_projects')->cascadeOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('figures');
            $table->string('status', 20)->default('pending'); // pending | approved | dismissed | stale
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('settlement_id')->nullable()->constrained('project_settlements')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status'], 'profit_proposals_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_proposals');
        Schema::dropIfExists('capital_returns');
        Schema::dropIfExists('investment_project_purchases');

        Schema::table('project_settlements', function (Blueprint $table): void {
            $table->dropColumn(['investor_loss_percent', 'locked_at']);
        });

        Schema::table('settlement_payouts', function (Blueprint $table): void {
            $table->dropColumn('capital_loss_amount');
        });

        foreach (['investments', 'investor_group_members'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('confirmed_by');
                $table->dropColumn(['tenor_months', 'maturity_date', 'maturity_reminder_sent_at', 'confirmed_at']);
            });
        }
    }
};
