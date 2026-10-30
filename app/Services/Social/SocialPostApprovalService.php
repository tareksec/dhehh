<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\User;
use App\Services\AuditLogService;

/**
 * The approval gate before anything goes live (19_SOCIAL_CONTENT_PLANNER_PLAN.md §1, §2.4). A user with
 * `social.approve` may approve their own post (owner decision 2026-10-27), so every approval is written to the audit
 * log with who approved what and when. An approval covers one exact version of the content: any later change voids it
 * (SocialPost::isApproved()).
 */
class SocialPostApprovalService
{
    public function __construct(
        private readonly SocialPostPlanner $planner,
        private readonly AuditLogService $audit,
    ) {}

    /** Draft -> waiting for approval. Only a post that could actually be sent is submitted. */
    public function submit(SocialPost $post, User $user): void
    {
        $this->assertEditable($post);

        if (! in_array($post->status, [SocialPost::STATUS_DRAFT, SocialPost::STATUS_BLOCKED], true)) {
            throw new SocialPostException(__('Only a draft can be sent for approval.'));
        }

        $problems = $this->planner->contentProblems($post);

        if ($problems !== []) {
            throw new SocialPostException(implode(' ', $problems));
        }

        $post->forceFill([
            'status' => SocialPost::STATUS_READY,
            'approval_state' => SocialPost::APPROVAL_PENDING,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'approved_fingerprint' => null,
        ])->save();

        $this->audit->record('social_post.submitted', $post, null, ['number' => $post->number]);
    }

    public function approve(SocialPost $post, User $user): void
    {
        if (! $user->hasPermission('social.approve')) {
            throw new SocialPostException(__('You are not allowed to approve social posts.'));
        }

        $this->assertEditable($post);

        if (! in_array($post->status, [SocialPost::STATUS_READY, SocialPost::STATUS_SCHEDULED], true)) {
            throw new SocialPostException(__('Send the post for approval first.'));
        }

        $problems = $this->planner->contentProblems($post);

        if ($problems !== []) {
            throw new SocialPostException(implode(' ', $problems));
        }

        $fingerprint = $post->contentFingerprint();

        $post->forceFill([
            'approval_state' => SocialPost::APPROVAL_APPROVED,
            'approved_by_user_id' => $user->getKey(),
            'approved_at' => now(),
            'approved_fingerprint' => $fingerprint,
        ])->save();

        $this->audit->record('social_post.approved', $post, null, [
            'number' => $post->number,
            'approved_by' => $user->getKey(),
            'own_post' => $post->created_by_user_id === $user->getKey(),
            'fingerprint' => $fingerprint,
        ]);
    }

    /** Takes the approval back. A scheduled post goes back to waiting; nothing is sent. */
    public function revoke(SocialPost $post, User $user): void
    {
        if (! $user->hasPermission('social.approve')) {
            throw new SocialPostException(__('You are not allowed to approve social posts.'));
        }

        $this->assertEditable($post);

        $post->forceFill([
            'approval_state' => SocialPost::APPROVAL_PENDING,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'approved_fingerprint' => null,
            'status' => $post->status === SocialPost::STATUS_SCHEDULED ? SocialPost::STATUS_READY : $post->status,
        ])->save();

        $this->audit->record('social_post.approval_revoked', $post, null, ['number' => $post->number, 'by' => $user->getKey()]);
    }

    private function assertEditable(SocialPost $post): void
    {
        if ($post->isLocked()) {
            throw new SocialPostException(__('This post is being published, or a platform needs reconciling first. It cannot be changed now.'));
        }

        if (in_array($post->status, [SocialPost::STATUS_POSTED, SocialPost::STATUS_ARCHIVED], true)) {
            throw new SocialPostException(__('This post is already finished.'));
        }
    }
}
