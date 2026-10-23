<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            // Which off-site places got the copy ("r2", "drive" or "r2,drive"; null = none or not
            // recorded), so each place can be judged on its own and the R2 page can estimate what
            // was sent there. Runs from before this column that did reach Google Drive are filled in.
            $table->string('offsite_to', 40)->nullable()->after('offsite_at');
        });

        DB::table('backup_runs')->whereNotNull('offsite_at')->update(['offsite_to' => 'drive']);
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropColumn('offsite_to');
        });
    }
};
