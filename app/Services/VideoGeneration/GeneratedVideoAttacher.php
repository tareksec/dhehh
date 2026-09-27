<?php

namespace App\Services\VideoGeneration;

use App\Models\GeneratedVideo;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * 12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md §7.5 / §11 — attaches a finished
 * GeneratedVideo to a real record:
 *
 * - Product            → `products.video_path` (the product page video block)
 * - Offer / landing    → `offers.video_path` (plays in the offer hero slot)
 *
 * Simpler than GeneratedImageAttacher: a video row has exactly one output,
 * so there is no output index and no gallery slot. The stored clip is
 * already in the company-scoped public shape, so an attach is a straight
 * path write — never a browser upload (plan §6.4). Each attach also stamps
 * the target on the generation's `linked` morph.
 */
class GeneratedVideoAttacher
{
    public function attachToProduct(GeneratedVideo $video, Product $product): void
    {
        $path = $this->videoPath($video);
        $this->assertSameCompany($video, $product);

        $product->forceFill(['video_path' => $path])->save();

        $this->link($video, $product);
    }

    public function attachToOffer(GeneratedVideo $video, Offer $offer): void
    {
        $path = $this->videoPath($video);
        $this->assertSameCompany($video, $offer);

        $offer->forceFill(['video_path' => $path])->save();

        $this->link($video, $offer);
    }

    protected function videoPath(GeneratedVideo $video): string
    {
        if ($video->status !== GeneratedVideo::STATUS_COMPLETED) {
            throw new VideoAttachmentException(__('That video has not finished yet.'));
        }

        if (! $video->isAttachable()) {
            throw new VideoAttachmentException($video->review_status === GeneratedVideo::REVIEW_REJECTED
                ? __('A reviewer rejected this video, so it cannot be attached.')
                : __('This video is waiting for a reviewer to approve it before it can be attached.'));
        }

        if (blank($video->video_path)) {
            throw new VideoAttachmentException(__('That video is no longer available.'));
        }

        return (string) $video->video_path;
    }

    protected function assertSameCompany(GeneratedVideo $video, Model $target): void
    {
        if ((int) $video->company_id !== (int) $target->getAttribute('company_id')) {
            throw new VideoAttachmentException(__('That record belongs to a different company.'));
        }
    }

    protected function link(GeneratedVideo $video, Model $target): void
    {
        $video->linked()->associate($target);
        $video->save();
    }
}
