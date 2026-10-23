<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amazon SES reports bounces, complaints and deliveries by its own message ID
 * (owner request 2026-10-23), so the log keeps the ID SES answered with in order
 * to find the row again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table): void {
            $table->string('message_id', 100)->nullable()->after('via');

            $table->index('message_id', 'email_logs_message_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table): void {
            $table->dropIndex('email_logs_message_id_idx');
            $table->dropColumn('message_id');
        });
    }
};
