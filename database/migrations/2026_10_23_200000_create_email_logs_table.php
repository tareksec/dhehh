<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row for every email the system tries to send (owner request
 * 2026-10-23): sent, failed, or not sent, and when it failed or was not sent,
 * why. Only who it went to, what kind of message it was and the mail server's
 * own answer are kept. The text of the message is never stored, so a login or
 * reset code never sits in this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('recipient', 254);
            $table->string('via', 150)->nullable();
            $table->string('status', 20);
            $table->string('reason', 40)->nullable();
            $table->string('detail', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'email_logs_company_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
