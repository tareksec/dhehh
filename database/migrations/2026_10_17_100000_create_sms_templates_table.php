<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The wording of the SMS the system sends, one row per company and message
 * (owner request 2026-10-17): order status, sign-in / password-reset codes and
 * the investors' project status messages.
 *
 * Order and project messages start empty (nothing is sent until the owner
 * writes one); code messages fall back to the built-in wording.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->text('body')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        // The "Project completed message" written on Investor Portal Settings
        // now lives here, as the "Project completed" template.
        if (Schema::hasColumn('companies', 'investor_project_completed_message')) {
            $now = now();

            DB::table('companies')
                ->whereNotNull('investor_project_completed_message')
                ->where('investor_project_completed_message', '!=', '')
                ->get(['id', 'investor_project_completed_message'])
                ->each(fn ($company) => DB::table('sms_templates')->insert([
                    'company_id' => $company->id,
                    'key' => 'investor_project.closed',
                    'body' => $company->investor_project_completed_message,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
