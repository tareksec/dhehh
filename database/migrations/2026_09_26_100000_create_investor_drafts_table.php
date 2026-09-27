<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Investors read from an Excel/CSV file or pasted text land here first as
 * drafts. Staff review each one (the investor form, pre-filled) and only a
 * confirmed draft becomes a real row in `investors`, so an unreviewed import
 * never shows up in investor pickers, reports or payouts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('investor_drafts')) {
            return;
        }

        Schema::create('investor_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('batch_id', 36)->index();
            $table->string('source', 20);
            $table->string('source_name')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('guardian_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            // Encrypted at rest, like investors.nid_number.
            $table->text('nid_number')->nullable();
            $table->string('stamp_number')->nullable();
            $table->string('cheque_number')->nullable();
            $table->foreignId('channel_partner_id')->nullable()->constrained('investors')->nullOnDelete();
            $table->boolean('is_channel_partner')->default(false);
            $table->string('nominee_name')->nullable();
            $table->text('nominee_nid_or_passport')->nullable();
            $table->string('nominee_phone')->nullable();
            $table->string('nominee_relation')->nullable();
            $table->text('nominee_address')->nullable();
            $table->json('import_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_drafts');
    }
};
