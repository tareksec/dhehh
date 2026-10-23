<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately no company_id: a backup copies the whole database, so it
        // is a platform-level record -- see App\Models\BackupRun's doc comment.
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20); // database | app
            $table->string('kind', 20); // hourly | daily | predeploy | manual
            $table->string('status', 20); // running | success | failed
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['scope', 'kind', 'started_at'], 'backup_runs_scope_kind_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
