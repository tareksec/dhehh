<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 19_SOCIAL_CONTENT_PLANNER_PLAN.md §7: organic social posts (Facebook Page,
 * Instagram) and their attached media. Index names are explicit and short
 * (MySQL 8.4 limit, MigrationIdentifierLengthTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // Per-company running number, shown as S-12.
            $table->unsignedInteger('number');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            // One language per post (bn | en).
            $table->string('language', 2)->default('bn');
            $table->json('platforms');
            $table->json('platform_options')->nullable();
            // UTC; people type it in the company's timezone.
            $table->timestamp('scheduled_at')->nullable();
            // draft | ready | scheduled | posted | blocked | archived
            $table->string('status', 16)->default('draft');
            // pending | approved. The fingerprint is what was approved: any
            // later change to the content makes the approval invalid.
            $table->string('approval_state', 16)->default('pending');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_fingerprint', 64)->nullable();
            // idle | queued | running | succeeded | failed
            $table->string('publish_state', 16)->default('idle');
            $table->text('publish_error')->nullable();
            // Private: the checkpoint per platform. Never sent to the browser or logs.
            $table->json('publish_progress')->nullable();
            // Public: one entry per platform with the platform's own id, link and status.
            $table->json('publish_result')->nullable();
            // A running job owns the post through this lease; a stale run cannot overwrite a newer one.
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->nullableMorphs('source');
            $table->boolean('is_ai_drafted')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'social_posts_company_number_unique');
            $table->index(['company_id', 'status', 'scheduled_at'], 'social_posts_company_due_index');
            $table->index(['publish_state', 'lease_expires_at'], 'social_posts_lease_index');
        });

        Schema::create('social_post_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            // image | video
            $table->string('kind', 8)->default('image');
            $table->string('path');
            $table->unsignedBigInteger('generated_image_id')->nullable();
            $table->unsignedBigInteger('generated_video_id')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            // The hash makes re-attaching the same file idempotent.
            $table->string('sha256', 64)->nullable();
            $table->timestamps();

            $table->index(['social_post_id', 'sort_order'], 'social_post_media_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_media');
        Schema::dropIfExists('social_posts');
    }
};
