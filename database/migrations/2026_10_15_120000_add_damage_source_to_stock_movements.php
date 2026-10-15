<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner decision (2026-10-15): damaged goods are counted in two groups
        // that must stay apart: pieces a customer sent back damaged, and pieces
        // found damaged when a shipment (carton) is opened. Existing damage
        // rows stay NULL ("not labelled").
        if (! Schema::hasColumn('stock_movements', 'damage_source')) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->string('damage_source', 30)->nullable()->after('reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_movements', 'damage_source')) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->dropColumn('damage_source');
            });
        }
    }
};
