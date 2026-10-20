<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMS v2 phases 3, 5 and 6 (IMS-V2/TRD.md §3.3): project updates,
 * milestones and published reports; the feed; support threads; monthly
 * statement dispatches; bank / mobile-banking statement matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('investment_projects')->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('video_url')->nullable();
            $table->boolean('visible_to_investors')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'project_id', 'visible_to_investors'], 'project_updates_company_project_visible_idx');
        });

        Schema::create('project_update_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('update_id')->constrained('project_updates')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('project_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('investment_projects')->cascadeOnDelete();
            $table->string('title');
            $table->date('due_on')->nullable();
            $table->date('done_on')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'project_id'], 'project_milestones_company_project_idx');
        });

        Schema::create('project_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('investment_projects')->cascadeOnDelete();
            $table->string('title');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft'); // draft | published
            $table->json('summary')->nullable(); // frozen on publish
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'project_id', 'status'], 'project_reports_company_project_status_idx');
        });

        Schema::create('feed_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('post'); // post | news | link
            $table->string('title')->nullable();
            $table->text('body');
            $table->string('link_url')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('comments_enabled')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'published_at'], 'feed_posts_company_published_idx');
        });

        Schema::create('feed_post_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('feed_posts')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('feed_post_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('feed_posts')->cascadeOnDelete();
            $table->foreignId('investor_portal_account_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->unique(['post_id', 'investor_portal_account_id'], 'feed_post_views_post_account_unique');
        });

        Schema::create('feed_post_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('feed_posts')->cascadeOnDelete();
            $table->foreignId('investor_portal_account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['post_id', 'investor_portal_account_id'], 'feed_post_likes_post_account_unique');
        });

        Schema::create('feed_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('feed_posts')->cascadeOnDelete();
            $table->foreignId('investor_portal_account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'post_id'], 'feed_comments_company_post_idx');
        });

        Schema::create('support_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investor_portal_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('investment_projects')->nullOnDelete();
            $table->string('subject');
            $table->string('status', 20)->default('open'); // open | closed
            $table->boolean('staff_unread')->default(true);
            $table->boolean('investor_unread')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->foreignId('legacy_request_id')->nullable()->constrained('investor_requests')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status', 'staff_unread'], 'support_threads_company_status_unread_idx');
        });

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('support_threads')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('from_staff')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('statement_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['investor_id', 'period'], 'statement_dispatches_investor_period_unique');
        });

        Schema::create('bank_statement_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20); // bkash | nagad | rocket | bank
            $table->string('file_name');
            $table->unsignedInteger('lines_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->constrained('bank_statement_imports')->cascadeOnDelete();
            $table->string('line_hash', 64);
            $table->date('transacted_on')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();
            $table->string('counterparty')->nullable();
            $table->string('status', 20)->default('unmatched'); // unmatched | suggested | matched | ignored
            $table->string('matched_type')->nullable();
            $table->unsignedBigInteger('matched_id')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'line_hash'], 'bank_statement_lines_company_hash_unique');
            $table->index(['company_id', 'status'], 'bank_statement_lines_company_status_idx');
        });
    }

    public function down(): void
    {
        foreach (['bank_statement_lines', 'bank_statement_imports', 'statement_dispatches', 'support_messages', 'support_threads', 'feed_comments', 'feed_post_likes', 'feed_post_views', 'feed_post_images', 'feed_posts', 'project_reports', 'project_milestones', 'project_update_photos', 'project_updates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
