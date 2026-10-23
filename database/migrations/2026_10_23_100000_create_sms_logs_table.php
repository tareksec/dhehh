<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row for every order SMS the system tries to send (owner request
 * 2026-10-23: an order was placed and no SMS arrived, and nobody could tell
 * why). The row says whether the SMS was queued, sent, refused by the gateway
 * or skipped, and the reason. Code messages (OTP, password reset) are never
 * logged, so a code never sits in this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('reference', 40)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20);
            $table->string('reason', 40)->nullable();
            $table->unsignedSmallInteger('gateway_code')->nullable();
            $table->string('detail', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'sms_logs_company_created_idx');
            $table->index(['kind', 'order_id', 'reference'], 'sms_logs_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
