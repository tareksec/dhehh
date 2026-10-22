<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI inbox control plane, phase 4 (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.5):
        // the owner's written guidance for the sales agent, kept as versions
        // so a change can be drafted, tried in a test chat, published and
        // rolled back. The live text still lives in the company's AI settings.
        Schema::create('ai_instruction_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->json('blocks');
            $table->string('status', 12)->default('draft'); // draft | published | superseded
            $table->string('change_note', 255)->nullable();
            $table->string('source', 20)->default('agent_desk'); // agent_desk | settings | restore
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'ai_instruction_versions_status_idx');
            $table->index(['company_id', 'published_at'], 'ai_instruction_versions_published_idx');
        });

        // A test chat in the Agent Desk is logged as a run with no conversation.
        Schema::table('crm_ai_runs', function (Blueprint $table): void {
            $table->boolean('is_test')->default(false)->after('status');
            $table->unsignedBigInteger('conversation_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('crm_ai_runs', function (Blueprint $table): void {
            $table->dropColumn('is_test');
        });

        Schema::dropIfExists('ai_instruction_versions');
    }
};
