<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trade Portal, phase 2 (docs/tasneem-samples/PLAN.md §4): the Delivery
 * Challan, the shop's sale paper. Each confirmed challan is backed by an ERP
 * order (orders.source = trade_challan), so stock, the customer's due and
 * later payments use the ERP's own order rules; this table keeps what the
 * paper itself shows (pad SL no., buyer as written, advance / cash / cheque /
 * due terms, who signed). Lines keep their own description, unit and remarks;
 * a line with a product also becomes an order line that takes stock.
 *
 * Also gives the Trade Owner role the new portal permissions when it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_challans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('challan_number', 30);
            $table->date('challan_date');
            $table->string('buyer_name');
            $table->string('buyer_phone')->nullable();
            $table->text('buyer_address')->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('advance_amount', 14, 2)->default(0);
            $table->date('advance_date')->nullable();
            $table->string('advance_note')->nullable();
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->date('cash_date')->nullable();
            $table->foreignId('payment_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('cheque_number')->nullable();
            $table->string('cheque_bank')->nullable();
            $table->text('due_terms')->nullable();
            $table->string('authorised_name')->nullable();
            $table->string('authorised_designation')->nullable();
            $table->string('authorised_phone')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('confirmed');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'challan_number'], 'delivery_challans_company_number_unique');
            $table->index(['company_id', 'challan_date'], 'delivery_challans_company_date_index');
        });

        Schema::create('delivery_challan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_challan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->unsignedInteger('quantity')->default(0);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->string('remarks')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        if (Schema::hasTable('user_roles')) {
            $role = DB::table('user_roles')->where('slug', 'trade_owner')->first();

            if ($role) {
                $permissions = json_decode((string) $role->permissions, true) ?: [];
                $permissions = array_values(array_unique([...$permissions, 'trade.sales', 'trade.purchases', 'trade.stock', 'trade.dues', 'trade.import']));

                DB::table('user_roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_challan_items');
        Schema::dropIfExists('delivery_challans');
    }
};
