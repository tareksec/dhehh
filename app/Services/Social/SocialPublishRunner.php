<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one queued post to the platforms (19_SOCIAL_CONTENT_PLANNER_PLAN.md §8). The queue job is a thin wrapper
 * around run() so the whole thing is testable without a worker.
 *
 * The rules, in order:
 *  1. The run claims the post with an atomic `queued -> running` update and a lease token; a second run of the same
 *     post finds nothing to claim and stops.
 *  2. Nothing goes out unless the post is approved and still matches what was approved.
 *  3. Each platform is independent. A platform that already succeeded is skipped, one that is `uncertain` is NEVER
 *     replayed (a person reconciles it), the rest are sent.
 *  4. Partial success is kept per platform, and a retry only sends the platforms that did not go out.
 *  5. A bad token or a missing permission blocks the post; it is not retried.
 */
class SocialPublishRunner
{
    /** How long a run may hold a post. The job timeout is 15 minutes. */
    public const LEASE_MINUTES = 20;

    public function __construct(
        private readonly CompanyContext $context,
        private readonly SocialPostPlanner $planner,
        private readonly SocialPublisherRegistry $publishers,
        private readonly AuditLogService $audit,
    ) {}

    public function run(int $postId): void
    {
        $post = SocialPost::withoutGlobalScopes()->with('company')->find($postId);

        if (! $post || ! $post->company) {
            return;
        }

        $this->context->set($post->company);

        try {
            $token = $this->claim($post);

            if ($token === null) {
                return;
            }

            $post->refresh();
            $progress = new SocialPublishProgress($post, $token);

            try {
                $this->publish($post, $progress, $token);
            } catch (SocialLeaseLostException) {
                // A newer run owns the post; leave everything to it.
            } catch (Throwable $exception) {
                report($exception);
                $this->abandon($post, $token, __('Publishing stopped because of an unexpected error. Check each platform, then reconcile or retry.'));
            }
        } finally {
            $this->context->clear();
        }
    }

    /**
     * Posts a run started and never finished (the worker was stopped mid-way). What was in flight is not known, so
     * each platform that was mid-call becomes `uncertain` and a person reconciles it. Returns how many were recovered.
     */
    public function recoverStale(): int
    {
        $stale = SocialPost::withoutGlobalScopes()
            ->where('publish_state', SocialPost::PUBLISH_RUNNING)
            ->where('lease_expires_at', '<', now())
            ->get();

        foreach ($stale as $post) {
            $this->abandon(
                $post,
                (string) $post->lease_token,
                __('The publishing worker stopped before it finished. Check each platform, then reconcile or retry.'),
            );
        }

        return $stale->count();
    }

    private function claim(SocialPost $post): ?string
    {
        $token = Str::random(40);

        $claimed = SocialPost::withoutGlobalScopes()
            ->whereKey($post->getKey())
            ->where('publish_state', SocialPost::PUBLISH_QUEUED)
            ->update([
                'publish_state' => SocialPost::PUBLISH_RUNNING,
                'lease_token' => $token,
                'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES),
                'publish_error' => null,
                'updated_at' => now(),
            ]);

        return $claimed === 1 ? $token : null;
    }

    private function publish(SocialPost $post, SocialPublishProgress $progress, string $token): void
    {
        if (! $post->isApproved()) {
            $this->finish($post, $token, SocialPost::PUBLISH_FAILED, null, $post->approvalIsStale()
                ? __('The post was changed after it was approved. Approve it again.')
                : __('The post is not approved.'));

            return;
        }

        $problems = $this->planner->problems($post);

        if ($problems !== []) {
            $this->finish($post, $token, SocialPost::PUBLISH_FAILED, null, implode(' ', $problems));

            return;
        }

        foreach ($post->platformList() as $platform) {
            $state = $progress->state($platform);

            if ($state === SocialPublishProgress::STATE_SUCCEEDED) {
                continue;
            }

            if ($state === SocialPublishProgress::STATE_UNCERTAIN) {
                // Never replayed: a person has to check the platform and reconcile first.
                continue;
            }

            $this->publishers->for($platform)->publish($post, $platform, $progress);
        }

        $this->conclude($post, $progress, $token);
    }

    private function conclude(SocialPost $post, SocialPublishProgress $progress, string $token): void
    {
        $platforms = $post->platformList();
        $states = collect($platforms)->mapWithKeys(fn (string $platform): array => [$platform => $progress->get($platform)]);

        if ($states->every(fn (array $state): bool => $state['state'] === SocialPublishProgress::STATE_SUCCEEDED)) {
            $this->finish($post, $token, SocialPost::PUBLISH_SUCCEEDED, SocialPost::STATUS_POSTED, null);

            return;
        }

        $failed = $states->filter(fn (array $state): bool => $state['state'] === SocialPublishProgress::STATE_FAILED);

        $messages = $states
            ->reject(fn (array $state): bool => $state['state'] === SocialPublishProgress::STATE_SUCCEEDED)
            ->map(fn (array $state, string $platform): string => SocialPost::PLATFORMS[$platform].': '.($state['error'] ?: __('Not sent.')))
            ->values()
            ->implode(' ');

        // A bad token or a missing permission is a problem for a person to fix, not something to retry.
        $blocked = $failed->contains(fn (array $state): bool => (bool) $state['blocking']);

        $this->finish($post, $token, SocialPost::PUBLISH_FAILED, $blocked ? SocialPost::STATUS_BLOCKED : null, $messages);
    }

    /** Ends the run: sets the outcome and releases the lease. A run that lost its lease writes nothing. */
    private function finish(SocialPost $post, string $token, string $publishState, ?string $status, ?string $error): void
    {
        $changes = [
            'publish_state' => $publishState,
            'publish_error' => $error !== null ? Str::limit($error, 1000, '') : null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'updated_at' => now(),
        ];

        if ($status !== null) {
            $changes['status'] = $status;
        }

        $written = SocialPost::withoutGlobalScopes()
            ->whereKey($post->getKey())
            ->where('lease_token', $token)
            ->update($changes);

        if ($written === 1) {
            $this->audit->record('social_post.publish_'.$publishState, $post, null, [
                'number' => $post->number,
                'platforms' => $post->platformList(),
                'status' => $status ?? $post->status,
            ]);
        }
    }

    /** A run ended in a way nobody can describe: whatever was mid-call is uncertain, the rest simply failed. */
    private function abandon(SocialPost $post, string $token, string $message): void
    {
        $fresh = SocialPost::withoutGlobalScopes()->find($post->getKey());

        if (! $fresh || $fresh->lease_token !== $token) {
            return;
        }

        $progress = (array) $fresh->publish_progress;
        $result = (array) $fresh->publish_result;

        foreach ($progress as $platform => $state) {
            if (($state['state'] ?? null) === SocialPublishProgress::STATE_RUNNING) {
                $progress[$platform]['state'] = SocialPublishProgress::STATE_UNCERTAIN;
                $progress[$platform]['error'] = $message;
                $result[$platform] = ['status' => SocialPublishProgress::STATE_UNCERTAIN, 'error' => $message, 'at' => now()->toIso8601String()];
            }
        }

        SocialPost::withoutGlobalScopes()
            ->whereKey($fresh->getKey())
            ->where('lease_token', $token)
            ->update([
                'publish_progress' => json_encode($progress, JSON_UNESCAPED_UNICODE),
                'publish_result' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'publish_state' => SocialPost::PUBLISH_FAILED,
                'publish_error' => Str::limit($message, 1000, ''),
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        $this->audit->record('social_post.publish_abandoned', $fresh, null, ['number' => $fresh->number]);
    }
}
