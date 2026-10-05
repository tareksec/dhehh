<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // A reseller order sent with the reseller's own courier: they book
            // the parcel themselves, pay the full amount in advance, and the
            // company packs it with the reseller's parcel ID on it.
            $table->boolean('reseller_own_courier')->default(false)->after('reseller_customer_id');
            $table->string('reseller_courier_name')->nullable()->after('reseller_own_courier');
            $table->string('reseller_parcel_id')->nullable()->after('reseller_courier_name');
            $table->timestamp('reseller_parcel_packed_at')->nullable()->after('reseller_parcel_id');
            $table->index(['reseller_customer_id', 'reseller_parcel_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['reseller_customer_id', 'reseller_parcel_id']);
            $table->dropColumn(['reseller_own_courier', 'reseller_courier_name', 'reseller_parcel_id', 'reseller_parcel_packed_at']);
        });
    }
};
