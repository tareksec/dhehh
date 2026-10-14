<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_api_keys', function (Blueprint $table): void {
            // 'website' = the existing website/storefront API; 'sync' = the
            // read-only data feed for an outside system (IMS). Every key that
            // existed before this column is a website key.
            $table->string('scope', 20)->default('website')->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('company_api_keys', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
    }
};
