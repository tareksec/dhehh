<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner decision (2026-10-12): the payment methods a reseller sends an
        // own-courier advance to are chosen by the admin from the store's list
        // (reseller_payment_method_ids; NULL = never chosen, so every active
        // manual method keeps working as before), and Reseller Settings can add
        // methods only for the reseller portal (reseller_only = true, never
        // offered at the storefront checkout).
        Schema::table('storefront_payment_methods', function (Blueprint $table): void {
            $table->boolean('reseller_only')->default(false)->after('is_active');
        });

        // A reseller-only method may share a name with a store method
        // ("bKash" with a different number), so the name is unique per scope.
        // The new index goes in first: company_id's foreign key needs an
        // index that starts with it on MySQL.
        Schema::table('storefront_payment_methods', function (Blueprint $table): void {
            $table->unique(['company_id', 'reseller_only', 'name'], 'storefront_pm_company_scope_name_unique');
        });

        Schema::table('storefront_payment_methods', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'name']);
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->json('reseller_payment_method_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropColumn('reseller_payment_method_ids');
        });

        Schema::table('storefront_payment_methods', function (Blueprint $table): void {
            $table->unique(['company_id', 'name']);
        });

        Schema::table('storefront_payment_methods', function (Blueprint $table): void {
            $table->dropUnique('storefront_pm_company_scope_name_unique');
        });

        Schema::table('storefront_payment_methods', function (Blueprint $table): void {
            $table->dropColumn('reseller_only');
        });
    }
};
