<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded with hasColumn/hasTable checks: MySQL DDL isn't transactional, so if a
        // previous deploy was interrupted mid-migration, some of this may already be applied
        // even though it was never recorded in the migrations table. Make retries safe.
        if (! Schema::hasColumn('leads', 'opted_out_at')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dateTime('opted_out_at')->nullable()->after('follow_up_reminded_at');
            });
        }

        if (! Schema::hasColumn('customers', 'opted_out_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dateTime('opted_out_at')->nullable()->after('customer_source');
            });
        }

        if (! Schema::hasTable('broadcasts')) {
            Schema::create('broadcasts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('audience_type', 20); // leads | customers | both
                $table->json('lead_status_filter')->nullable();
                $table->json('lead_source_filter')->nullable();
                $table->string('channel', 20); // whatsapp | sms | both
                $table->foreignId('whatsapp_channel_id')->nullable()->constrained('conversation_channels')->nullOnDelete();
                $table->string('whatsapp_template_name')->nullable();
                $table->string('whatsapp_template_language', 10)->default('bn');
                $table->text('sms_body')->nullable();
                $table->string('status', 20)->default('draft'); // draft|queued|sending|completed|failed|cancelled
                $table->unsignedInteger('recipients_count')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('queued_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status']);
            });
        }

        if (! Schema::hasTable('broadcast_recipients')) {
            Schema::create('broadcast_recipients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
                $table->string('recipient_type', 20); // lead | customer
                $table->unsignedBigInteger('recipient_id');
                $table->string('name')->nullable();
                $table->string('phone', 40);
                $table->string('channel_used', 20)->nullable(); // whatsapp | sms — set once sent/failed
                $table->string('status', 20)->default('pending'); // pending|sent|failed
                $table->text('error')->nullable();
                $table->dateTime('sent_at')->nullable();
                $table->timestamps();

                $table->index(['broadcast_id', 'status']);
                $table->unique(['broadcast_id', 'recipient_type', 'recipient_id'], 'broadcast_recipients_broadcast_recipient_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('opted_out_at');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('opted_out_at');
        });
    }
};
