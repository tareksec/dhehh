<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deleted expense or order payment used to vanish, so the IMS data feed could
 * not show that a profit figure had changed. They are now kept and flagged
 * (see App\Services\Sync\ImsSyncFeed); everywhere else a deleted row still
 * behaves as gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['expenses', 'order_payments'] as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['expenses', 'order_payments'] as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
