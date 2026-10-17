<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_channels', function (Blueprint $table): void {
            // connection_type 'web' = linked by WhatsApp Web QR login through
            // the whatsapp-bridge service (archive of the Business App chats).
            $table->string('web_status', 30)->nullable()->after('connection_type');
            $table->string('web_phone', 30)->nullable()->after('web_status');
            $table->dateTime('web_connected_at')->nullable()->after('web_phone');
            $table->dateTime('web_disconnected_at')->nullable()->after('web_connected_at');
            $table->dateTime('web_history_synced_at')->nullable()->after('web_disconnected_at');
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            // "Delete for everyone" only flags the archived message; the
            // original text and media are kept.
            $table->dateTime('revoked_at')->nullable()->after('sent_at');
        });

        // Permanent customer address book synced from the linked WhatsApp
        // account. Kept even if the channel is deleted or the number banned.
        Schema::create('whatsapp_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('conversation_channels')->nullOnDelete();
            $table->string('jid');
            $table->string('lid')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('name')->nullable();
            $table->string('push_name')->nullable();
            $table->string('verified_name')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'jid']);
            $table->index(['company_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contacts');

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropColumn('revoked_at');
        });

        Schema::table('conversation_channels', function (Blueprint $table): void {
            $table->dropColumn(['web_status', 'web_phone', 'web_connected_at', 'web_disconnected_at', 'web_history_synced_at']);
        });
    }
};
