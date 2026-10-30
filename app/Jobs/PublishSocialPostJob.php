<?php

namespace App\Jobs;

use App\Services\Social\SocialPublishRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one approved social post to its platforms (19_SOCIAL_CONTENT_PLANNER_PLAN.md §4, §8).
 *
 * It runs only on the dedicated `social` queue connection (own worker, retry_after far above the timeout), never on the
 * default worker: a publish can take minutes and the default 90 s retry_after would hand the same job to a second worker
 * while the first still runs, so the post could go out twice. It is unique per post, never retried by the queue
 * (`tries = 1`), and SocialPublishRunner holds a lease so a stale run cannot overwrite a newer one.
 */
class PublishSocialPostJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A failed publish is shown on the post and retried by a person, never blindly by the queue. */
    public int $tries = 1;

    public int $timeout = 900;

    /** The unique lock is held until the run is over, plus a margin. */
    public int $uniqueFor = 1800;

    public function __construct(public int $socialPostId)
    {
        $this->onConnection('social');
        $this->onQueue('social');
    }

    public function uniqueId(): string
    {
        return (string) $this->socialPostId;
    }

    public function handle(SocialPublishRunner $runner): void
    {
        $runner->run($this->socialPostId);
    }
}
