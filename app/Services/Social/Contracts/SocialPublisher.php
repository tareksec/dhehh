<?php

namespace App\Services\Social\Contracts;

use App\Models\SocialPost;
use App\Services\Social\SocialPublishProgress;

/**
 * One way of getting a post onto platforms (19_SOCIAL_CONTENT_PLANNER_PLAN.md §8). Today only the direct
 * Meta Graph API driver exists; a PostSyncer driver can be added later (Phase 4) without touching the
 * post screen, the approval rules or the publish job.
 */
interface SocialPublisher
{
    /**
     * The platforms this driver serves.
     *
     * @return list<string>
     */
    public function platforms(): array;

    /**
     * Reasons the post cannot go to $platform, found without calling the platform. Empty means it can.
     *
     * @return list<string>
     */
    public function problems(SocialPost $post, string $platform): array;

    /**
     * Send the post to one platform, resuming from the checkpoint in $progress. The driver records every
     * step through $progress BEFORE the external call it describes, and the outcome after it. It must not
     * replay a create call whose answer was lost: that platform step is recorded as uncertain instead.
     */
    public function publish(SocialPost $post, string $platform, SocialPublishProgress $progress): void;
}
