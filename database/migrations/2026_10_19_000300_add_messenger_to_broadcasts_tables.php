<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI inbox control plane, phase 6 (owner answer O5, 2026-10-19):
        // Messenger broadcast. The audience is the Page's chats that wrote
        // inside the last 23 hours, so a recipient is a conversation, not a
        // phone number. `tested_hash` is the hash of the text a test message
        // proved: changing the text makes the test stale.
        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->foreignId('messenger_channel_id')->nullable()->after('whatsapp_template_language')->constrained('conversation_channels')->nullOnDelete();
            $table->text('messenger_text')->nullable()->after('sms_body');
            $table->string('tested_hash', 64)->nullable();
            $table->timestamp('tested_at')->nullable();
        });

        Schema::table('broadcast_recipients', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('messenger_channel_id');
            $table->dropColumn(['messenger_text', 'tested_hash', 'tested_at']);
        });
    }
};
