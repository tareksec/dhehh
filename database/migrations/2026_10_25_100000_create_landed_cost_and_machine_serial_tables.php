<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trade Portal, phase 5 (docs/tasneem-samples/PLAN.md §3.6–3.7, SUMMARY.md §4
 * report 3):
 *
 * - landed_cost_lines: the costs of one import beyond the goods (duty,
 *   insurance, C&F, port, bank, PSI from the C&F agent or the bank; truck and
 *   loading from the owner), each with where the figure came from. Kept apart
 *   from the purchase's own cost fields, which the ERP adds to the purchase
 *   total and so to the supplier's due — these are not owed to the supplier.
 * - lc_files.exchange_rate: the bank's settlement rate (BDT per USD) that
 *   turns the CFR value into Taka; landed_cost_applied_at: when the landed
 *   unit costs were last written to the items' buying price.
 * - machine_serials: optional serial numbers of machines, recorded on their
 *   purchase and, when sold, linked to the Delivery Challan.
 *
 * Also gives the Trade Owner role "trade.landed_cost" when it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lc_files', function (Blueprint $table): void {
            $table->decimal('exchange_rate', 12, 4)->nullable();
            $table->timestamp('landed_cost_applied_at')->nullable();
        });

        Schema::create('landed_cost_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lc_file_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('label');
            $table->string('source', 20);
            $table->decimal('amount', 14, 2);
            $table->date('paid_on')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'lc_file_id'], 'landed_cost_lines_company_file_index');
        });

        Schema::create('machine_serials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_challan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number', 100);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'serial_number'], 'machine_serials_company_product_serial_unique');
            $table->index(['company_id', 'delivery_challan_id'], 'machine_serials_company_challan_index');
        });

        if (Schema::hasTable('user_roles')) {
            $role = DB::table('user_roles')->where('slug', 'trade_owner')->first();

            if ($role) {
                $permissions = json_decode((string) $role->permissions, true) ?: [];
                $permissions = array_values(array_unique([...$permissions, 'trade.landed_cost']));

                DB::table('user_roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_serials');
        Schema::dropIfExists('landed_cost_lines');

        Schema::table('lc_files', function (Blueprint $table): void {
            $table->dropColumn(['exchange_rate', 'landed_cost_applied_at']);
        });
    }
};
