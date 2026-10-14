<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3 gap analysis P1.1 — the investor deed (clause 3) and the channel-partner
 * agreement (clauses 2-3) both require every voucher, receipt and settlement
 * sheet to be kept and shown to investors. Today the only upload anywhere in
 * the module is a security instrument's signed-contract scan. One polymorphic
 * attachment store, hung off projects, settlements, payouts and cost items.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('investment_documents')) {
            return;
        }

        Schema::create('investment_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->morphs('documentable');
            $table->string('file_path');
            $table->string('file_type')->nullable();
            $table->string('category')->default('other');
            $table->string('label')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'documentable_type', 'documentable_id'], 'investment_documents_company_documentable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_documents');
    }
};
