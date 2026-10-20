<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI inbox control plane, phase 1 (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.1):
        // who answers a chat now — the AI or the team. `human_handled_until`
        // keeps its meaning and becomes the time a human chat returns to the
        // AI (NULL while a human holds it with no time limit).
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('automation_mode', 10)->default('ai')->after('ai_enabled');
            $table->string('handover_reason', 40)->nullable()->after('automation_mode');
            $table->timestamp('mode_changed_at')->nullable()->after('human_handled_until');
            $table->foreignId('mode_changed_by')->nullable()->after('mode_changed_at')->constrained('users')->nullOnDelete();
        });

        // Existing chats keep their current state: AI switched off, or held by
        // a person until a time still in the future, means a person has it.
        DB::table('conversations')
            ->where(fn ($query) => $query->where('ai_enabled', false)->orWhere('human_handled_until', '>', now()))
            ->update(['automation_mode' => 'human', 'ai_enabled' => false]);
        DB::table('conversations')
            ->where('automation_mode', 'ai')
            ->whereNotNull('human_handled_until')
            ->update(['human_handled_until' => null]);

        // Append-only trail of every switch between the AI and the team.
        Schema::create('conversation_mode_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('from_mode', 10);
            $table->string('to_mode', 10);
            $table->string('source', 20); // dashboard | ai | auto_revert | staff_reply | page_reply | dot_command | bulk
            $table->string('reason', 40)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('revert_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_mode_events');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mode_changed_by');
            $table->dropColumn(['automation_mode', 'handover_reason', 'mode_changed_at']);
        });
    }
};
