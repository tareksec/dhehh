<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md §6.2 — one row per Video
 * Generation request. A dedicated table rather than a `tool` discriminator on
 * `generated_images` (see plan §3.1): the shape is structurally different
 * (always exactly one output, never an array; a submit/poll async state
 * machine with columns that mean nothing for an image row).
 *
 * Company-owned: `BelongsToCompany` + `CompanyScope`, registered in
 * MultiCompanyIsolationTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('generated_videos')) {
            return;
        }

        Schema::create('generated_videos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('tool')->default('video_generation');
            $table->string('context')->default('general');
            $table->string('operation')->default('image_to_video');

            $table->text('original_prompt')->nullable();
            $table->text('prompt');

            $table->string('source_image_path')->nullable();
            $table->foreignId('source_generated_image_id')->nullable()->constrained('generated_images')->nullOnDelete();

            $table->string('provider_profile_id')->nullable();
            $table->string('provider_label')->nullable();
            $table->string('api_format')->nullable();
            $table->string('model')->nullable();

            $table->string('aspect_ratio')->nullable();
            $table->string('duration_preset')->default('standard');
            $table->unsignedSmallInteger('duration_seconds')->nullable();

            $table->string('external_job_id')->nullable()->index();
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->timestamp('poll_deadline_at')->nullable();

            $table->string('video_path')->nullable();
            $table->string('video_mime_type')->nullable();
            $table->unsignedBigInteger('video_size_bytes')->nullable();
            $table->string('thumbnail_path')->nullable();

            $table->nullableMorphs('linked');
            $table->boolean('is_favorite')->default(false);
            $table->decimal('estimated_cost', 12, 4)->default(0);

            $table->string('review_status')->default('not_required');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->string('status')->default('queued');
            $table->text('error_message')->nullable();
            $table->json('provider_response')->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'context']);
            $table->index(['company_id', 'is_favorite']);
            $table->index(['company_id', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_videos');
    }
};
