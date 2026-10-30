<?php

namespace Tests\Feature;

use App\Jobs\PublishSocialPostJob;
use App\Jobs\SocialQueueHeartbeatJob;
use App\Models\Company;
use App\Models\SocialPost;
use App\Models\SocialPostMedia;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Social\SocialPostApprovalService;
use App\Services\Social\SocialPostException;
use App\Services\Social\SocialPostPublishingService;
use App\Services\Social\SocialPublishRunner;
use App\Services\Social\SocialSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The publish rules of 19_SOCIAL_CONTENT_PLANNER_PLAN.md §8 and the tests the plan lists in §11. Meta is always faked.
 */
class SocialPostPublishingTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAAB-social-token-value-1234567890';

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'app.url' => 'https://erp.example.test',
            'services.social.instagram_poll_seconds' => 0,
            'services.social.instagram_poll_attempts' => 3,
        ]);

        $this->company = $this->makeCompany('Social One', 'social-one', '111111', '222222');
        app(CompanyContext::class)->set($this->company);
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    // ---- helpers --------------------------------------------------------------------------------------------

    private function makeCompany(string $name, string $slug, string $pageId, string $instagramId, string $token = self::TOKEN): Company
    {
        $company = Company::query()->create([
            'name' => $name, 'slug' => $slug, 'invoice_prefix' => strtoupper(substr(md5($slug), 0, 5)),
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);

        app(SocialSettingsService::class)->save($company, [
            'enabled' => true,
            'page_id' => $pageId,
            'instagram_account_id' => $instagramId,
            'page_access_token' => $token,
        ]);

        return $company->refresh();
    }

    private function jpegBytes(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(12, 12));

        return (string) ob_get_clean();
    }

    private function pngBytes(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(12, 12));

        return (string) ob_get_clean();
    }

    private function attachPicture(SocialPost $post, ?string $bytes = null, string $name = 'pic.jpg'): SocialPostMedia
    {
        $path = app(CompanyStorageService::class)->putPublic($post->company, 'social-posts', $name, $bytes ?? $this->jpegBytes());

        return SocialPostMedia::query()->create(['social_post_id' => $post->getKey(), 'kind' => 'image', 'path' => $path]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makePost(array $overrides = [], ?Company $company = null): SocialPost
    {
        $company ??= $this->company;

        return SocialPost::withoutGlobalScopes()->create(array_merge([
            'company_id' => $company->getKey(),
            'title' => 'Eid offer',
            'body' => 'Eid sale starts today.',
            'language' => 'en',
            'platforms' => ['facebook'],
            'status' => SocialPost::STATUS_READY,
            'approval_state' => SocialPost::APPROVAL_PENDING,
            'created_by_user_id' => $this->admin->getKey(),
        ], $overrides))->load('company');
    }

    private function approved(SocialPost $post): SocialPost
    {
        app(SocialPostApprovalService::class)->approve($post->refresh(), $this->admin);

        return $post->refresh();
    }

    /** Replaces every Http stub (the first matching stub wins, so a second Http::fake() would not take over). */
    private function refake(array $stubs): void
    {
        Http::swap(new HttpFactory);
        Http::fake($stubs);
    }

    private function fakeMedia(): void
    {
        Http::fake(['erp.example.test/*' => Http::response('x', 200, ['Content-Type' => 'image/jpeg'])]);
    }

    /** Publishes right now and runs the queued job inline. */
    private function publishNowAndRun(SocialPost $post): SocialPost
    {
        Queue::fake();
        app(SocialPostPublishingService::class)->publishNow($post->refresh(), $this->admin);
        Queue::assertPushed(PublishSocialPostJob::class);
        app(SocialPublishRunner::class)->run($post->getKey());

        return SocialPost::withoutGlobalScopes()->findOrFail($post->getKey());
    }

    private function graphCalls(): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com'))
            ->map(fn (array $pair): Request => $pair[0])
            ->all();
    }

    // ---- approval gate --------------------------------------------------------------------------------------

    public function test_an_unapproved_post_cannot_be_queued_or_published(): void
    {
        Queue::fake();
        Http::fake();
        $post = $this->makePost();

        try {
            app(SocialPostPublishingService::class)->publishNow($post, $this->admin);
            $this->fail('An unapproved post was queued.');
        } catch (SocialPostException $exception) {
            $this->assertStringContainsString('approved', $exception->getMessage());
        }

        Queue::assertNothingPushed();

        // Even if something forces it into the queue, the runner refuses to send it.
        $post->forceFill(['publish_state' => SocialPost::PUBLISH_QUEUED])->save();
        app(SocialPublishRunner::class)->run($post->getKey());

        Http::assertNothingSent();
        $post->refresh();
        $this->assertSame(SocialPost::PUBLISH_FAILED, $post->publish_state);
        $this->assertSame(SocialPost::STATUS_READY, $post->status);
    }

    public function test_changing_an_approved_post_voids_the_approval(): void
    {
        Queue::fake();
        $post = $this->approved($this->makePost());
        $this->assertTrue($post->isApproved());

        $post->forceFill(['body' => 'Different text'])->save();

        $this->assertFalse($post->refresh()->isApproved());
        $this->assertTrue($post->approvalIsStale());

        $this->expectException(SocialPostException::class);
        app(SocialPostPublishingService::class)->publishNow($post, $this->admin);
    }

    public function test_changing_the_picture_also_voids_the_approval(): void
    {
        $post = $this->makePost();
        $this->attachPicture($post, $this->jpegBytes(), 'a.jpg');
        $post = $this->approved($post);
        $this->assertTrue($post->isApproved());

        $post->media()->delete();
        $this->attachPicture($post, $this->pngBytes(), 'b.png');

        $this->assertFalse($post->refresh()->isApproved());
    }

    public function test_a_user_without_the_approve_permission_cannot_approve(): void
    {
        $post = $this->makePost();
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        $this->expectException(SocialPostException::class);
        app(SocialPostApprovalService::class)->approve($post, $staff);
    }

    public function test_a_user_may_approve_their_own_post_and_the_audit_log_records_it(): void
    {
        $post = $this->makePost(['created_by_user_id' => $this->admin->getKey()]);

        app(SocialPostApprovalService::class)->approve($post, $this->admin);

        $this->assertTrue($post->refresh()->isApproved());
        $this->assertDatabaseHas('audit_logs', ['action' => 'social_post.approved', 'user_id' => $this->admin->getKey()]);
    }

    // ---- publishing -----------------------------------------------------------------------------------------

    public function test_an_approved_facebook_text_post_is_published_with_the_companys_own_token(): void
    {
        Http::fake(['graph.facebook.com/*/111111/feed' => Http::response(['id' => '111111_999'])]);
        $post = $this->approved($this->makePost());

        $post = $this->publishNowAndRun($post);

        $this->assertSame(SocialPost::PUBLISH_SUCCEEDED, $post->publish_state);
        $this->assertSame(SocialPost::STATUS_POSTED, $post->status);
        $this->assertSame('111111_999', $post->publish_result['facebook']['post_id']);
        $this->assertSame('https://www.facebook.com/111111_999', $post->publish_result['facebook']['link']);

        $calls = $this->graphCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('Eid sale starts today.', $calls[0]['message']);
        $this->assertSame('Bearer '.self::TOKEN, $calls[0]->header('Authorization')[0]);
    }

    public function test_a_facebook_picture_post_uses_the_photos_endpoint_with_a_public_url(): void
    {
        $this->fakeMedia();
        Http::fake([
            'erp.example.test/*' => Http::response('x', 200, ['Content-Type' => 'image/jpeg']),
            'graph.facebook.com/*/111111/photos' => Http::response(['id' => '777', 'post_id' => '111111_888']),
        ]);
        $post = $this->makePost();
        $this->attachPicture($post);
        $post = $this->approved($post);

        $post = $this->publishNowAndRun($post);

        $this->assertSame(SocialPost::STATUS_POSTED, $post->status);
        $call = collect($this->graphCalls())->first(fn (Request $request): bool => str_ends_with($request->url(), '/111111/photos'));
        $this->assertStringStartsWith('https://erp.example.test/', $call['url']);
        $this->assertSame('111111_888', $post->publish_result['facebook']['post_id']);
    }

    public function test_instagram_takes_two_steps_and_a_png_is_converted_to_jpeg(): void
    {
        Http::fake([
            'erp.example.test/*' => Http::response('x', 200, ['Content-Type' => 'image/jpeg']),
            'graph.facebook.com/*/222222/media' => Http::response(['id' => 'container-1']),
            'graph.facebook.com/*/container-1*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'])
                ->push(['status_code' => 'FINISHED']),
            'graph.facebook.com/*/222222/media_publish' => Http::response(['id' => '17900000']),
            'graph.facebook.com/*/17900000*' => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
        ]);
        $post = $this->makePost(['platforms' => ['instagram'], 'body' => 'Caption']);
        $this->attachPicture($post, $this->pngBytes(), 'pic.png');
        $post = $this->approved($post);

        $post = $this->publishNowAndRun($post);

        $this->assertSame(SocialPost::STATUS_POSTED, $post->status);
        $this->assertSame('17900000', $post->publish_result['instagram']['post_id']);
        $this->assertSame('https://www.instagram.com/p/abc/', $post->publish_result['instagram']['link']);

        $container = collect($this->graphCalls())->first(fn (Request $request): bool => str_ends_with($request->url(), '/222222/media'));
        $this->assertStringEndsWith('.jpg', $container['image_url'], 'a PNG is converted before Instagram sees it');
        $this->assertSame('Caption', $container['caption']);
    }

    public function test_instagram_without_a_picture_is_refused_before_anything_is_sent(): void
    {
        Queue::fake();
        Http::fake();
        $post = $this->approvedViaForce($this->makePost(['platforms' => ['instagram']]));

        try {
            app(SocialPostPublishingService::class)->publishNow($post, $this->admin);
            $this->fail('A post the planner refuses was queued.');
        } catch (SocialPostException $exception) {
            $this->assertStringContainsString('Instagram needs a picture', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /** Approval refuses content problems, so tests that need an approved-but-invalid post set the fields directly. */
    private function approvedViaForce(SocialPost $post): SocialPost
    {
        $post->forceFill([
            'approval_state' => SocialPost::APPROVAL_APPROVED,
            'approved_fingerprint' => $post->contentFingerprint(),
            'approved_by_user_id' => $this->admin->getKey(),
            'approved_at' => now(),
        ])->save();

        return $post->refresh();
    }

    public function test_the_job_running_twice_publishes_once(): void
    {
        Http::fake(['graph.facebook.com/*/111111/feed' => Http::response(['id' => '111111_1'])]);
        $post = $this->approved($this->makePost());

        $this->publishNowAndRun($post);
        app(SocialPublishRunner::class)->run($post->getKey());
        app(SocialPublishRunner::class)->run($post->getKey());

        $this->assertCount(1, $this->graphCalls());
    }

    public function test_a_second_run_cannot_claim_a_post_that_is_already_running(): void
    {
        Http::fake();
        $post = $this->approved($this->makePost());
        $post->forceFill([
            'publish_state' => SocialPost::PUBLISH_RUNNING,
            'lease_token' => 'someone-else',
            'lease_expires_at' => now()->addMinutes(10),
        ])->save();

        app(SocialPublishRunner::class)->run($post->getKey());

        Http::assertNothingSent();
        $this->assertSame('someone-else', SocialPost::withoutGlobalScopes()->find($post->getKey())->lease_token);
    }

    // ---- lost answers, retry, reconcile ---------------------------------------------------------------------

    public function test_a_lost_answer_leaves_the_platform_uncertain_and_a_retry_refuses(): void
    {
        $attempts = 0;
        Http::fake(['graph.facebook.com/*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('timed out');
        }]);
        $post = $this->approved($this->makePost());

        $post = $this->publishNowAndRun($post);

        $this->assertSame(SocialPost::PUBLISH_FAILED, $post->publish_state);
        $this->assertSame('uncertain', $post->publish_progress['facebook']['state']);
        $this->assertTrue($post->hasUncertainPlatform());
        $this->assertTrue($post->isLocked(), 'an uncertain post cannot be edited');
        $this->assertSame(1, $attempts);

        Queue::fake();
        try {
            app(SocialPostPublishingService::class)->retry($post, $this->admin);
            $this->fail('A retry was allowed while a platform was uncertain.');
        } catch (SocialPostException $exception) {
            $this->assertStringContainsString('Reconcile', $exception->getMessage());
        }

        Queue::assertNothingPushed();
        // Even a forced run never replays the uncertain call.
        $post->forceFill(['publish_state' => SocialPost::PUBLISH_QUEUED])->save();
        app(SocialPublishRunner::class)->run($post->getKey());
        $this->assertSame(1, $attempts, 'the uncertain call is never replayed');
    }

    public function test_a_server_error_from_meta_is_treated_as_an_unknown_outcome(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom', 'code' => 2]], 500)]);
        $post = $this->publishNowAndRun($this->approved($this->makePost()));

        $this->assertSame('uncertain', $post->publish_progress['facebook']['state']);
        $this->assertCount(1, $this->graphCalls(), 'a create call is never retried automatically');
    }

    public function test_reconcile_with_an_id_marks_the_platform_posted(): void
    {
        Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('timed out')]);
        $post = $this->publishNowAndRun($this->approved($this->makePost()));

        app(SocialPostPublishingService::class)->reconcileWithId($post, 'facebook', '111111_4242', $this->admin);

        $post->refresh();
        $this->assertSame(SocialPost::STATUS_POSTED, $post->status);
        $this->assertSame(SocialPost::PUBLISH_SUCCEEDED, $post->publish_state);
        $this->assertSame('111111_4242', $post->publish_result['facebook']['post_id']);
        $this->assertFalse($post->hasUncertainPlatform());
        $this->assertDatabaseHas('audit_logs', ['action' => 'social_post.reconciled']);
    }

    public function test_reconcile_as_absent_allows_a_retry_that_sends_it_again(): void
    {
        Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('timed out')]);
        $post = $this->publishNowAndRun($this->approved($this->makePost()));

        app(SocialPostPublishingService::class)->reconcileAbsent($post, 'facebook', $this->admin);

        $post->refresh();
        $this->assertSame('failed', $post->publish_progress['facebook']['state']);
        $this->assertFalse($post->hasUncertainPlatform());

        $this->refake(['graph.facebook.com/*/111111/feed' => Http::response(['id' => '111111_55'])]);
        Queue::fake();
        app(SocialPostPublishingService::class)->retry($post, $this->admin);
        app(SocialPublishRunner::class)->run($post->getKey());

        $post->refresh();
        $this->assertSame(SocialPost::STATUS_POSTED, $post->status);
        $this->assertSame('111111_55', $post->publish_result['facebook']['post_id']);
    }

    public function test_reconcile_rejects_a_platform_that_is_not_uncertain_and_a_malformed_id(): void
    {
        Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('timed out')]);
        $post = $this->publishNowAndRun($this->approved($this->makePost()));

        try {
            app(SocialPostPublishingService::class)->reconcileWithId($post, 'facebook', 'not an id!', $this->admin);
            $this->fail('A malformed id was accepted.');
        } catch (SocialPostException) {
            $this->assertTrue($post->refresh()->hasUncertainPlatform());
        }

        $this->expectException(SocialPostException::class);
        app(SocialPostPublishingService::class)->reconcileAbsent($post, 'instagram', $this->admin);
    }

    public function test_a_post_with_one_failed_platform_retries_only_that_platform(): void
    {
        $this->fakeMedia();
        Http::fake([
            'erp.example.test/*' => Http::response('x', 200, ['Content-Type' => 'image/jpeg']),
            'graph.facebook.com/*/111111/photos' => Http::response(['post_id' => '111111_1']),
            'graph.facebook.com/*/222222/media' => Http::response(['error' => ['message' => 'Invalid image', 'code' => 100]], 400),
        ]);
        $post = $this->makePost(['platforms' => ['facebook', 'instagram']]);
        $this->attachPicture($post);
        $post = $this->publishNowAndRun($this->approved($post));

        $this->assertSame(SocialPost::PUBLISH_FAILED, $post->publish_state);
        $this->assertSame('succeeded', $post->publish_progress['facebook']['state']);
        $this->assertSame('failed', $post->publish_progress['instagram']['state']);
        $this->assertSame(SocialPost::STATUS_READY, $post->status, 'a clear failure is not a block');

        $this->refake([
            'erp.example.test/*' => Http::response('x', 200, ['Content-Type' => 'image/jpeg']),
            'graph.facebook.com/*/222222/media' => Http::response(['id' => 'c-9']),
            'graph.facebook.com/*/c-9*' => Http::response(['status_code' => 'FINISHED']),
            'graph.facebook.com/*/222222/media_publish' => Http::response(['id' => '1790']),
            'graph.facebook.com/*/1790*' => Http::response(['permalink' => 'https://www.instagram.com/p/x/']),
        ]);
        Queue::fake();
        app(SocialPostPublishingService::class)->retry($post, $this->admin);
        app(SocialPublishRunner::class)->run($post->getKey());

        $post->refresh();
        $this->assertSame(SocialPost::STATUS_POSTED, $post->status);
        $facebookCalls = collect($this->graphCalls())->filter(fn (Request $request): bool => str_contains($request->url(), '/111111/'));
        $this->assertCount(0, $facebookCalls, 'Facebook was not sent again');
    }

    public function test_an_authentication_error_blocks_the_post_and_is_not_retried(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'expired', 'code' => 190]], 401)]);
        $post = $this->publishNowAndRun($this->approved($this->makePost()));

        $this->assertSame(SocialPost::STATUS_BLOCKED, $post->status);
        $this->assertSame(SocialPost::PUBLISH_FAILED, $post->publish_state);
        $this->assertStringContainsString('Social → Settings', $post->publish_error);
        $this->assertCount(1, $this->graphCalls());
        $this->assertStringNotContainsString(self::TOKEN, (string) $post->publish_error);
    }

    public function test_instagram_container_that_errors_is_dropped_so_a_retry_makes_a_new_one(): void
    {
        Http::fake([
            'erp.example.test/*' => Http::response('x', 200, ['Content-Type' => 'image/jpeg']),
            'graph.facebook.com/*/222222/media' => Http::response(['id' => 'c-bad']),
            'graph.facebook.com/*/c-bad*' => Http::response(['status_code' => 'ERROR']),
        ]);
        $post = $this->makePost(['platforms' => ['instagram']]);
        $this->attachPicture($post);
        $post = $this->publishNowAndRun($this->approved($post));

        $this->assertSame('failed', $post->publish_progress['instagram']['state']);
        $this->assertNull($post->publish_progress['instagram']['container_id']);
    }

    public function test_a_container_that_is_still_processing_is_kept_for_the_retry(): void
    {
        Http::fake([
            'erp.example.test/*' => Http::response('x', 200, ['Content-Type' => 'image/jpeg']),
            'graph.facebook.com/*/222222/media' => Http::response(['id' => 'c-slow']),
            'graph.facebook.com/*/c-slow*' => Http::response(['status_code' => 'IN_PROGRESS']),
        ]);
        $post = $this->makePost(['platforms' => ['instagram']]);
        $this->attachPicture($post);
        $post = $this->publishNowAndRun($this->approved($post));

        $this->assertSame('failed', $post->publish_progress['instagram']['state']);
        $this->assertSame('c-slow', $post->publish_progress['instagram']['container_id']);
        $this->assertSame('container', $post->publish_progress['instagram']['completed']);
    }

    public function test_an_unreachable_picture_is_refused_before_meta_is_called(): void
    {
        Http::fake(['erp.example.test/*' => Http::response('nope', 404)]);
        $post = $this->makePost();
        $this->attachPicture($post);
        $post = $this->publishNowAndRun($this->approved($post));

        $this->assertSame('failed', $post->publish_progress['facebook']['state']);
        $this->assertStringContainsString('not reachable', $post->publish_progress['facebook']['error']);
        $this->assertCount(0, $this->graphCalls());
    }

    // ---- isolation ------------------------------------------------------------------------------------------

    public function test_one_company_never_sees_or_publishes_with_another_companys_settings(): void
    {
        $other = $this->makeCompany('Social Two', 'social-two', '333333', '444444', 'EAAB-other-company-token');
        $otherPost = $this->makePost(['title' => 'Other'], $other);

        app(CompanyContext::class)->set($this->company);
        $this->assertNull(SocialPost::query()->find($otherPost->getKey()), 'the company scope hides the other company');

        Http::fake(['graph.facebook.com/*/333333/feed' => Http::response(['id' => '333333_5'])]);
        app(CompanyContext::class)->set($other);
        $this->approvedAs($otherPost);
        $post = $this->publishNowAndRun($otherPost);

        $call = $this->graphCalls()[0];
        $this->assertStringContainsString('/333333/feed', $call->url());
        $this->assertSame('Bearer EAAB-other-company-token', $call->header('Authorization')[0]);
        $this->assertSame(SocialPost::STATUS_POSTED, $post->status);
    }

    private function approvedAs(SocialPost $post): void
    {
        app(SocialPostApprovalService::class)->approve($post->refresh(), $this->admin);
    }

    // ---- secrets --------------------------------------------------------------------------------------------

    public function test_the_page_access_token_is_stored_encrypted_and_never_leaves_the_server(): void
    {
        $raw = json_encode($this->company->refresh()->settings);
        $this->assertStringNotContainsString(self::TOKEN, $raw);

        $service = app(SocialSettingsService::class);
        $this->assertSame(self::TOKEN, $service->all($this->company)['page_access_token']);
        $this->assertArrayNotHasKey('page_access_token', $service->forDisplay($this->company));
        $this->assertTrue($service->forDisplay($this->company)['has_page_access_token']);

        // A blank token on save keeps the stored one.
        $service->save($this->company, ['enabled' => true, 'page_id' => '111111', 'instagram_account_id' => '222222', 'page_access_token' => '']);
        $this->assertSame(self::TOKEN, $service->all($this->company->refresh())['page_access_token']);

        // The post's private checkpoint is hidden from serialization.
        $post = $this->makePost(['publish_progress' => ['facebook' => ['state' => 'running']]]);
        $this->assertArrayNotHasKey('publish_progress', $post->toArray());
    }

    // ---- scheduling -----------------------------------------------------------------------------------------

    public function test_the_due_command_queues_only_approved_scheduled_posts_that_are_due(): void
    {
        Queue::fake();
        $due = $this->approved($this->makePost(['status' => SocialPost::STATUS_READY]));
        $due->forceFill(['status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->subMinute()])->save();

        $later = $this->approved($this->makePost());
        $later->forceFill(['status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addHour()])->save();

        $unapproved = $this->makePost(['status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->subMinute()]);

        $this->artisan('social:publish-due')->assertSuccessful();

        Queue::assertPushed(PublishSocialPostJob::class, 1);
        Queue::assertPushed(PublishSocialPostJob::class, fn (PublishSocialPostJob $job): bool => $job->socialPostId === $due->getKey());
        $this->assertSame(SocialPost::PUBLISH_QUEUED, $due->refresh()->publish_state);
        $this->assertSame(SocialPost::PUBLISH_IDLE, $later->refresh()->publish_state);
        $this->assertSame(SocialPost::PUBLISH_FAILED, $unapproved->refresh()->publish_state);

        // Running it again does not queue the same post twice.
        $this->artisan('social:publish-due')->assertSuccessful();
        Queue::assertPushed(PublishSocialPostJob::class, 1);
    }

    public function test_the_due_command_skips_a_company_that_switched_publishing_off(): void
    {
        Queue::fake();
        $post = $this->approved($this->makePost());
        $post->forceFill(['status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->subMinute()])->save();
        app(SocialSettingsService::class)->save($this->company, ['enabled' => false, 'page_id' => '111111', 'instagram_account_id' => '222222']);

        $this->artisan('social:publish-due')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_scheduling_stores_utc_and_shows_the_companys_time(): void
    {
        $post = $this->approved($this->makePost());
        $at = now()->addDay()->setTime(15, 0)->utc();

        app(SocialPostPublishingService::class)->schedule($post, $at, $this->admin);

        $post->refresh();
        $this->assertSame(SocialPost::STATUS_SCHEDULED, $post->status);
        $this->assertSame($at->format('Y-m-d H:i'), $post->scheduled_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('Asia/Dhaka', $post->timezone());
        $this->assertSame($at->copy()->setTimezone('Asia/Dhaka')->format('H:i'), $post->scheduled_at->copy()->setTimezone($post->timezone())->format('H:i'));
    }

    public function test_a_schedule_in_the_past_is_refused(): void
    {
        $post = $this->approved($this->makePost());

        $this->expectException(SocialPostException::class);
        app(SocialPostPublishingService::class)->schedule($post, now()->subMinute(), $this->admin);
    }

    public function test_a_run_stopped_half_way_is_recovered_with_the_in_flight_platform_uncertain(): void
    {
        $post = $this->approved($this->makePost());
        $post->forceFill([
            'publish_state' => SocialPost::PUBLISH_RUNNING,
            'lease_token' => 'dead-worker',
            'lease_expires_at' => now()->subMinute(),
            'publish_progress' => ['facebook' => ['state' => 'running', 'pending' => 'publish']],
        ])->save();

        $this->artisan('social:publish-due')->assertSuccessful();

        $post->refresh();
        $this->assertSame(SocialPost::PUBLISH_FAILED, $post->publish_state);
        $this->assertSame('uncertain', $post->publish_progress['facebook']['state']);
        $this->assertNull($post->lease_token);
    }

    // ---- publishing switch ----------------------------------------------------------------------------------

    public function test_publishing_is_refused_when_the_company_has_not_switched_it_on(): void
    {
        app(SocialSettingsService::class)->save($this->company, ['enabled' => false, 'page_id' => '111111', 'instagram_account_id' => '222222']);
        $post = $this->approved($this->makePost());

        $this->expectException(SocialPostException::class);
        app(SocialPostPublishingService::class)->publishNow($post, $this->admin);
    }

    public function test_the_worker_heartbeat_is_remembered(): void
    {
        Cache::forget(SocialSettingsService::WORKER_HEARTBEAT_CACHE_KEY);
        $service = app(SocialSettingsService::class);
        $this->assertFalse($service->workerSeenRecently());

        (new SocialQueueHeartbeatJob)->handle($service);

        $this->assertTrue($service->workerSeenRecently());
    }

    public function test_the_job_uses_its_own_queue_connection_and_is_never_retried_by_the_queue(): void
    {
        $job = new PublishSocialPostJob(5);

        $this->assertSame('social', $job->connection);
        $this->assertSame('social', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.social.retry_after'));
        $this->assertSame('5', $job->uniqueId());
    }
}
