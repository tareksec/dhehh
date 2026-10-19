<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI inbox control plane, phase 6 (owner answer O4, 2026-10-19): one
        // follow-up row now carries a whole reminder schedule. `step` is the
        // number of reminders already dealt with (sent, or handed to the
        // team); `base_at` is the customer message the hours are counted from.
        Schema::table('crm_sales_follow_ups', function (Blueprint $table): void {
            $table->unsignedTinyInteger('step')->default(0)->after('attempts');
            $table->timestamp('base_at')->nullable()->after('step');
        });
    }

    public function down(): void
    {
        Schema::table('crm_sales_follow_ups', function (Blueprint $table): void {
            $table->dropColumn(['step', 'base_at']);
        });
    }
};
