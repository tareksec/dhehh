<?php

namespace App\Services\Social\Meta;

use App\Models\SocialPost;
use App\Models\SocialPostMedia;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaGraphService;
use App\Services\Social\Contracts\SocialPublisher;
use App\Services\Social\SocialMediaException;
use App\Services\Social\SocialMediaUrlResolver;
use App\Services\Social\SocialPublishProgress;
use App\Services\Social\SocialSettingsService;

/**
 * Facebook Page and Instagram through the Meta Graph API with the company's own Social token
 * (19_SOCIAL_CONTENT_PLANNER_PLAN.md §8).
 *
 * The rule that keeps it safe: before each call that creates something, the step is written to the checkpoint;
 * if the answer to that call is lost, the step becomes `uncertain` and is NEVER replayed by this class. A person
 * checks the platform and reconciles.
 *
 * Facebook is one call. Instagram is two: create a media container, wait until Meta has processed it, then publish
 * it. A retry after a clear failure resumes from the last step that worked.
 */
class MetaPagePublisher implements SocialPublisher
{
    public function __construct(
        private readonly MetaGraphService $graph,
        private readonly SocialSettingsService $settings,
        private readonly SocialMediaUrlResolver $media,
    ) {}

    public function platforms(): array
    {
        return [SocialPost::PLATFORM_FACEBOOK, SocialPost::PLATFORM_INSTAGRAM];
    }

    public function problems(SocialPost $post, string $platform): array
    {
        return [];
    }

    public function publish(SocialPost $post, string $platform, SocialPublishProgress $progress): void
    {
        match ($platform) {
            SocialPost::PLATFORM_FACEBOOK => $this->publishFacebook($post, $progress),
            SocialPost::PLATFORM_INSTAGRAM => $this->publishInstagram($post, $progress),
            default => $progress->fail($platform, __(':platform cannot be posted to yet.', ['platform' => ucfirst($platform)])),
        };
    }

    private function publishFacebook(SocialPost $post, SocialPublishProgress $progress): void
    {
        $platform = SocialPost::PLATFORM_FACEBOOK;
        $settings = $this->settings->all($post->company);
        $image = $post->media()->get()->first();
        $imageUrl = null;

        if ($image instanceof SocialPostMedia) {
            try {
                $imageUrl = $this->media->resolve($image);
            } catch (SocialMediaException $exception) {
                $progress->fail($platform, $exception->getMessage());

                return;
            }
        }

        $progress->begin($platform, 'publish');

        try {
            $postId = $imageUrl !== null
                ? $this->graph->createPagePhotoPost($settings['page_id'], (string) $settings['page_access_token'], $imageUrl, (string) $post->body)
                : $this->graph->createPagePost($settings['page_id'], (string) $settings['page_access_token'], (string) $post->body);
        } catch (MetaGraphException $exception) {
            $this->recordCreateFailure($progress, $platform, $exception);

            return;
        }

        $this->settings->markSuccess($post->company);
        $progress->succeed($platform, $postId, 'https://www.facebook.com/'.$postId);
    }

    private function publishInstagram(SocialPost $post, SocialPublishProgress $progress): void
    {
        $platform = SocialPost::PLATFORM_INSTAGRAM;
        $settings = $this->settings->all($post->company);
        $token = (string) $settings['page_access_token'];
        $accountId = $settings['instagram_account_id'];
        $state = $progress->get($platform);
        $containerId = filled($state['container_id'] ?? null) && ($state['completed'] ?? null) === 'container'
            ? (string) $state['container_id']
            : null;

        if ($containerId === null) {
            $image = $post->media()->get()->first();

            if (! $image instanceof SocialPostMedia) {
                $progress->fail($platform, __('Instagram needs a picture.'));

                return;
            }

            try {
                $imageUrl = $this->media->resolve($image, jpegOnly: true);
            } catch (SocialMediaException $exception) {
                $progress->fail($platform, $exception->getMessage());

                return;
            }

            $progress->begin($platform, 'container');

            try {
                $containerId = $this->graph->createInstagramImageContainer($accountId, $token, $imageUrl, (string) $post->body);
            } catch (MetaGraphException $exception) {
                $this->recordCreateFailure($progress, $platform, $exception);

                return;
            }

            $progress->completed($platform, 'container', ['container_id' => $containerId]);
        }

        if (! $this->waitUntilProcessed($progress, $platform, $containerId, $token)) {
            return;
        }

        $progress->begin($platform, 'publish');

        try {
            $mediaId = $this->graph->publishInstagramContainer($accountId, $token, $containerId);
        } catch (MetaGraphException $exception) {
            $this->recordCreateFailure($progress, $platform, $exception);

            return;
        }

        $this->settings->markSuccess($post->company);
        $progress->succeed($platform, $mediaId, $this->graph->instagramPermalink($mediaId, $token));
    }

    /**
     * Meta processes the container on its side; publishing before it is FINISHED fails. Reading the status is
     * safe to repeat. Returns false when the run has to stop (the failure is already recorded).
     */
    private function waitUntilProcessed(SocialPublishProgress $progress, string $platform, string $containerId, string $token): bool
    {
        $attempts = max(1, (int) config('services.social.instagram_poll_attempts', 10));
        $pause = max(0, (int) config('services.social.instagram_poll_seconds', 3));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $status = $this->graph->instagramContainerStatus($containerId, $token);
            } catch (MetaGraphException $exception) {
                $progress->fail($platform, $exception->getMessage(), $exception->blocksPosting());

                return false;
            }

            if ($status === 'FINISHED') {
                return true;
            }

            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                // This container is no use any more: a retry makes a new one.
                $progress->fail(
                    $platform,
                    $status === 'EXPIRED'
                        ? __('Instagram dropped the prepared picture because it waited too long. Try again.')
                        : __('Instagram could not process the picture. Check the picture and try again.'),
                    false,
                    ['completed' => null, 'container_id' => null],
                );

                return false;
            }

            if ($status === 'PUBLISHED') {
                $progress->uncertain($platform, __('Instagram says this post is already published. Check the account, then reconcile.'));

                return false;
            }

            if ($attempt < $attempts && $pause > 0) {
                sleep($pause);
            }
        }

        // Still being processed. The container is kept, so a retry only waits again.
        $progress->fail($platform, __('Instagram is still processing the picture. Try again in a few minutes.'));

        return false;
    }

    private function recordCreateFailure(SocialPublishProgress $progress, string $platform, MetaGraphException $exception): void
    {
        if ($exception->outcomeIsUnknown()) {
            $progress->uncertain($platform, __('No clear answer came back from the platform, so it is not known whether the post went out. Check the account, then reconcile.'));

            return;
        }

        $progress->fail($platform, $exception->getMessage(), $exception->blocksPosting());
    }
}
