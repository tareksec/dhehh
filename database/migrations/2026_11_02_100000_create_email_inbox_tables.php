<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Email inbox (owner request 2026-11-02, plan docs/email-inbox-plan.md): a mailbox per address, its
 * conversations (threads), the messages in them (received, sent, drafts and internal notes), the files, and
 * the labels. Every table is company-owned except the label pivot. Index names are written out: MySQL allows
 * 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_mailboxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // The site whose SMTP account (Integrations → Email Setup) this mailbox sends through.
            $table->foreignId('storefront_setting_id')->nullable()->constrained('storefront_settings')->nullOnDelete();
            $table->string('name', 120);
            $table->string('address', 190);
            $table->string('receive_mode', 20)->default('webhook');
            $table->string('token_hash', 64)->nullable();
            $table->timestamp('token_issued_at')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->text('signature')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'address'], 'email_mailboxes_company_address_unique');
        });

        Schema::create('email_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained('email_mailboxes')->cascadeOnDelete();
            $table->string('subject', 255)->default('');
            $table->string('snippet', 300)->nullable();
            // inbox | archived | spam | trash. Sent, Drafts, Starred and All mail are views over these.
            $table->string('folder', 12)->default('inbox');
            $table->boolean('is_starred')->default(false);
            $table->unsignedSmallInteger('unread_count')->default(0);
            $table->unsignedSmallInteger('message_count')->default(0);
            $table->boolean('has_attachments')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            // The other party: who wrote first (received) or who was written to first (sent).
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_name', 190)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('open');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'mailbox_id', 'folder', 'last_message_at'], 'email_threads_list_index');
            $table->index(['company_id', 'contact_email'], 'email_threads_contact_index');
            $table->index(['company_id', 'customer_id'], 'email_threads_customer_index');
        });

        Schema::create('email_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('email_threads')->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained('email_mailboxes')->cascadeOnDelete();
            // in | out
            $table->string('direction', 3);
            // message | note (internal, never sent) | draft
            $table->string('kind', 10)->default('message');
            // received | pending (sent after the undo window) | sent | failed | draft | note
            $table->string('status', 12)->default('received');
            $table->string('message_id', 255)->nullable();
            $table->string('in_reply_to', 255)->nullable();
            $table->text('references')->nullable();
            $table->string('from_address', 190)->nullable();
            $table->string('from_name', 190)->nullable();
            $table->json('to_addresses')->nullable();
            $table->json('cc_addresses')->nullable();
            $table->json('bcc_addresses')->nullable();
            $table->string('subject', 255)->default('');
            $table->longText('body_text')->nullable();
            // Already cleaned by App\Services\Email\Inbox\EmailSanitizer; never trusted at display time either.
            $table->longText('body_html')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('sent_at')->nullable();
            // A message waiting out the undo-send window goes out at this time.
            $table->timestamp('send_after')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // The id the mail service gave (Amazon SES), so a delivered / bounced report can find the message.
            $table->string('provider_message_id', 100)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('raw_size')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'sent_at'], 'email_messages_thread_index');
            // One message once: a mail delivered twice, or our own sent mail coming back by copy, is recognised by its Message-ID.
            $table->unique(['mailbox_id', 'message_id'], 'email_messages_message_id_unique');
            $table->index(['company_id', 'status', 'send_after'], 'email_messages_due_index');
        });

        Schema::create('email_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('email_messages')->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('mime', 120)->default('application/octet-stream');
            $table->unsignedInteger('size')->default(0);
            $table->string('path', 500);
            $table->string('content_id', 255)->nullable();
            $table->boolean('is_inline')->default(false);
            $table->timestamps();

            $table->index('message_id', 'email_attachments_message_index');
        });

        Schema::create('email_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 16)->default('blue');
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'email_labels_company_name_unique');
        });

        Schema::create('email_label_thread', function (Blueprint $table): void {
            $table->foreignId('thread_id')->constrained('email_threads')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('email_labels')->cascadeOnDelete();

            $table->primary(['thread_id', 'label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_label_thread');
        Schema::dropIfExists('email_labels');
        Schema::dropIfExists('email_attachments');
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('email_threads');
        Schema::dropIfExists('email_mailboxes');
    }
};
