<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner (2026-10-18): investors get their profit share every cycle,
        // sales or not, so a settlement can be made from a profit that staff
        // enter by hand. `basis` says which way a settlement was made: every
        // settlement that existed before is 'sales'.
        Schema::table('project_settlements', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_settlements', 'basis')) {
                $table->string('basis', 20)->default('sales')->after('outcome');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_settlements', function (Blueprint $table): void {
            if (Schema::hasColumn('project_settlements', 'basis')) {
                $table->dropColumn('basis');
            }
        });
    }
};
