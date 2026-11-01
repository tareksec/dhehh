<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delete = Trash (owner request 2026-11-01, docs/delete-and-trash-plan.md, phase T4a):
 * customers (which includes resellers: same table) and suppliers are kept when
 * deleted and can be restored or deleted permanently from the Trash page.
 *
 * Nothing about a trashed row's money changes: orders, payments, vouchers and
 * purchases keep pointing at it and keep showing its name; its balance is
 * recalculated on Restore. The customers' reseller slug / subdomain unique
 * index is left as it is on purpose: a reseller in Trash still holds its store
 * address, so a Restore can never collide.
 */
return new class extends Migration
{
    private const TABLES = [
        'customers',
        'suppliers',
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
