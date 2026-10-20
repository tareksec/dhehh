<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** IMS v2 (TRD §6 ims:shipment-delays): a late shipment is reported to the office only once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->timestamp('ims_delay_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('ims_delay_alerted_at');
        });
    }
};
