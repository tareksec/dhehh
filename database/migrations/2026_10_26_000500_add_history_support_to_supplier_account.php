<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Old goods are already counted in today's stock: a receipt or order of history records the account
        // facts (received, taken, buy price) without moving stock a second time.
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->boolean('books_stock')->default(true)->after('is_opening');
        });

        Schema::table('supplier_orders', function (Blueprint $table): void {
            $table->string('external_reference')->nullable()->after('order_number');
            $table->boolean('books_stock')->default(true)->after('advance_collected_by');
            $table->unique(['company_id', 'external_reference']);
        });

        // Figures kept outside the ledgers: cash, shipping and ad cost of the old workbook, or a later correction.
        Schema::create('supplier_account_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('entry_date');
            $table->string('type');
            $table->decimal('amount', 14, 2);
            $table->string('external_reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            // Named by hand: the generated names pass MySQL's 64 character limit.
            $table->unique(['company_id', 'external_reference'], 'supplier_adjustments_company_reference_unique');
            $table->index(['company_id', 'supplier_id', 'entry_date'], 'supplier_adjustments_company_supplier_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_account_adjustments');

        Schema::table('supplier_orders', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'external_reference']);
            $table->dropColumn(['external_reference', 'books_stock']);
        });

        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->dropColumn('books_stock');
        });
    }
};
