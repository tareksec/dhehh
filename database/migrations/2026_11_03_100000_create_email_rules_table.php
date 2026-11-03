<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email inbox, Phase 2 step 10 (docs/email-inbox-plan.md): rules that act on mail as it arrives in a mailbox -- put a
 * label on it, give it to a person, archive it -- when the sender or the subject contains some text. Index names are
 * written out: MySQL allows 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained('email_mailboxes')->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            // When: the sender's address or name contains this, and the subject contains that (both given = both must hold).
            $table->string('from_contains', 190)->nullable();
            $table->string('subject_contains', 190)->nullable();
            // Then: any of these.
            $table->foreignId('label_id')->nullable()->constrained('email_labels')->nullOnDelete();
            $table->foreignId('assign_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('archive')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'mailbox_id', 'is_active', 'position'], 'email_rules_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_rules');
    }
};
