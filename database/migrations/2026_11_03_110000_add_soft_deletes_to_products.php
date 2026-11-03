<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delete = Trash (owner request 2026-11-01, docs/delete-and-trash-plan.md, phase T5b):
 * a product is kept when deleted and can be restored or deleted permanently.
 *
 * `(company_id, sku)` and `(company_id, barcode)` keep their unique indexes, so a product
 * in Trash still holds its SKU and barcode. `trash_reason` is why it was moved (a
 * "Test product" may go even with many orders), `trash_note` the owner's words, and
 * `trash_cascade` remembers what went to Trash together with it (orders, purchases and
 * the stock pool it left) so that Restore brings back exactly those.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'deleted_at')) {
                $table->softDeletes();
            }

            if (! Schema::hasColumn('products', 'trash_reason')) {
                $table->string('trash_reason', 40)->nullable();
            }

            if (! Schema::hasColumn('products', 'trash_note')) {
                $table->string('trash_note', 500)->nullable();
            }

            if (! Schema::hasColumn('products', 'trash_cascade')) {
                $table->json('trash_cascade')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            foreach (['trash_cascade', 'trash_note', 'trash_reason'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('products', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
