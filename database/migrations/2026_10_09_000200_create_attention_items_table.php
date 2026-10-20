<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI inbox control plane, phase 2 (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.3):
        // one row per time a chat needed a person — the record behind the
        // push alert, with when it was first answered and how it ended.
        Schema::create('attention_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30); // handover
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('reason', 40)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->foreignId('first_response_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('outcome', 20)->nullable(); // answered | returned_to_ai | never_answered | dismissed
            $table->timestamps();

            $table->index(['company_id', 'resolved_at', 'due_at'], 'attention_items_open_idx');
            $table->index(['company_id', 'opened_at'], 'attention_items_opened_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attention_items');
    }
};
