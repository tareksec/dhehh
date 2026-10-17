<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group-based investment, phase G3 (16_INVESTOR_GROUPS_PLAN.md): new money
 * (top-up, reinvest, imported or signed-up investors) goes into an OPEN GROUP,
 * and a withdrawal notice is about a group. Additive: the `project_id`
 * columns stay for investments that are not in a group.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['investor_requests', 'investor_drafts', 'investment_withdrawal_notices'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'group_id')) {
                    $blueprint->foreignId('group_id')->nullable()->constrained('investor_groups')->nullOnDelete();
                }
            });
        }

        Schema::table('investor_cycle_elections', function (Blueprint $table): void {
            $table->foreignId('next_group_id')->nullable()->constrained('investor_groups')->nullOnDelete();
            $table->foreignId('reinvestment_member_id')->nullable()->constrained('investor_group_members')->nullOnDelete();
        });

        Schema::table('investor_group_members', function (Blueprint $table): void {
            // Stamp / cheque numbers noted when the money came in; they become
            // the security instrument of the member's first project row.
            $table->json('paperwork')->nullable()->after('notes');
            // Set when a withdrawal notice matures: the member is in project N
            // and is not copied into N + 1.
            $table->unsignedSmallInteger('leaves_after_sequence')->nullable()->after('withdrawal_note');
        });

        Schema::table('settlement_payouts', function (Blueprint $table): void {
            // Principal paid back with this payout. NULL = all of `investment_amount`
            // (every payout made before groups). A member who stays in the group
            // gets 0 here: only the profit is paid and the principal carries on.
            $table->decimal('principal_returned_amount', 15, 2)->nullable()->after('investment_amount');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_payouts', function (Blueprint $table): void {
            $table->dropColumn('principal_returned_amount');
        });

        Schema::table('investor_group_members', function (Blueprint $table): void {
            $table->dropColumn(['paperwork', 'leaves_after_sequence']);
        });

        Schema::table('investor_cycle_elections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reinvestment_member_id');
            $table->dropConstrainedForeignId('next_group_id');
        });

        foreach (['investor_requests', 'investor_drafts', 'investment_withdrawal_notices'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('group_id');
            });
        }
    }
};
