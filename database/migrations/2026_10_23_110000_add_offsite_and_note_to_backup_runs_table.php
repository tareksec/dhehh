<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            // When the copy on Google Drive was made (null = this run did not send one),
            // so backup health can tell "the backup ran" from "the off-site copy arrived".
            $table->timestamp('offsite_at')->nullable()->after('finished_at');
            // What a restore test found (tables, migrations, time taken) or other short remark.
            $table->string('note', 500)->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropColumn(['offsite_at', 'note']);
        });
    }
};
