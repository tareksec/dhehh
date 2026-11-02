<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delete = Trash (owner request 2026-11-01, docs/delete-and-trash-plan.md, phase T4b):
 * chat channels, leads (and the activities logged on them), quotations and
 * reseller levels are kept when deleted and can be restored or deleted
 * permanently from the Trash.
 *
 * The unique indexes are left as they are on purpose: a channel in Trash still
 * holds its Phone Number ID / Page ID, a lead in Trash still holds its website
 * reference, so a Restore can never collide.
 */
return new class extends Migration
{
    private const TABLES = [
        'conversation_channels',
        'leads',
        'lead_activities',
        'quotations',
        'reseller_levels',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
