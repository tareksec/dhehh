<?php

namespace Tests\Feature;

use App\Filament\Pages\SocialSettings;
use App\Filament\Resources\SocialPosts\Pages\CreateSocialPost;
use App\Filament\Resources\SocialPosts\Pages\EditSocialPost;
use App\Filament\Resources\SocialPosts\Pages\ListSocialPosts;
use App\Filament\Resources\SocialPosts\SocialPostResource;
use App\Jobs\PublishSocialPostJob;
use App\Models\Company;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\Social\SocialPostApprovalService;
use App\Services\Social\SocialSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The Social screens of 19_SOCIAL_CONTENT_PLANNER_PLAN.md §5 and §6: tabs, workflow actions, permissions, settings. */
class SocialPostResourceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAAB-resource-test-token-123456';

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Social Screens Co', 'slug' => 'social-screens-co', 'invoice_prefix' => 'SSCO',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        app(SocialSettingsService::class)->save($this->company, [
            'enabled' => true, 'page_id' => '111111', 'instagram_account_id' => '222222', 'page_access_token' => self::TOKEN,
        ]);

        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    private function makePost(array $overrides = []): SocialPost
    {
        return SocialPost::query()->create(array_merge([
            'title' => 'Eid offer', 'body' => 'Eid sale starts today.', 'language' => 'en',
            'platforms' => ['facebook'], 'status' => SocialPost::STATUS_READY,
            'approval_state' => SocialPost::APPROVAL_PENDING, 'created_by_user_id' => $this->admin->getKey(),
        ], $overrides));
    }

    public function test_the_list_page_renders_and_filters_by_tab(): void
    {
        $draft = $this->makePost(['status' => SocialPost::STATUS_DRAFT, 'title' => 'A draft']);
        $posted = $this->makePost(['status' => SocialPost::STATUS_POSTED, 'title' => 'Done one']);
        $archived = $this->makePost(['status' => SocialPost::STATUS_ARCHIVED, 'title' => 'Old one']);

        Livewire::test(ListSocialPosts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$draft, $posted])
            ->assertCanNotSeeTableRecords([$archived])
            ->set('activeTab', 'posted')
            ->assertCanSeeTableRecords([$posted])
            ->assertCanNotSeeTableRecords([$draft, $archived])
            ->set('activeTab', 'archived')
            ->assertCanSeeTableRecords([$archived]);
    }

    public function test_a_draft_is_created_with_the_creator_and_cannot_set_its_own_status(): void
    {
        Livewire::test(CreateSocialPost::class)
            ->fillForm([
                'title' => 'Launch', 'body' => 'We are live.', 'language' => 'en', 'platforms' => ['facebook'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = SocialPost::query()->where('title', 'Launch')->firstOrFail();
        $this->assertSame(SocialPost::STATUS_DRAFT, $post->status);
        $this->assertSame(SocialPost::APPROVAL_PENDING, $post->approval_state);
        $this->assertSame($this->admin->getKey(), $post->created_by_user_id);
        $this->assertSame($this->company->getKey(), $post->company_id);
        $this->assertSame(1, $post->number);
        $this->assertSame('S-1', $post->human_id);
    }

    public function test_a_picture_uploaded_on_the_form_is_attached_with_its_hash_and_counts_in_the_approval(): void
    {
        Storage::fake('public');

        Livewire::test(CreateSocialPost::class)
            ->fillForm([
                'title' => 'With picture', 'body' => 'Look at this.', 'language' => 'en', 'platforms' => ['facebook', 'instagram'],
                'media' => [['path' => [UploadedFile::fake()->image('offer.jpg', 600, 600)]]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = SocialPost::query()->where('title', 'With picture')->firstOrFail();
        $media = $post->media()->get();

        $this->assertCount(1, $media);
        $this->assertSame($this->company->getKey(), $media->first()->company_id);
        $this->assertSame('image', $media->first()->kind);
        $this->assertSame('image/jpeg', $media->first()->mime);
        $this->assertSame(64, strlen((string) $media->first()->sha256));
        $this->assertGreaterThan(0, $media->first()->bytes);

        // The picture is part of what an approval covers.
        $before = $post->contentFingerprint();
        $media->first()->forceFill(['sha256' => str_repeat('a', 64)])->save();
        $this->assertNotSame($before, $post->refresh()->contentFingerprint());
    }

    public function test_each_company_numbers_its_posts_from_one(): void
    {
        $this->makePost();
        $this->makePost();

        $other = Company::query()->create([
            'name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTHR',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        $first = SocialPost::withoutGlobalScopes()->create([
            'company_id' => $other->getKey(), 'body' => 'x', 'language' => 'en', 'platforms' => ['facebook'],
        ]);

        $this->assertSame(1, $first->number);
        $this->assertSame(2, SocialPost::query()->max('number'));
    }

    public function test_the_workflow_actions_take_a_post_from_draft_to_queued(): void
    {
        Queue::fake();
        $post = $this->makePost(['status' => SocialPost::STATUS_DRAFT]);

        $list = Livewire::test(ListSocialPosts::class);

        $list->callTableAction('sendForApproval', $post)->assertHasNoTableActionErrors();
        $this->assertSame(SocialPost::STATUS_READY, $post->refresh()->status);

        $list->assertTableActionVisible('approve', $post)->assertTableActionHidden('publishNow', $post);
        $list->callTableAction('approve', $post);
        $this->assertTrue($post->refresh()->isApproved());

        $list->assertTableActionHidden('approve', $post)->assertTableActionVisible('publishNow', $post);
        $list->callTableAction('publishNow', $post);

        $this->assertSame(SocialPost::PUBLISH_QUEUED, $post->refresh()->publish_state);
        Queue::assertPushed(PublishSocialPostJob::class, fn (PublishSocialPostJob $job): bool => $job->socialPostId === $post->getKey());
    }

    public function test_the_time_typed_in_the_picker_is_the_companys_time_and_is_stored_as_utc(): void
    {
        $post = $this->makePost();
        app(SocialPostApprovalService::class)->approve($post, $this->admin);
        $typed = now('Asia/Dhaka')->addDays(2)->setTime(9, 30)->format('Y-m-d H:i:s');

        Livewire::test(ListSocialPosts::class)
            ->callTableAction('schedule', $post->refresh(), data: ['scheduled_at' => $typed])
            ->assertHasNoTableActionErrors();

        $post->refresh();
        $this->assertSame(SocialPost::STATUS_SCHEDULED, $post->status);
        $this->assertSame(
            Carbon::parse($typed, 'Asia/Dhaka')->utc()->format('Y-m-d H:i'),
            $post->scheduled_at->utc()->format('Y-m-d H:i'),
            '09:30 in Dhaka is 03:30 UTC',
        );
        $this->assertSame('09:30', $post->scheduled_at->copy()->setTimezone($post->timezone())->format('H:i'));
    }

    public function test_a_refused_action_shows_the_reason_instead_of_an_error_page(): void
    {
        $post = $this->makePost(['platforms' => ['instagram']]);
        // Approval of a post the planner refuses is itself refused.
        Livewire::test(ListSocialPosts::class)
            ->callTableAction('approve', $post)
            ->assertNotified(__('Not done'));

        $this->assertFalse($post->refresh()->isApproved());
    }

    public function test_editing_an_approved_post_takes_the_approval_back(): void
    {
        $post = $this->makePost();
        app(SocialPostApprovalService::class)->approve($post, $this->admin);
        $this->assertTrue($post->refresh()->isApproved());

        Livewire::test(EditSocialPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['body' => 'Changed after approval'])
            ->call('save')
            ->assertHasNoFormErrors();

        $post->refresh();
        $this->assertSame('Changed after approval', $post->body);
        $this->assertSame(SocialPost::APPROVAL_PENDING, $post->approval_state);
        $this->assertNull($post->approved_fingerprint);
        $this->assertDatabaseHas('audit_logs', ['action' => 'social_post.approval_voided']);
    }

    public function test_a_post_being_published_is_not_saved_over(): void
    {
        $post = $this->makePost(['publish_state' => SocialPost::PUBLISH_RUNNING, 'lease_token' => 'x', 'lease_expires_at' => now()->addMinutes(5)]);

        Livewire::test(EditSocialPost::class, ['record' => $post->getRouteKey()])
            ->call('save');

        $this->assertSame('Eid sale starts today.', $post->refresh()->body);
    }

    public function test_the_reconcile_action_appears_only_for_an_uncertain_platform(): void
    {
        $plain = $this->makePost();
        $uncertain = $this->makePost([
            'publish_state' => SocialPost::PUBLISH_FAILED,
            'publish_progress' => ['facebook' => ['state' => 'uncertain']],
        ]);

        Livewire::test(ListSocialPosts::class)
            ->assertTableActionHidden('reconcile', $plain)
            ->assertTableActionVisible('reconcile', $uncertain)
            ->assertTableActionHidden('retry', $uncertain)
            ->callTableAction('reconcile', $uncertain, data: ['platform' => 'facebook', 'outcome' => 'found', 'platform_post_id' => '111111_77'])
            ->assertHasNoTableActionErrors();

        $uncertain->refresh();
        $this->assertSame(SocialPost::STATUS_POSTED, $uncertain->status);
        $this->assertSame('111111_77', $uncertain->publish_result['facebook']['post_id']);
    }

    public function test_only_users_with_the_social_permissions_can_use_the_module(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));
        $this->assertFalse(SocialPostResource::canViewAny());
        $this->assertFalse(SocialSettings::canAccess());

        UserRole::query()->create([
            'name' => 'Social viewer', 'slug' => 'social_viewer', 'permissions' => ['social.view'], 'is_active' => true,
        ]);
        $viewer = User::factory()->create(['role' => 'social_viewer', 'is_active' => true]);
        $this->actingAs($viewer);

        $this->assertTrue(SocialPostResource::canViewAny());
        $this->assertFalse(SocialPostResource::canCreate());
        $this->assertFalse(SocialSettings::canAccess());

        $post = $this->makePost();
        Livewire::test(ListSocialPosts::class)
            ->assertTableActionHidden('approve', $post)
            ->assertTableActionHidden('publishNow', $post)
            ->assertTableActionHidden('archive', $post);
    }

    public function test_no_built_in_role_but_super_admin_holds_the_social_permissions_yet(): void
    {
        foreach (['manager', 'sales_staff', 'inventory_staff', 'accountant'] as $role) {
            $granted = array_filter(User::ROLE_PERMISSIONS[$role] ?? [], fn (string $permission): bool => str_starts_with($permission, 'social.'));
            $this->assertSame([], array_values($granted), "{$role} must not hold social permissions until the owner decides");
        }

        foreach (['social.view', 'social.create', 'social.update', 'social.delete', 'social.approve', 'social.publish', 'social.settings'] as $key) {
            $this->assertArrayHasKey($key, User::CUSTOM_PERMISSION_OPTIONS);
        }
    }

    // ---- settings -------------------------------------------------------------------------------------------

    public function test_social_settings_keep_the_token_when_the_field_is_left_blank(): void
    {
        Cache::put(SocialSettingsService::WORKER_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addMinutes(10));

        Livewire::test(SocialSettings::class)
            ->assertFormSet(['page_id' => '111111', 'page_access_token' => null])
            ->fillForm(['page_id' => '999000', 'page_access_token' => ''])
            ->call('save');

        $settings = app(SocialSettingsService::class)->all($this->company->refresh());
        $this->assertSame('999000', $settings['page_id']);
        $this->assertSame(self::TOKEN, $settings['page_access_token']);
        $this->assertTrue($settings['enabled']);
    }

    public function test_publishing_cannot_be_switched_on_until_the_social_worker_has_been_seen(): void
    {
        app(SocialSettingsService::class)->save($this->company, ['enabled' => false, 'page_id' => '111111', 'instagram_account_id' => '222222']);
        Cache::forget(SocialSettingsService::WORKER_HEARTBEAT_CACHE_KEY);

        Livewire::test(SocialSettings::class)
            ->fillForm(['enabled' => true])
            ->call('save')
            ->assertNotified(__('Publishing was not switched on'));

        $this->assertFalse(app(SocialSettingsService::class)->isEnabled($this->company->refresh()));

        Cache::put(SocialSettingsService::WORKER_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addMinutes(10));

        Livewire::test(SocialSettings::class)
            ->fillForm(['enabled' => true])
            ->call('save');

        $this->assertTrue(app(SocialSettingsService::class)->isEnabled($this->company->refresh()));
    }

    public function test_test_connection_reads_the_page_and_never_posts(): void
    {
        Http::fake([
            'graph.facebook.com/*/111111*' => Http::response(['id' => '111111', 'name' => 'Zam Zam Page']),
            'graph.facebook.com/*/222222*' => Http::response(['id' => '222222', 'username' => 'zamzam_ig']),
        ]);

        Livewire::test(SocialSettings::class)
            ->callAction('testConnection')
            ->assertNotified(__('Connection works'));

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
        $this->assertNotNull(app(SocialSettingsService::class)->lastSuccessAt($this->company->refresh()));
    }

    public function test_test_connection_shows_a_failure_message_without_the_token(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)]);

        Livewire::test(SocialSettings::class)
            ->callAction('testConnection')
            ->assertNotified(__('Connection failed'));
    }
}
