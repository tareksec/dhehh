<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A cost ZamZam paid on a supplier's behalf (shipping, ads): it counts in that supplier's account.
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->nullable()->after('expense_category_id')->constrained()->nullOnDelete();
            $table->string('supplier_cost_type')->nullable()->after('supplier_id');
            $table->index(['company_id', 'supplier_id', 'supplier_cost_type']);
        });

        // Cash paid straight into the supplier account (not against a purchase invoice).
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->boolean('for_supplier_account')->default(false)->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropColumn('for_supplier_account');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'supplier_id', 'supplier_cost_type']);
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('supplier_cost_type');
        });
    }
};
