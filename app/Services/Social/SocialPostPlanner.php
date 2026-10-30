<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\SocialPostMedia;

/**
 * Looks at a post BEFORE anything is sent and says what is wrong with it
 * (19_SOCIAL_CONTENT_PLANNER_PLAN.md §8, "the planner refuses a post that breaks one of these"). It never calls a
 * platform; the picture's public address is checked later, by SocialMediaUrlResolver, right before sending.
 *
 * Phase 1 limits, stated plainly so nobody is surprised: text and ONE picture per post, to Facebook and Instagram.
 * Several pictures (a carousel), video, TikTok and YouTube come in later phases.
 */
class SocialPostPlanner
{
    /** Instagram's documented caption limit. */
    public const INSTAGRAM_CAPTION_LIMIT = 2200;

    public const FACEBOOK_TEXT_LIMIT = 63206;

    public function __construct(
        private readonly SocialSettingsService $settings,
        private readonly SocialPublisherRegistry $publishers,
    ) {}

    /**
     * What is wrong with the content itself: what a person fixes by editing the post.
     *
     * @return list<string>
     */
    public function contentProblems(SocialPost $post): array
    {
        $problems = [];
        $platforms = (array) $post->platforms;
        $media = $post->media()->get();
        $hasText = filled(trim((string) $post->body));

        if ($platforms === []) {
            $problems[] = __('Choose at least one platform.');
        }

        foreach ($platforms as $platform) {
            if (! array_key_exists($platform, SocialPost::PLATFORMS)) {
                $problems[] = __(':platform cannot be posted to yet.', ['platform' => ucfirst((string) $platform)]);
            }
        }

        if (! $hasText && $media->isEmpty()) {
            $problems[] = __('Write some text or attach a picture.');
        }

        if ($media->count() > 1) {
            $problems[] = __('Only one picture per post is supported for now.');
        }

        if ($media->contains(fn (SocialPostMedia $item): bool => $item->kind !== SocialPostMedia::KIND_IMAGE)) {
            $problems[] = __('Only pictures can be posted for now.');
        }

        if (in_array(SocialPost::PLATFORM_INSTAGRAM, $platforms, true)) {
            if ($media->isEmpty()) {
                $problems[] = __('Instagram needs a picture.');
            }

            if (mb_strlen((string) $post->body) > self::INSTAGRAM_CAPTION_LIMIT) {
                $problems[] = __('The text is too long for an Instagram caption (:max characters at most).', ['max' => self::INSTAGRAM_CAPTION_LIMIT]);
            }
        }

        if (in_array(SocialPost::PLATFORM_FACEBOOK, $platforms, true) && mb_strlen((string) $post->body) > self::FACEBOOK_TEXT_LIMIT) {
            $problems[] = __('The text is too long for a Facebook post.');
        }

        return $problems;
    }

    /**
     * Content problems plus what stops it from going out right now: the connection is off or incomplete.
     *
     * @return list<string>
     */
    public function problems(SocialPost $post): array
    {
        $problems = $this->contentProblems($post);
        $company = $post->company;

        if (! $company || ! $this->settings->isEnabled($company)) {
            $problems[] = __('Social posting is switched off for this company. Turn it on in Social → Settings.');

            return array_values(array_unique($problems));
        }

        foreach ($post->platformList() as $platform) {
            if (! $this->settings->isConfiguredFor($company, $platform)) {
                $problems[] = $platform === SocialPost::PLATFORM_INSTAGRAM
                    ? __('Instagram is not connected. Add the Instagram account id and the access token in Social → Settings.')
                    : __('Facebook is not connected. Add the Page id and the access token in Social → Settings.');

                continue;
            }

            foreach ($this->publishers->for($platform)->problems($post, $platform) as $problem) {
                $problems[] = $problem;
            }
        }

        return array_values(array_unique($problems));
    }
}
