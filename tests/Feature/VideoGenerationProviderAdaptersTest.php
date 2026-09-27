<?php

namespace Tests\Feature;

use App\Services\VideoGeneration\Providers\CustomVideoProvider;
use App\Services\VideoGeneration\Providers\StabilityVideoProvider;
use App\Services\VideoGeneration\VideoGenerationException;
use App\Services\VideoGeneration\VideoGenerationRequest;
use App\Services\VideoGeneration\VideoPollResult;
use App\Services\VideoGeneration\VideoProviderResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VideoGenerationProviderAdaptersTest extends TestCase
{
    private function pngBytes(int $w = 24, int $h = 24): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 12, 34, 56));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function request(array $overrides = []): VideoGenerationRequest
    {
        $get = fn (string $key, mixed $default): mixed => array_key_exists($key, $overrides) ? $overrides[$key] : $default;

        return new VideoGenerationRequest(
            prompt: $get('prompt', 'slow push in on the product'),
            model: $get('model', null),
            apiKey: $get('apiKey', 'sk-test'),
            baseUrl: $get('baseUrl', null),
            pollUrlTemplate: $get('pollUrlTemplate', null),
            sourceImage: $get('sourceImage', $this->pngBytes()),
            aspectRatio: $get('aspectRatio', '16:9'),
            durationPreset: $get('durationPreset', 'standard'),
            operation: $get('operation', VideoGenerationRequest::OP_IMAGE_TO_VIDEO),
        );
    }

    /** @return resource */
    private function sink()
    {
        return tmpfile();
    }

    public function test_stability_submit_returns_the_job_id(): void
    {
        Http::fake(['api.stability.ai/v2beta/image-to-video' => Http::response(['id' => 'job-123'], 200)]);

        $result = app(StabilityVideoProvider::class)->submit($this->request());

        $this->assertSame('job-123', $result->externalJobId);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sk-test')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'image'));
    }

    public function test_stability_submit_requires_an_api_key(): void
    {
        Http::fake();

        $this->expectException(VideoGenerationException::class);
        $this->expectExceptionMessageMatches('/needs an API key/');

        app(StabilityVideoProvider::class)->submit($this->request(['apiKey' => null]));
    }

    public function test_stability_submit_fails_cleanly_without_a_job_id(): void
    {
        Http::fake(['api.stability.ai/*' => Http::response([], 200)]);

        $this->expectException(VideoGenerationException::class);
        $this->expectExceptionMessageMatches('/did not return a job id/');

        app(StabilityVideoProvider::class)->submit($this->request());
    }

    public function test_stability_poll_reports_pending_on_202(): void
    {
        Http::fake(['api.stability.ai/v2beta/image-to-video/result/*' => Http::response('', 202)]);

        $result = app(StabilityVideoProvider::class)->poll($this->request(), 'job-123', $this->sink());

        $this->assertTrue($result->isPending());
    }

    public function test_stability_poll_streams_the_finished_video_into_the_sink_without_buffering_it(): void
    {
        $video = random_bytes(2048);
        Http::fake(['api.stability.ai/v2beta/image-to-video/result/*' => Http::response($video, 200)]);

        $sink = $this->sink();
        $result = app(StabilityVideoProvider::class)->poll($this->request(), 'job-123', $sink);

        $this->assertTrue($result->isCompleted());
        $this->assertSame(strlen($video), $result->videoSizeBytes);
        $this->assertSame('video/mp4', $result->videoMimeType);

        rewind($sink);
        $this->assertSame($video, stream_get_contents($sink));
    }

    public function test_stability_poll_turns_an_http_error_into_a_readable_exception(): void
    {
        Http::fake(['api.stability.ai/v2beta/image-to-video/result/*' => Http::response(['errors' => ['bad job id']], 404)]);

        $this->expectException(VideoGenerationException::class);
        $this->expectExceptionMessageMatches('/bad job id/');

        app(StabilityVideoProvider::class)->poll($this->request(), 'job-123', $this->sink());
    }

    public function test_custom_provider_submit_posts_to_the_base_url(): void
    {
        Http::fake(['self-hosted.local/*' => Http::response(['id' => 'ext-9'], 200)]);

        $result = app(CustomVideoProvider::class)->submit($this->request([
            'apiKey' => null,
            'baseUrl' => 'https://self-hosted.local/videos',
        ]));

        $this->assertSame('ext-9', $result->externalJobId);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://self-hosted.local/videos');
    }

    public function test_custom_provider_submit_requires_a_base_url(): void
    {
        Http::fake();

        $this->expectException(VideoGenerationException::class);
        $this->expectExceptionMessageMatches('/needs a Base URL/');

        app(CustomVideoProvider::class)->submit($this->request(['baseUrl' => null]));
    }

    public function test_custom_provider_poll_requires_a_poll_url_template(): void
    {
        Http::fake();

        $this->expectException(VideoGenerationException::class);
        $this->expectExceptionMessageMatches('/needs a Poll URL template/');

        app(CustomVideoProvider::class)->poll(
            $this->request(['pollUrlTemplate' => null]),
            'ext-9',
            $this->sink(),
        );
    }

    public function test_custom_provider_poll_accepts_a_direct_binary_response(): void
    {
        $video = random_bytes(1024);
        Http::fake(['self-hosted.local/jobs/ext-9' => Http::response($video, 200, ['Content-Type' => 'video/mp4'])]);

        $sink = $this->sink();
        $result = app(CustomVideoProvider::class)->poll(
            $this->request(['pollUrlTemplate' => 'https://self-hosted.local/jobs/{job_id}']),
            'ext-9',
            $sink,
        );

        $this->assertTrue($result->isCompleted());
        $this->assertSame(strlen($video), $result->videoSizeBytes);

        rewind($sink);
        $this->assertSame($video, stream_get_contents($sink));
    }

    public function test_custom_provider_poll_reports_pending_from_a_json_status_body(): void
    {
        Http::fake(['self-hosted.local/jobs/ext-9' => Http::response(
            ['status' => 'processing'],
            200,
            ['Content-Type' => 'application/json'],
        )]);

        $result = app(CustomVideoProvider::class)->poll(
            $this->request(['pollUrlTemplate' => 'https://self-hosted.local/jobs/{job_id}']),
            'ext-9',
            $this->sink(),
        );

        $this->assertTrue($result->isPending());
    }

    public function test_custom_provider_poll_downloads_a_video_url_from_a_json_body(): void
    {
        $video = random_bytes(1024);
        Http::fake([
            'self-hosted.local/jobs/ext-9' => Http::response(
                ['status' => 'complete', 'video_url' => 'https://cdn.example/clip.mp4'],
                200,
                ['Content-Type' => 'application/json'],
            ),
            'cdn.example/clip.mp4' => Http::response($video, 200, ['Content-Type' => 'video/mp4']),
        ]);

        $sink = $this->sink();
        $result = app(CustomVideoProvider::class)->poll(
            $this->request(['pollUrlTemplate' => 'https://self-hosted.local/jobs/{job_id}']),
            'ext-9',
            $sink,
        );

        $this->assertTrue($result->isCompleted());
        $this->assertSame(strlen($video), $result->videoSizeBytes);

        rewind($sink);
        $this->assertSame($video, stream_get_contents($sink));
    }

    public function test_custom_provider_poll_reports_failure_from_a_json_error_body(): void
    {
        Http::fake(['self-hosted.local/jobs/ext-9' => Http::response(
            ['error' => ['message' => 'the source image was rejected']],
            200,
            ['Content-Type' => 'application/json'],
        )]);

        $result = app(CustomVideoProvider::class)->poll(
            $this->request(['pollUrlTemplate' => 'https://self-hosted.local/jobs/{job_id}']),
            'ext-9',
            $this->sink(),
        );

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('rejected', (string) $result->errorMessage);
    }

    public function test_resolver_maps_formats_to_adapters(): void
    {
        $resolver = app(VideoProviderResolver::class);

        $this->assertInstanceOf(StabilityVideoProvider::class, $resolver->byFormat('stability'));
        $this->assertInstanceOf(CustomVideoProvider::class, $resolver->byFormat('custom'));
        $this->assertFalse($resolver->supports('runway'));

        $this->expectException(VideoGenerationException::class);
        $resolver->byFormat('runway');
    }

    public function test_every_adapter_supports_image_to_video(): void
    {
        $resolver = app(VideoProviderResolver::class);

        $this->assertEqualsCanonicalizing(
            ['stability', 'custom'],
            $resolver->formatsSupporting(VideoGenerationRequest::OP_IMAGE_TO_VIDEO),
        );
    }

    public function test_video_poll_result_named_constructors(): void
    {
        $this->assertTrue(VideoPollResult::pending()->isPending());
        $this->assertTrue(VideoPollResult::completed('video/mp4', 10)->isCompleted());
        $this->assertTrue(VideoPollResult::failed('nope')->isFailed());
    }
}
