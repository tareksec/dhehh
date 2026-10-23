<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trade Portal, phase 4 (docs/tasneem-samples/PLAN.md §3.2, SUMMARY.md §2): the
 * LC file of a purchase — everything the import papers need beyond what the
 * purchase already holds (its LC / PI / CI numbers, ports and delivery terms
 * stay on `purchases`): the opening bank and margin, the bank's trade office,
 * the marine insurance cover note, the inspection, the vessel / B/L /
 * container, the weights of this shipment (never global), the paper dates,
 * the stage and the document status. One LC file per purchase.
 *
 * Also gives the Trade Owner role "trade.lc" when the role exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 30)->default('pi_received');
            $table->string('doc_status', 30)->default('awaiting_docs');

            $table->decimal('lc_value_usd', 14, 2)->nullable();
            $table->decimal('margin_percent', 5, 2)->nullable();
            $table->decimal('margin_amount_bdt', 14, 2)->nullable();
            $table->text('lc_bank')->nullable();
            $table->string('lc_account_name')->nullable();
            $table->string('lc_account_number')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_bin')->nullable();

            $table->string('insurer_name')->nullable();
            $table->text('insurer_address')->nullable();
            $table->string('cover_note_number')->nullable();
            $table->date('cover_note_date')->nullable();

            $table->string('psi_agency')->nullable();
            $table->date('psi_date')->nullable();

            $table->text('goods_description')->nullable();
            $table->boolean('goods_used')->default(false);
            $table->string('total_packages')->nullable();
            $table->decimal('net_weight_kg', 14, 3)->nullable();
            $table->decimal('gross_weight_kg', 14, 3)->nullable();
            $table->decimal('cbm', 10, 2)->nullable();
            $table->string('carrier')->nullable();
            $table->string('vessel_name')->nullable();
            $table->string('port_of_shipment')->nullable();
            $table->string('place_of_delivery')->nullable();
            $table->date('etd')->nullable();
            $table->date('eta')->nullable();
            $table->string('bl_number')->nullable();
            $table->date('bl_date')->nullable();
            $table->string('container_number')->nullable();
            $table->string('seal_number')->nullable();

            $table->date('opening_letter_date')->nullable();
            $table->date('certificate_date')->nullable();
            $table->date('shipment_advice_date')->nullable();
            $table->date('drl_date')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'purchase_id'], 'lc_files_company_purchase_unique');
            $table->index(['company_id', 'stage'], 'lc_files_company_stage_index');
        });

        if (Schema::hasTable('user_roles')) {
            $role = DB::table('user_roles')->where('slug', 'trade_owner')->first();

            if ($role) {
                $permissions = json_decode((string) $role->permissions, true) ?: [];
                $permissions = array_values(array_unique([...$permissions, 'trade.lc']));

                DB::table('user_roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_files');
    }
};
