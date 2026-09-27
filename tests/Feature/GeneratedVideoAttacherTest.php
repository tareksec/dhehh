<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\GeneratedVideo;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\VideoGeneration\GeneratedVideoAttacher;
use App\Services\VideoGeneration\VideoAttachmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneratedVideoAttacherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Clip Co');
        app(CompanyContext::class)->set($this->company);

        $this->user = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $this->user->companies()->syncWithoutDetaching([
            $this->company->getKey() => ['role' => 'manager', 'is_default' => true],
        ]);
    }

    private function makeCompany(string $name): Company
    {
        return Company::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'invoice_prefix' => 'CLP'.random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
    }

    private function videoPath(): string
    {
        return 'companies/'.$this->company->storage_key.'/public/ai-generated-videos/clip.mp4';
    }

    private function completedVideo(array $overrides = []): GeneratedVideo
    {
        return GeneratedVideo::query()->create(array_merge([
            'user_id' => $this->user->getKey(),
            'tool' => GeneratedVideo::TOOL_VIDEO_GENERATION,
            'context' => 'product_demo',
            'prompt' => 'slow push in on the bottle',
            'source_image_path' => 'companies/'.$this->company->storage_key.'/public/ai-video-source-images/bottle.webp',
            'provider_profile_id' => 'p1',
            'provider_label' => 'Stability',
            'api_format' => 'stability',
            'aspect_ratio' => '16:9',
            'video_path' => $this->videoPath(),
            'video_mime_type' => 'video/mp4',
            'status' => GeneratedVideo::STATUS_COMPLETED,
            'generated_at' => now(),
        ], $overrides));
    }

    private function product(): Product
    {
        return Product::query()->create([
            'name' => 'Steel Bottle',
            'sku' => 'SB-'.random_int(100, 999),
            'price' => 900,
            'sale_price' => 900,
            'stock' => 0,
        ]);
    }

    public function test_it_sets_the_product_video_and_links_the_generation(): void
    {
        $video = $this->completedVideo();
        $product = $this->product();

        app(GeneratedVideoAttacher::class)->attachToProduct($video, $product);

        $this->assertSame($this->videoPath(), $product->fresh()->video_path);

        $video->refresh();
        $this->assertSame(Product::class, $video->linked_type);
        $this->assertSame($product->getKey(), $video->linked_id);
        $this->assertSame('Steel Bottle', $video->linkedLabel());
    }

    public function test_it_sets_the_offer_hero_video_and_keeps_the_cover_image(): void
    {
        $video = $this->completedVideo(['context' => 'landing_hero']);
        $offer = Offer::query()->create(['type' => Offer::TYPE_SINGLE, 'title' => 'Winter Combo', 'cover_image' => 'cover.webp']);

        app(GeneratedVideoAttacher::class)->attachToOffer($video, $offer);

        $offer->refresh();
        $this->assertSame($this->videoPath(), $offer->video_path);
        $this->assertSame('cover.webp', $offer->cover_image);

        $video->refresh();
        $this->assertSame(Offer::class, $video->linked_type);
        $this->assertSame($offer->getKey(), $video->linked_id);
    }

    public function test_it_rejects_an_unfinished_video(): void
    {
        $video = $this->completedVideo(['status' => GeneratedVideo::STATUS_PROCESSING, 'video_path' => null]);

        $this->expectException(VideoAttachmentException::class);
        $this->expectExceptionMessage('has not finished yet');

        app(GeneratedVideoAttacher::class)->attachToProduct($video, $this->product());
    }

    public function test_it_rejects_a_completed_row_whose_clip_is_missing(): void
    {
        $video = $this->completedVideo(['video_path' => null]);

        $this->expectException(VideoAttachmentException::class);
        $this->expectExceptionMessage('no longer available');

        app(GeneratedVideoAttacher::class)->attachToProduct($video, $this->product());
    }

    public function test_it_rejects_a_video_awaiting_review(): void
    {
        $video = $this->completedVideo(['review_status' => GeneratedVideo::REVIEW_PENDING]);

        $this->expectException(VideoAttachmentException::class);
        $this->expectExceptionMessage('waiting for a reviewer');

        app(GeneratedVideoAttacher::class)->attachToProduct($video, $this->product());
    }

    public function test_it_rejects_a_rejected_video(): void
    {
        $video = $this->completedVideo(['review_status' => GeneratedVideo::REVIEW_REJECTED]);

        $this->expectException(VideoAttachmentException::class);
        $this->expectExceptionMessage('rejected this video');

        app(GeneratedVideoAttacher::class)->attachToOffer(
            $video,
            Offer::query()->create(['type' => Offer::TYPE_SINGLE, 'title' => 'Held Back']),
        );
    }

    public function test_it_allows_an_approved_video(): void
    {
        $video = $this->completedVideo(['review_status' => GeneratedVideo::REVIEW_APPROVED]);
        $product = $this->product();

        app(GeneratedVideoAttacher::class)->attachToProduct($video, $product);

        $this->assertSame($this->videoPath(), $product->fresh()->video_path);
    }

    public function test_it_refuses_a_record_from_another_company(): void
    {
        $video = $this->completedVideo();

        $other = $this->makeCompany('Other Co');
        app(CompanyContext::class)->set($other);
        $foreignProduct = $this->product();
        app(CompanyContext::class)->set($this->company);

        $this->expectException(VideoAttachmentException::class);
        $this->expectExceptionMessage('different company');

        app(GeneratedVideoAttacher::class)->attachToProduct($video, $foreignProduct);
    }
}
