<?php

namespace App\Console\Commands;

use App\Jobs\PublishSocialPostJob;
use App\Models\Company;
use App\Models\SocialPost;
use App\Services\CompanyContext;
use App\Services\Social\SocialPublishRunner;
use App\Services\Social\SocialSettingsService;
use Illuminate\Console\Command;

/**
 * Every minute: queues the approved social posts whose time has come (19_SOCIAL_CONTENT_PLANNER_PLAN.md §4).
 * The ERP schedules, not the platform, so every platform behaves the same and a schedule can be changed until the
 * moment the post is queued. Per-company loop with its own CompanyContext, like the other scheduled commands.
 *
 * It also recovers runs that were left half-way by a stopped worker: those platforms become `uncertain` and wait for
 * a person to reconcile them.
 */
class PublishDueSocialPosts extends Command
{
    protected $signature = 'social:publish-due';

    protected $description = 'Queue the approved social posts that are due, and recover runs a stopped worker left behind';

    public function handle(CompanyContext $context, SocialSettingsService $settings, SocialPublishRunner $runner): int
    {
        $recovered = $runner->recoverStale();
        $queued = 0;

        Company::query()->where('is_active', true)->get()->each(function (Company $company) use ($context, $settings, &$queued): void {
            if (! $settings->isEnabled($company)) {
                return;
            }

            $context->set($company);

            try {
                SocialPost::query()
                    ->where('status', SocialPost::STATUS_SCHEDULED)
                    ->where('publish_state', SocialPost::PUBLISH_IDLE)
                    ->where('scheduled_at', '<=', now())
                    ->orderBy('scheduled_at')
                    ->limit(50)
                    ->get()
                    ->each(function (SocialPost $post) use (&$queued): void {
                        if ($this->queueOne($post)) {
                            $queued++;
                        }
                    });
            } finally {
                $context->clear();
            }
        });

        $this->info("Social posts queued: {$queued}; stale runs recovered: {$recovered}.");

        return self::SUCCESS;
    }

    private function queueOne(SocialPost $post): bool
    {
        // Approved, and still exactly what was approved. If not, it is flagged on the post and left alone.
        if (! $post->isApproved()) {
            SocialPost::withoutGlobalScopes()
                ->whereKey($post->getKey())
                ->where('publish_state', SocialPost::PUBLISH_IDLE)
                ->update([
                    'publish_state' => SocialPost::PUBLISH_FAILED,
                    'publish_error' => $post->approvalIsStale()
                        ? __('The post was changed after it was approved. Approve it again.')
                        : __('The post is not approved.'),
                    'updated_at' => now(),
                ]);

            return false;
        }

        $claimed = SocialPost::withoutGlobalScopes()
            ->whereKey($post->getKey())
            ->where('publish_state', SocialPost::PUBLISH_IDLE)
            ->update(['publish_state' => SocialPost::PUBLISH_QUEUED, 'publish_error' => null, 'updated_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        PublishSocialPostJob::dispatch($post->getKey());

        return true;
    }
}
