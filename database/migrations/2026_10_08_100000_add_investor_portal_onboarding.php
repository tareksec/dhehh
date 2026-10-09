<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md Phase 3. A channel partner's
        // invite code (the owner's draft: "a unique invite link/code").
        Schema::table('channel_partners', function (Blueprint $table): void {
            $table->string('invite_code', 20)->nullable();
            $table->boolean('invite_enabled')->default(false);
            $table->unique(['company_id', 'invite_code']);
        });

        // A self-registration waits in the existing Investor Drafts queue.
        Schema::table('investor_drafts', function (Blueprint $table): void {
            $table->foreignId('portal_account_id')->nullable()->constrained('investor_portal_accounts')->nullOnDelete();
        });

        // An applicant's account points at their draft until staff confirm it;
        // a rejected application keeps its reason for the status page.
        Schema::table('investor_portal_accounts', function (Blueprint $table): void {
            $table->foreignId('investor_draft_id')->nullable()->constrained('investor_drafts')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('investor_portal_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('investor_draft_id');
            $table->dropColumn('rejection_reason');
        });

        Schema::table('investor_drafts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('portal_account_id');
        });

        Schema::table('channel_partners', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'invite_code']);
            $table->dropColumn(['invite_code', 'invite_enabled']);
        });
    }
};
