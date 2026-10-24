<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wording of the emails the system sends, one row per company and message
 * (owner request 2026-10-24, the email twin of sms_templates).
 *
 * A row for a built-in message (order status, login and reset codes, IMS news)
 * has key "order.confirmed", "code.customer_login", "ims.profit_shared" ... and
 * is_custom = false; a row the owner makes up herself has key "custom.<id>", a
 * name, and is_custom = true. Order messages start empty (nothing is mailed until
 * the owner writes one); code and IMS messages fall back to the built-in text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('name', 120)->nullable();
            $table->string('subject', 190)->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'key'], 'email_templates_company_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
