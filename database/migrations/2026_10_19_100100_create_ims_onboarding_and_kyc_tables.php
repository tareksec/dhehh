<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IMS v2 phase 2 (IMS-V2/TRD.md §3.2–3.3): KYC on the investor, one to five
 * nominees, invites and one-time codes for joining by invite.
 *
 * The single nominee kept on `investors.nominee_*` is copied into
 * `investor_nominees` (share 100%); the old columns are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table): void {
            $table->string('kyc_status', 20)->default('not_submitted')->after('nominee_address');
            $table->timestamp('kyc_submitted_at')->nullable()->after('kyc_status');
            $table->timestamp('kyc_reviewed_at')->nullable()->after('kyc_submitted_at');
            $table->foreignId('kyc_reviewed_by')->nullable()->after('kyc_reviewed_at')->constrained('users')->nullOnDelete();
            $table->text('kyc_note')->nullable()->after('kyc_reviewed_by');
            $table->json('kyc_check')->nullable()->after('kyc_note');
            $table->timestamp('email_verified_at')->nullable()->after('kyc_check');
            $table->index(['company_id', 'kyc_status'], 'investors_company_kyc_status_idx');
        });

        Schema::create('investor_nominees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('relation')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('nid')->nullable(); // encrypted
            $table->text('address')->nullable();
            $table->decimal('share_percent', 5, 2)->default(100);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'investor_id'], 'investor_nominees_company_investor_idx');
        });

        Schema::create('investor_kyc_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // nid_front | nid_back | selfie | other
            $table->string('path');
            $table->string('mime', 60)->nullable();
            $table->text('ocr_result')->nullable(); // encrypted json
            $table->string('uploaded_by_type', 20); // staff | investor
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'investor_id', 'type'], 'investor_kyc_docs_company_investor_type_idx');
        });

        Schema::create('investor_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('contact'); // a normalised phone (01XXXXXXXXX) or an e-mail
            $table->string('contact_type', 10); // phone | email
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique('investor_invites_token_hash_unique');
            $table->timestamp('expires_at');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('investor_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'contact'], 'investor_invites_company_contact_idx');
        });

        Schema::create('investor_otp_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('contact');
            $table->string('purpose', 30); // invite
            $table->string('code_hash', 255);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'contact', 'purpose'], 'investor_otp_codes_company_contact_purpose_idx');
        });

        // The one nominee the ERP kept so far becomes the first nominee row (100%).
        DB::table('investors')->whereNotNull('nominee_name')->where('nominee_name', '!=', '')->orderBy('id')
            ->chunkById(200, function ($investors): void {
                $now = now();

                DB::table('investor_nominees')->insert($investors->map(fn ($investor): array => [
                    'company_id' => $investor->company_id,
                    'investor_id' => $investor->id,
                    'name' => $investor->nominee_name,
                    'relation' => $investor->nominee_relation,
                    'phone' => $investor->nominee_phone,
                    // Already encrypted with the same key and cast: copied as it is.
                    'nid' => $investor->nominee_nid_or_passport,
                    'address' => $investor->nominee_address,
                    'share_percent' => 100,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_otp_codes');
        Schema::dropIfExists('investor_invites');
        Schema::dropIfExists('investor_kyc_documents');
        Schema::dropIfExists('investor_nominees');

        Schema::table('investors', function (Blueprint $table): void {
            $table->dropIndex('investors_company_kyc_status_idx');
            $table->dropConstrainedForeignId('kyc_reviewed_by');
            $table->dropColumn(['kyc_status', 'kyc_submitted_at', 'kyc_reviewed_at', 'kyc_note', 'kyc_check', 'email_verified_at']);
        });
    }
};
