<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // The shop name shown in the header of the reseller's own store
            // (13_RESELLER_PORTAL_PLAN.md §2.1 Q3). Falls back to business_name.
            $table->string('reseller_store_name')->nullable()->after('reseller_slug');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('reseller_store_name');
        });
    }
};
