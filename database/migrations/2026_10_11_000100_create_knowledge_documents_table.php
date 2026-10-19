<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI inbox control plane, phase 5 (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.6):
        // the company's own long-tail facts (fees, rules, packages, returns)
        // that do not fit a FAQ line. The sales agent looks passages up with
        // lookup_knowledge; the server sends the chosen passage as written.
        Schema::create('knowledge_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->longText('content');
            $table->boolean('is_active')->default(true);
            $table->string('source_file', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'is_active'], 'knowledge_documents_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
