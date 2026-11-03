<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delete = Trash (owner request 2026-11-01, docs/delete-and-trash-plan.md, phase T5a):
 * purchases and stock pools are kept when deleted and can be restored or deleted
 * permanently from the Trash.
 *
 * `purchases.purchase_number` keeps its unique index, so a purchase in Trash still
 * holds its number. A stock pool in Trash is empty (its products are taken out of
 * the pool, exactly as a delete always did), so `stock_pools.member_product_ids`
 * remembers which products were in it and Restore puts them back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchases') && ! Schema::hasColumn('purchases', 'deleted_at')) {
            Schema::table('purchases', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('stock_pools')) {
            Schema::table('stock_pools', function (Blueprint $table): void {
                if (! Schema::hasColumn('stock_pools', 'deleted_at')) {
                    $table->softDeletes();
                }

                if (! Schema::hasColumn('stock_pools', 'member_product_ids')) {
                    $table->json('member_product_ids')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'deleted_at')) {
            Schema::table('purchases', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('stock_pools')) {
            Schema::table('stock_pools', function (Blueprint $table): void {
                if (Schema::hasColumn('stock_pools', 'member_product_ids')) {
                    $table->dropColumn('member_product_ids');
                }

                if (Schema::hasColumn('stock_pools', 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
            });
        }
    }
};
