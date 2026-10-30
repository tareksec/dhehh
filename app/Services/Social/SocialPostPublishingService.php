<?php

namespace App\Services\Social;

use App\Jobs\PublishSocialPostJob;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Schedule, publish now, retry and reconcile (19_SOCIAL_CONTENT_PLANNER_PLAN.md §5, §8). Every method checks the
 * `social.publish` permission itself, so a route or action that forgets to is still safe.
 *
 * Reconcile is the only way out of an `uncertain` platform: a person looks at the platform, then either gives the
 * platform's own post id ("it is there") or confirms it is absent ("it is not there"). Nothing is replayed before that.
 */
class SocialPostPublishingService
{
    public function __construct(
        private readonly SocialPostPlanner $planner,
        private readonly AuditLogService $audit,
    ) {}

    public function schedule(SocialPost $post, CarbonInterface $at, User $user): void
    {
        $this->authorize($user);
        $this->assertSendable($post);

        if ($at->lessThanOrEqualTo(now())) {
            throw new SocialPostException(__('Pick a time in the future.'));
        }

        $post->forceFill([
            'scheduled_at' => $at->copy()->utc(),
            'status' => SocialPost::STATUS_SCHEDULED,
            'publish_state' => SocialPost::PUBLISH_IDLE,
            'publish_error' => null,
        ])->save();

        $this->audit->record('social_post.scheduled', $post, null, [
            'number' => $post->number,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
        ]);
    }

    public function unschedule(SocialPost $post, User $user): void
    {
        $this->authorize($user);

        if ($post->status !== SocialPost::STATUS_SCHEDULED || $post->publish_state !== SocialPost::PUBLISH_IDLE) {
            throw new SocialPostException(__('Only a scheduled post that has not started can be taken off the schedule.'));
        }

        $post->forceFill(['scheduled_at' => null, 'status' => SocialPost::STATUS_READY])->save();

        $this->audit->record('social_post.unscheduled', $post, null, ['number' => $post->number]);
    }

    public function publishNow(SocialPost $post, User $user): void
    {
        $this->authorize($user);
        $this->assertSendable($post);
        $this->queue($post, $user, 'social_post.publish_now');
    }

    /** Sends again the platforms that did not go out. Refused while any platform is uncertain. */
    public function retry(SocialPost $post, User $user): void
    {
        $this->authorize($user);

        if ($post->publish_state !== SocialPost::PUBLISH_FAILED) {
            throw new SocialPostException(__('Only a post whose publishing failed can be retried.'));
        }

        if ($post->hasUncertainPlatform()) {
            throw new SocialPostException(__('A platform did not give a clear answer. Check it, then use Reconcile before retrying.'));
        }

        $this->assertSendable($post);
        $this->queue($post, $user, 'social_post.retry');
    }

    /** "I checked: it is on the platform, and this is its id." */
    public function reconcileWithId(SocialPost $post, string $platform, string $platformPostId, User $user): void
    {
        $this->authorize($user);

        $id = trim($platformPostId);

        if ($id === '' || preg_match('/\A[0-9_]{5,64}\z/', $id) !== 1) {
            throw new SocialPostException(__('Enter the post id exactly as the platform shows it (digits, and an underscore on Facebook).'));
        }

        $link = $platform === SocialPost::PLATFORM_FACEBOOK ? 'https://www.facebook.com/'.$id : null;

        $this->reconcile($post, $platform, $user, function (array &$progress, array &$result) use ($platform, $id, $link): void {
            $progress[$platform] = array_merge($progress[$platform], [
                'state' => SocialPublishProgress::STATE_SUCCEEDED,
                'completed' => 'published',
                'pending' => null,
                'post_id' => $id,
                'error' => null,
                'blocking' => false,
                'updated_at' => now()->toIso8601String(),
            ]);
            $result[$platform] = ['status' => SocialPublishProgress::STATE_SUCCEEDED, 'post_id' => $id, 'link' => $link, 'reconciled' => true, 'at' => now()->toIso8601String()];
        }, ['platform' => $platform, 'outcome' => 'found', 'platform_post_id' => $id]);
    }

    /** "I checked: it is not on the platform." The platform goes back to failed, so a retry sends it. */
    public function reconcileAbsent(SocialPost $post, string $platform, User $user): void
    {
        $this->authorize($user);

        $message = __('Confirmed by a person: it is not on the platform. It can be sent again.');

        $this->reconcile($post, $platform, $user, function (array &$progress, array &$result) use ($platform, $message): void {
            $progress[$platform] = array_merge($progress[$platform], [
                'state' => SocialPublishProgress::STATE_FAILED,
                'pending' => null,
                'error' => $message,
                'blocking' => false,
                'updated_at' => now()->toIso8601String(),
            ]);
            $result[$platform] = ['status' => SocialPublishProgress::STATE_FAILED, 'error' => $message, 'reconciled' => true, 'at' => now()->toIso8601String()];
        }, ['platform' => $platform, 'outcome' => 'absent']);
    }

    public function archive(SocialPost $post, User $user): void
    {
        if (! $user->hasPermission('social.delete')) {
            throw new SocialPostException(__('You are not allowed to archive social posts.'));
        }

        if ($post->isLocked()) {
            throw new SocialPostException(__('This post is being published, or a platform needs reconciling first. It cannot be changed now.'));
        }

        $post->forceFill(['status' => SocialPost::STATUS_ARCHIVED, 'scheduled_at' => null])->save();

        $this->audit->record('social_post.archived', $post, null, ['number' => $post->number]);
    }

    /**
     * @param  callable(array<string, array<string, mixed>>&, array<string, array<string, mixed>>&): void  $apply
     * @param  array<string, mixed>  $auditDetails
     */
    private function reconcile(SocialPost $post, string $platform, User $user, callable $apply, array $auditDetails): void
    {
        DB::transaction(function () use ($post, $platform, $user, $apply, $auditDetails): void {
            $locked = SocialPost::withoutGlobalScopes()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->publish_state, [SocialPost::PUBLISH_QUEUED, SocialPost::PUBLISH_RUNNING], true)) {
                throw new SocialPostException(__('This post is being published right now.'));
            }

            $progress = (array) $locked->publish_progress;
            $result = (array) $locked->publish_result;

            if (($progress[$platform]['state'] ?? null) !== SocialPublishProgress::STATE_UNCERTAIN) {
                throw new SocialPostException(__('That platform does not need reconciling.'));
            }

            $apply($progress, $result);

            $allDone = collect($locked->platformList())
                ->every(fn (string $name): bool => ($progress[$name]['state'] ?? null) === SocialPublishProgress::STATE_SUCCEEDED);

            $locked->forceFill([
                'publish_progress' => $progress,
                'publish_result' => $result,
                'publish_state' => $allDone ? SocialPost::PUBLISH_SUCCEEDED : SocialPost::PUBLISH_FAILED,
                'publish_error' => $allDone ? null : $locked->publish_error,
                'status' => $allDone ? SocialPost::STATUS_POSTED : $locked->status,
            ])->save();

            $this->audit->record('social_post.reconciled', $locked, null, array_merge(['number' => $locked->number, 'by' => $user->getKey()], $auditDetails));

            $post->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** What must be true before anything is sent. */
    private function assertSendable(SocialPost $post): void
    {
        if (in_array($post->publish_state, [SocialPost::PUBLISH_QUEUED, SocialPost::PUBLISH_RUNNING], true)) {
            throw new SocialPostException(__('This post is already queued or running.'));
        }

        if ($post->hasUncertainPlatform()) {
            throw new SocialPostException(__('A platform did not give a clear answer. Check it, then use Reconcile before retrying.'));
        }

        if (in_array($post->status, [SocialPost::STATUS_POSTED, SocialPost::STATUS_ARCHIVED], true)) {
            throw new SocialPostException(__('This post is already finished.'));
        }

        if (! $post->isApproved()) {
            throw new SocialPostException($post->approvalIsStale()
                ? __('The post was changed after it was approved. Approve it again.')
                : __('The post must be approved first.'));
        }

        $problems = $this->planner->problems($post);

        if ($problems !== []) {
            throw new SocialPostException(implode(' ', $problems));
        }
    }

    /** Claims the post for a worker (idle or failed -> queued) and hands it to the `social` queue. */
    private function queue(SocialPost $post, User $user, string $auditAction): void
    {
        $claimed = SocialPost::withoutGlobalScopes()
            ->whereKey($post->getKey())
            ->whereIn('publish_state', [SocialPost::PUBLISH_IDLE, SocialPost::PUBLISH_FAILED])
            ->update([
                'publish_state' => SocialPost::PUBLISH_QUEUED,
                'publish_error' => null,
                // A blocked post whose connection was fixed goes back to the line it was in.
                'status' => $post->status === SocialPost::STATUS_BLOCKED
                    ? ($post->scheduled_at ? SocialPost::STATUS_SCHEDULED : SocialPost::STATUS_READY)
                    : $post->status,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            throw new SocialPostException(__('This post is already queued or running.'));
        }

        $post->refresh();

        $this->audit->record($auditAction, $post, null, ['number' => $post->number, 'by' => $user->getKey()]);

        PublishSocialPostJob::dispatch($post->getKey());
    }

    private function authorize(User $user): void
    {
        if (! $user->hasPermission('social.publish')) {
            throw new SocialPostException(__('You are not allowed to publish social posts.'));
        }
    }
}
