<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // The external website's own quote/inquiry id, sent on
            // POST /api/v1/leads. Makes a retried submission return the
            // existing lead instead of creating a duplicate, and is echoed
            // back on the lead.status_updated webhook so the website can
            // find its quote — same convention as orders.external_reference.
            $table->string('external_reference')->nullable()->after('source');
            $table->unique(['company_id', 'external_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'external_reference']);
            $table->dropColumn('external_reference');
        });
    }
};
