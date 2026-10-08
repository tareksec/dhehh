<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The agreement text is entered by staff (never written into the code);
        // a published version is never changed — a change is a new version.
        Schema::create('agreement_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30); // investor_deed | partner_agreement
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('body');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'kind', 'version']);
        });

        // "I have read and agree" in the portal, with time, IP and device
        // (owner's draft). Never changed after it is written.
        Schema::create('agreement_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agreement_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_account_id')->constrained('investor_portal_accounts')->cascadeOnDelete();
            $table->foreignId('investor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('body_sha256', 64);
            $table->timestamps();

            $table->unique(['agreement_template_id', 'portal_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_acceptances');
        Schema::dropIfExists('agreement_templates');
    }
};
