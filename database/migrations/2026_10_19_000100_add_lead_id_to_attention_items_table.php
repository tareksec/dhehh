<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI inbox control plane, phase 6 (owner answer O3, 2026-10-19): a
        // new website lead also needs a person, and has no chat behind it.
        Schema::table('attention_items', function (Blueprint $table): void {
            $table->foreignId('lead_id')->nullable()->after('conversation_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attention_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lead_id');
        });
    }
};
