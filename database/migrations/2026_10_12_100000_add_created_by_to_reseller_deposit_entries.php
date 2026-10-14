<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner decision (2026-10-12): staff can add to or deduct from a
        // reseller's deposit with a reason, and every entry is logged. The
        // member of staff who made a manual adjustment is kept on the entry
        // (NULL for the entries the system makes itself).
        Schema::table('reseller_deposit_entries', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('note')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reseller_deposit_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
