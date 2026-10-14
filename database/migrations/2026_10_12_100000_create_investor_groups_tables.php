<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group-based investment (16_INVESTOR_GROUPS_PLAN.md, phase G1). Investors
 * join a group; the group runs Project-1, 2, 3 … one after another. The
 * settlement engine still reads `project->investments`, which now holds the
 * per-project snapshot of the group's members. Additive only: nothing in the
 * existing tables is changed or backfilled here (the data move is the
 * `investments:backfill-groups` command, dry-run first).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('group_code', 20);
            $table->string('name');
            $table->string('status', 20)->default('open'); // open | running | closed
            $table->date('opened_on')->nullable();
            $table->date('running_since')->nullable(); // anchor of the 2-month cycles
            $table->date('closed_on')->nullable();
            $table->text('close_reason')->nullable();
            $table->unsignedTinyInteger('cycle_months')->default(2);
            $table->boolean('auto_cycle')->default(false);
            $table->decimal('investor_share_percent', 5, 2)->default(40);
            $table->decimal('channel_partner_share_percent', 5, 2)->default(10);
            $table->decimal('company_share_percent', 5, 2)->default(50);
            $table->foreignId('receiving_fund_source_id')->nullable()->constrained('fund_sources')->nullOnDelete();
            $table->foreignId('payout_fund_source_id')->nullable()->constrained('fund_sources')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'group_code'], 'investor_groups_company_code_unique');
            $table->index(['company_id', 'status'], 'investor_groups_company_status_idx');
        });

        Schema::create('investor_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('investor_groups')->cascadeOnDelete();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('invested_at');
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->string('status', 20)->default('active'); // active | withdrawn
            $table->date('withdrawn_at')->nullable();
            $table->foreignId('withdrawal_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->text('withdrawal_note')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'group_id', 'status'], 'inv_group_members_group_status_idx');
            $table->index(['company_id', 'investor_id'], 'inv_group_members_investor_idx');
        });

        Schema::table('investment_projects', function (Blueprint $table): void {
            $table->foreignId('group_id')->nullable()->after('company_id')->constrained('investor_groups')->nullOnDelete();
            $table->unsignedSmallInteger('sequence_in_group')->nullable()->after('group_id');

            $table->unique(['company_id', 'group_id', 'sequence_in_group'], 'inv_projects_group_sequence_unique');
        });

        Schema::table('investments', function (Blueprint $table): void {
            $table->foreignId('group_member_id')->nullable()->after('project_id')->constrained('investor_group_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('investments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('group_member_id');
        });

        Schema::table('investment_projects', function (Blueprint $table): void {
            $table->dropUnique('inv_projects_group_sequence_unique');
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn('sequence_in_group');
        });

        Schema::dropIfExists('investor_group_members');
        Schema::dropIfExists('investor_groups');
    }
};
