<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number');
            $table->date('received_on');
            $table->string('reference')->nullable();
            // One catch-all receipt per product for goods whose real date/cartons are unknown (imported history).
            $table->boolean('is_opening')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'receipt_number']);
            $table->index(['company_id', 'supplier_id', 'received_on']);
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('cartons')->nullable();
            $table->unsignedInteger('pcs_per_carton')->nullable();
            // Pieces the supplier sent: the "Received" figure of the supplier account.
            $table->unsignedInteger('quantity');
            // Lost on the way: never reached the godown.
            $table->unsignedInteger('short_quantity')->default(0);
            // Reached the godown broken.
            $table->unsignedInteger('damage_quantity')->default(0);
            // The supplier's buy price per piece (no shipping or other landed cost).
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
            $table->index(['company_id', 'product_id']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('pcs_per_carton')->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('pcs_per_carton');
        });

        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
    }
};
