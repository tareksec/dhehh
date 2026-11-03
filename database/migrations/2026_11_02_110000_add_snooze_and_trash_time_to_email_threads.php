<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email inbox, Phase 2 step 8 (docs/email-inbox-plan.md): a conversation can be snoozed (hidden from the Inbox until a
 * time, then it comes back unread) and the Trash empties itself after a number of days, so a conversation remembers
 * when it was put in Trash. Index names are written out: MySQL allows 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_threads', function (Blueprint $table): void {
            $table->timestamp('snoozed_until')->nullable()->after('status');
            $table->foreignId('snoozed_by')->nullable()->after('snoozed_until')->constrained('users')->nullOnDelete();
            $table->timestamp('trashed_at')->nullable()->after('snoozed_by');

            $table->index(['company_id', 'snoozed_until'], 'email_threads_snooze_index');
            $table->index(['company_id', 'folder', 'trashed_at'], 'email_threads_trash_index');
        });

        // A conversation already in Trash starts its clock now, so nothing that was deleted before this change vanishes on the first run.
        DB::table('email_threads')->where('folder', 'trash')->update(['trashed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('email_threads', function (Blueprint $table): void {
            $table->dropIndex('email_threads_snooze_index');
            $table->dropIndex('email_threads_trash_index');
            $table->dropConstrainedForeignId('snoozed_by');
            $table->dropColumn(['snoozed_until', 'trashed_at']);
        });
    }
};
