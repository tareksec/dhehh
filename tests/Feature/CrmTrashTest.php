<?php

namespace Tests\Feature;

use App\Filament\Pages\Inbox;
use App\Filament\Resources\ConversationChannels\ConversationChannelResource;
use App\Filament\Resources\ConversationChannels\Pages\CreateConversationChannel;
use App\Filament\Resources\ConversationChannels\Pages\EditConversationChannel;
use App\Filament\Resources\ConversationChannels\Pages\TrashedConversationChannels;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\TrashedLeads;
use App\Filament\Resources\Leads\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\TrashedQuotations;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\ResellerLevels\ResellerLevelResource;
use App\Models\AttentionItem;
use App\Models\Broadcast;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Quotation;
use App\Models\ResellerLevel;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\Crm\AttentionQueueService;
use App\Services\Crm\BroadcastService;
use App\Services\Crm\ContactSuppressionService;
use App\Services\Crm\ConversationMessengerService;
use App\Services\Crm\LeadConversionService;
use App\Support\TypeToConfirmDelete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Owner request 2026-11-01, phase T4b of docs/delete-and-trash-plan.md: leads
 * (and the activities logged on them), quotations, chat channels and reseller
 * levels go to Trash when deleted and can be restored or deleted permanently.
 *
 * A thing in Trash behaves like a deleted one did until it is restored: a channel
 * receives and sends nothing, a lead gets no broadcast or reminder and leaves
 * Needs attention, a level stops applying. The reseller-level cases are in
 * ResellerLevelsTest, the embedded-signup case in EmbeddedSignupTest and the
 * qualification case in CrmSalesAutomationTest.
 */
class CrmTrashTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'crm-trash-secret';

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->company = $this->admin->defaultCompany();
        // Reseller levels belong to the reseller module, which a company switches on.
        $this->company->forceFill(['reseller_module_enabled' => true])->save();
        app(CompanyContext::class)->set($this->company->fresh());
        $this->session(['current_company_id' => $this->company->getKey()]);
    }

    /** @return array<string, array{0: class-string, 1: class-string, 2: string}> */
    public static function trashedResources(): array
    {
        return [
            'leads' => [LeadResource::class, Lead::class, 'leads'],
            'quotations' => [QuotationResource::class, Quotation::class, 'quotations'],
            'chat channels' => [ConversationChannelResource::class, ConversationChannel::class, 'conversation_channels'],
            'reseller levels' => [ResellerLevelResource::class, ResellerLevel::class, 'reseller_levels'],
        ];
    }

    #[DataProvider('trashedResources')]
    public function test_leads_quotations_channels_and_levels_have_a_trash(string $resource, string $model, string $table): void
    {
        $this->actingAs($this->admin);

        $this->assertTrue(Schema::hasColumn($table, 'deleted_at'));
        $this->assertTrue(TypeToConfirmDelete::usesSoftDeletes($model));
        $this->assertTrue($resource::hasPage('trash'));
        $this->assertTrue($resource::canRestoreAny());
        $this->assertTrue($resource::canForceDeleteAny());
    }

    public function test_lead_activities_have_a_trash_inside_the_lead(): void
    {
        $this->assertTrue(Schema::hasColumn('lead_activities', 'deleted_at'));
        $this->assertTrue(TypeToConfirmDelete::usesSoftDeletes(LeadActivity::class));
    }

    /**
     * Who may use a Trash is whoever may delete that record (plan question 5). Leads, quotations and
     * lead activities need the `crm.delete` permission (owner decision 2026-11-03, plan question 10);
     * reseller levels are guarded by the sales module and chat channels are Super Admin only.
     */
    public function test_someone_who_may_not_delete_cannot_use_the_trash(): void
    {
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        $this->actingAs($staff);

        foreach ([ResellerLevelResource::class, ConversationChannelResource::class, LeadResource::class, QuotationResource::class] as $resource) {
            $this->assertFalse($resource::canDeleteAny(), $resource);
            $this->assertFalse($resource::canRestoreAny(), $resource);
            $this->assertFalse($resource::canForceDeleteAny(), $resource);
        }
    }

    public function test_the_crm_delete_permission_is_given_to_no_built_in_role_except_super_admin(): void
    {
        $this->assertArrayHasKey('crm.delete', User::CUSTOM_PERMISSION_OPTIONS);

        foreach (User::ROLE_PERMISSIONS as $role => $permissions) {
            $this->assertSame($role === 'super_admin', in_array('crm.delete', $permissions, true) || in_array('*', $permissions, true), $role);
        }

        // Sales staff and managers can work the CRM (view and manage) but not delete in it.
        foreach (['manager', 'sales_staff'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);

            $this->assertTrue($user->hasPermission('crm.manage'), $role);
            $this->assertFalse($user->canDeleteCrmRecords(), $role);
        }
    }

    public function test_without_the_crm_delete_permission_a_lead_a_quotation_and_an_activity_cannot_be_deleted_or_restored(): void
    {
        $lead = $this->lead('Guarded Lead', '01811000060');
        $activity = $lead->activities()->create(['type' => 'call', 'note' => 'Guarded call']);
        $quotation = Quotation::query()->create(['lead_id' => $lead->getKey(), 'status' => 'draft']);
        $trashedLead = $this->lead('Already Trashed', '01811000061');
        $trashedLead->delete();
        $trashedQuotation = Quotation::query()->create(['status' => 'draft']);
        $trashedQuotation->delete();
        $params = ['ownerRecord' => $lead, 'pageClass' => EditLead::class];

        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $this->grantCompany($staff);

        Livewire::actingAs($staff)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertActionHidden('delete');
        Livewire::actingAs($staff)
            ->test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
            ->assertActionHidden('delete');
        Livewire::actingAs($staff)
            ->test(ActivitiesRelationManager::class, $params)
            ->assertTableActionHidden('delete', $activity)
            ->assertTableActionHidden('trash');

        // The Trash pages are closed, and the "Trash (n)" button is not offered.
        $this->actingAs($staff);
        $this->assertFalse(TrashedLeads::canAccess());
        $this->assertFalse(TrashedQuotations::canAccess());

        Livewire::actingAs($staff)->test(TrashedLeads::class)->assertForbidden();
        Livewire::actingAs($staff)->test(TrashedQuotations::class)->assertForbidden();

        $this->assertNotSoftDeleted($lead);
        $this->assertNotSoftDeleted($activity);
        $this->assertNotSoftDeleted($quotation);
        $this->assertSoftDeleted($trashedLead);
        $this->assertSoftDeleted($trashedQuotation);
    }

    public function test_a_role_with_the_crm_delete_permission_can_delete_restore_and_delete_permanently(): void
    {
        UserRole::query()->create([
            'name' => 'CRM Cleaner',
            'slug' => 'crm_cleaner',
            'permissions' => ['crm.view', 'crm.manage', 'crm.delete'],
            'is_active' => true,
        ]);
        $cleaner = User::factory()->create(['role' => 'crm_cleaner', 'is_active' => true]);
        $this->grantCompany($cleaner);

        $lead = $this->lead('Cleaner Lead', '01811000062');
        $activity = $lead->activities()->create(['type' => 'call', 'note' => 'Cleaner call']);
        $quotation = Quotation::query()->create(['status' => 'draft']);
        $params = ['ownerRecord' => $lead, 'pageClass' => EditLead::class];

        $this->actingAs($cleaner);
        $this->assertTrue($cleaner->canDeleteCrmRecords());
        $this->assertTrue(TrashedLeads::canAccess());
        $this->assertTrue(TrashedQuotations::canAccess());

        Livewire::actingAs($cleaner)
            ->test(ActivitiesRelationManager::class, $params)
            ->callTableAction('delete', $activity, data: ['confirmation' => 'Call #'.$activity->getKey()])
            ->assertHasNoTableActionErrors();
        $this->assertSoftDeleted($activity);

        Livewire::actingAs($cleaner)
            ->test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
            ->callAction('delete', data: ['confirmation' => $quotation->quotation_number])
            ->assertHasNoActionErrors();
        $this->assertSoftDeleted($quotation);

        Livewire::actingAs($cleaner)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('delete', data: ['confirmation' => 'Cleaner Lead'])
            ->assertHasNoActionErrors();
        $this->assertSoftDeleted($lead);

        Livewire::actingAs($cleaner)
            ->test(TrashedLeads::class)
            ->callTableAction('restore', $lead)
            ->assertHasNoTableActionErrors();
        $this->assertNotSoftDeleted($lead);

        Livewire::actingAs($cleaner)
            ->test(TrashedQuotations::class)
            ->callTableAction('forceDelete', $quotation, data: ['confirmation' => $quotation->quotation_number])
            ->assertHasNoTableActionErrors();
        $this->assertDatabaseMissing('quotations', ['id' => $quotation->getKey()]);
    }

    // ------------------------------------------------------------------ leads

    public function test_a_lead_goes_to_trash_by_name_and_keeps_its_activities_and_quotations(): void
    {
        $lead = $this->lead('Karim Traders', '01811000001');
        $activity = $lead->activities()->create(['type' => 'call', 'note' => 'Asked for prices']);
        $quotation = Quotation::query()->create(['lead_id' => $lead->getKey(), 'status' => 'draft']);

        Livewire::actingAs($this->admin)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('delete', data: ['confirmation' => 'Someone else'])
            ->assertHasActionErrors(['confirmation']);
        $this->assertNotSoftDeleted($lead);

        Livewire::actingAs($this->admin)
            ->test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('delete', data: ['confirmation' => 'Karim Traders'])
            ->assertHasNoActionErrors();

        $this->assertSoftDeleted($lead);
        $this->assertNull(Lead::query()->find($lead->getKey()));
        // Nothing hanging off the lead is touched, and the quotation still shows who it was for.
        $this->assertNotSoftDeleted($activity);
        $this->assertNotSoftDeleted($quotation);
        $this->assertSame('Karim Traders', $quotation->fresh()->lead->name);

        Livewire::actingAs($this->admin)
            ->test(TrashedLeads::class)
            ->assertCanSeeTableRecords([$lead])
            ->callTableAction('restore', $lead)
            ->assertHasNoTableActionErrors();

        $this->assertNotSoftDeleted($lead);
        $this->assertSame(1, $lead->fresh()->activities()->count());
    }

    public function test_a_lead_in_trash_gets_no_reminder_no_broadcast_and_leaves_needs_attention(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $due = $this->lead('Due Lead', '01811000010', ['assigned_to' => $agent->getKey(), 'next_follow_up_at' => now()->subHour()]);
        $kept = $this->lead('Kept Lead', '01811000011');
        $kept->forceFill(['status' => 'contacted'])->saveQuietly();
        app(AttentionQueueService::class)->openedForLead($due);
        $this->assertSame(1, AttentionItem::query()->open()->count());

        $broadcast = Broadcast::query()->create([
            'name' => 'All leads', 'audience_type' => 'leads', 'channel' => 'sms', 'sms_body' => 'Hi {{name}}', 'status' => 'draft',
        ]);
        $this->assertSame(2, app(BroadcastService::class)->buildRecipients($broadcast));

        $due->delete();

        // Left out of the broadcast, the follow-up reminders and the Needs attention list/badge...
        $this->assertSame(1, app(BroadcastService::class)->buildRecipients($broadcast->fresh()));
        Artisan::call('crm:send-follow-up-reminders');
        $this->assertSame(0, $agent->notifications()->count());
        $this->assertNull($due->fresh()?->follow_up_reminded_at);
        $this->assertSame(0, AttentionItem::query()->open()->count());

        // ...and all of it comes back with the lead.
        $due->restore();
        $this->assertSame(1, AttentionItem::query()->open()->count());
        Artisan::call('crm:send-follow-up-reminders');
        $this->assertSame(1, $agent->notifications()->count());
    }

    public function test_an_opt_out_still_holds_for_a_lead_in_trash(): void
    {
        $lead = $this->lead('Opted Out', '01811000020', ['opted_out_at' => now()]);
        $lead->delete();

        $this->assertTrue(app(ContactSuppressionService::class)->suppressed((int) $this->company->getKey(), '01811000020'));
    }

    public function test_the_websites_retry_of_a_lead_in_trash_gets_that_lead_instead_of_an_error(): void
    {
        [, $token] = CompanyApiKey::generate($this->company);
        $first = $this->postJson('/api/v1/leads', [
            'name' => 'Web Lead', 'phone' => '01811000030', 'external_reference' => 'WEB-77',
        ], ['Authorization' => "Bearer {$token}"])->assertCreated();

        Lead::query()->findOrFail($first->json('data.id'))->delete();

        // (company, external_reference) is unique: the retry must find the trashed lead, not insert a second.
        $this->postJson('/api/v1/leads', [
            'name' => 'Web Lead', 'phone' => '01811000030', 'external_reference' => 'WEB-77',
        ], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Lead::withTrashed()->where('external_reference', 'WEB-77')->count());
    }

    public function test_a_quotation_cannot_turn_a_lead_in_trash_into_a_customer(): void
    {
        $lead = $this->lead('Trashed Buyer', '01811000040');
        $quotation = Quotation::query()->create(['lead_id' => $lead->getKey(), 'status' => 'accepted']);
        $lead->delete();

        try {
            app(LeadConversionService::class)->convertQuotationToOrder($quotation->fresh());
            $this->fail('A lead in Trash must not be converted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Trash', $exception->getMessage());
        }

        $this->assertNull(Customer::query()->where('phone', '01811000040')->first());
        $this->assertNull($lead->fresh()?->converted_customer_id);
    }

    public function test_a_lead_activity_goes_to_trash_inside_the_lead_and_comes_back_on_restore(): void
    {
        $lead = $this->lead('Activity Lead', '01811000050');
        $activity = $lead->activities()->create(['type' => 'call', 'note' => 'First call']);
        $params = ['ownerRecord' => $lead, 'pageClass' => EditLead::class];
        $name = 'Call #'.$activity->getKey();

        Livewire::actingAs($this->admin)
            ->test(ActivitiesRelationManager::class, $params)
            ->callTableAction('delete', $activity, data: ['confirmation' => 'wrong'])
            ->assertHasTableActionErrors(['confirmation']);
        $this->assertNotSoftDeleted($activity);

        Livewire::actingAs($this->admin)
            ->test(ActivitiesRelationManager::class, $params)
            ->callTableAction('delete', $activity, data: ['confirmation' => $name])
            ->assertHasNoTableActionErrors();
        $this->assertSoftDeleted($activity);

        Livewire::actingAs($this->admin)
            ->test(ActivitiesRelationManager::class, $params)
            ->assertCanNotSeeTableRecords([$activity])
            ->set('showTrash', true)
            ->assertCanSeeTableRecords([$activity])
            ->callTableAction('restore', $activity)
            ->assertHasNoTableActionErrors();
        $this->assertNotSoftDeleted($activity);
    }

    // ------------------------------------------------------------- quotations

    public function test_a_quotation_goes_to_trash_keeps_its_number_reserved_and_comes_back_on_restore(): void
    {
        $first = Quotation::query()->create(['status' => 'draft']);
        $second = Quotation::query()->create(['status' => 'draft']);

        Livewire::actingAs($this->admin)
            ->test(EditQuotation::class, ['record' => $second->getRouteKey()])
            ->callAction('delete', data: ['confirmation' => $second->quotation_number])
            ->assertHasNoActionErrors();
        $this->assertSoftDeleted($second);

        // The number of a quotation in Trash is never given out again.
        $third = Quotation::query()->create(['status' => 'draft']);
        $this->assertNotSame($second->quotation_number, $third->quotation_number);
        $this->assertNotSame($first->quotation_number, $third->quotation_number);

        Livewire::actingAs($this->admin)
            ->test(TrashedQuotations::class)
            ->assertCanSeeTableRecords([$second])
            ->callTableAction('restore', $second)
            ->assertHasNoTableActionErrors();
        $this->assertNotSoftDeleted($second);
    }

    public function test_a_quotation_in_trash_is_gone_for_the_customer_and_left_alone_by_the_expiry_job(): void
    {
        $quotation = Quotation::query()->create(['status' => 'sent', 'valid_until' => now()->subDay()->toDateString()]);
        $link = URL::signedRoute('quotation.public', ['quotationNumber' => $quotation->quotation_number]);
        $quotation->delete();

        app(CompanyContext::class)->clear();
        $this->get($link)->assertNotFound();

        Artisan::call('quotations:mark-expired');
        $this->assertSame('sent', Quotation::withTrashed()->findOrFail($quotation->getKey())->status);

        $quotation->restore();
        Artisan::call('quotations:mark-expired');
        $this->assertSame('expired', $quotation->fresh()->status);
    }

    // --------------------------------------------------------- chat channels

    public function test_a_channel_in_trash_receives_no_webhook_and_receives_again_when_restored(): void
    {
        Queue::fake();
        $channel = $this->channel('1000000001');

        $this->postMetaMessage($channel, 'wamid.LIVE')->assertOk();
        $this->assertSame(1, ConversationMessage::query()->count());

        $seenBefore = $channel->fresh()->last_webhook_at;

        $channel->delete();
        $this->postMetaMessage($channel, 'wamid.TRASH')->assertNotFound();
        $this->assertSame(1, ConversationMessage::query()->count());
        // The channel in Trash is not even touched: no "webhook received" stamp, no diagnostic error.
        $trashed = ConversationChannel::withTrashed()->findOrFail($channel->getKey());
        $this->assertEquals($seenBefore, $trashed->last_webhook_at);
        $this->assertNull($trashed->last_error);

        $channel->restore();
        $this->postMetaMessage($channel, 'wamid.BACK')->assertOk();
        $this->assertSame(2, ConversationMessage::query()->count());
    }

    public function test_the_meta_verification_handshake_refuses_a_channel_in_trash(): void
    {
        $channel = $this->channel('1000000002');
        $url = '/webhooks/meta?hub_mode=subscribe&hub_verify_token=crm-trash-verify&hub_challenge=PING';

        $this->get($url)->assertOk()->assertSee('PING');

        $channel->delete();
        $this->get($url)->assertForbidden();
    }

    public function test_a_web_archive_channel_in_trash_answers_the_bridge_with_not_found(): void
    {
        $channel = ConversationChannel::query()->create([
            'provider' => 'whatsapp', 'connection_type' => 'web', 'web_status' => 'connected', 'external_id' => 'web-trash-session',
            'display_name' => 'QR channel', 'app_secret' => self::APP_SECRET, 'auto_create_leads' => false, 'is_active' => true,
        ]);
        $post = function () use ($channel) {
            $body = json_encode(['type' => 'connection', 'session_id' => 'web-trash-session', 'data' => ['status' => 'connected']]);

            return $this->call('POST', "/webhooks/whatsapp-web/{$channel->getKey()}", [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_BRIDGE_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET),
            ], $body);
        };

        $post()->assertOk();

        $channel->delete();
        $post()->assertNotFound();

        $channel->restore();
        $post()->assertOk();
    }

    public function test_nothing_is_sent_through_a_channel_in_trash_and_its_chats_stay(): void
    {
        $channel = $this->channel('1000000003');
        $conversation = Conversation::query()->create([
            'channel_id' => $channel->getKey(), 'provider' => 'whatsapp', 'external_contact_id' => '8801812345678',
            'contact_phone' => '01812345678', 'contact_name' => 'Rina', 'status' => 'open',
        ]);
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'Hello', 'sent_at' => now()]);

        $channel->delete();
        Http::fake();

        // The attempt is archived as a failed message ("no active channel"); nothing goes out to Meta.
        $sent = app(ConversationMessengerService::class)->send($conversation->fresh(), 'Reply');
        $this->assertSame('failed', $sent->delivery_status);
        Http::assertNothingSent();

        // The chat and its messages stay, still linked, and the channel is simply not found while it is in Trash.
        $this->assertSame($channel->getKey(), $conversation->fresh()->channel_id);
        $this->assertNull($conversation->fresh()->channel);
        $this->assertSame(1, $conversation->messages()->where('direction', 'incoming')->count());

        $channel->restore();
        $this->assertSame($channel->getKey(), $conversation->fresh()->channel?->getKey());
    }

    public function test_the_inbox_opens_a_chat_whose_channel_is_in_trash(): void
    {
        $channel = $this->channel('1000000004');
        $conversation = Conversation::query()->create([
            'channel_id' => $channel->getKey(), 'provider' => 'whatsapp', 'external_contact_id' => '8801812345679',
            'contact_phone' => '01812345679', 'contact_name' => 'Karim', 'status' => 'open',
        ]);
        $conversation->messages()->create(['direction' => 'incoming', 'type' => 'text', 'body' => 'Still here', 'sent_at' => now()]);
        $channel->delete();

        Livewire::actingAs($this->admin)
            ->test(Inbox::class)
            ->call('selectConversation', $conversation->getKey())
            ->assertOk()
            ->assertSee('Still here');
    }

    public function test_a_channel_goes_to_trash_by_name_and_its_phone_number_id_stays_reserved(): void
    {
        $channel = $this->channel('1000000005', 'Main Page');

        Livewire::actingAs($this->admin)
            ->test(EditConversationChannel::class, ['record' => $channel->getRouteKey()])
            ->callAction('delete', data: ['confirmation' => 'Main Page'])
            ->assertHasNoActionErrors();
        $this->assertSoftDeleted($channel);

        // The Phone Number ID is unique, so the form says why it is refused and where the channel is.
        Livewire::actingAs($this->admin)
            ->test(CreateConversationChannel::class)
            ->fillForm([
                'provider' => 'whatsapp', 'display_name' => 'Second', 'external_id' => '1000000005', 'waba_id' => 'WABA-2',
                'access_token' => 'x', 'app_secret' => 'y', 'verify_token' => 'z',
            ])
            ->call('create')
            ->assertHasFormErrors(['external_id' => 'unique']);

        Livewire::actingAs($this->admin)
            ->test(TrashedConversationChannels::class)
            ->assertCanSeeTableRecords([$channel])
            ->callTableAction('restore', $channel)
            ->assertHasNoTableActionErrors();
        $this->assertNotSoftDeleted($channel);
    }

    /**
     * A channel is found by webhooks, jobs and pickers with `withoutGlobalScopes()`, which also drops
     * the Trash filter. Every such lookup must add `->withoutTrashed()`, or a channel in Trash would
     * go on receiving and sending. The only place that may see Trash is reconnecting a number
     * (EmbeddedSignupService restores it); the web-archive "read-only" guard is a relation lookup.
     */
    public function test_every_channel_lookup_that_drops_global_scopes_skips_the_trash(): void
    {
        $allowed = ['Services/Meta/EmbeddedSignupService.php'];
        $offenders = [];
        $pattern = '/ConversationChannel::(?:query\(\)\s*->)?withoutGlobalScopes\(\)(?!\s*->withoutTrashed\(\))/';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(app_path()) + 1));

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            if (preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'These files find a ConversationChannel without skipping the Trash: '.implode(', ', $offenders));
    }

    // --------------------------------------------------------------- helpers

    /** Gives a staff account access to the test company with its own role. */
    private function grantCompany(User $user): void
    {
        $user->companies()->syncWithoutDetaching([
            $this->company->getKey() => ['role' => $user->role, 'is_default' => true],
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function lead(string $name, string $phone, array $extra = []): Lead
    {
        return Lead::query()->create([...['name' => $name, 'phone' => $phone, 'source' => 'website', 'status' => 'new'], ...$extra]);
    }

    private function channel(string $phoneNumberId, string $name = 'Sales WhatsApp'): ConversationChannel
    {
        return ConversationChannel::query()->create([
            'provider' => 'whatsapp',
            'external_id' => $phoneNumberId,
            'waba_id' => 'WABA-'.$phoneNumberId,
            'display_name' => $name,
            'access_token' => 'test-access-token',
            'app_secret' => self::APP_SECRET,
            'verify_token' => 'crm-trash-verify',
            'auto_create_leads' => false,
            'is_active' => true,
        ]);
    }

    private function postMetaMessage(ConversationChannel $channel, string $messageId)
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $channel->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => $channel->external_id],
                        'contacts' => [['profile' => ['name' => 'Rina'], 'wa_id' => '8801812345678']],
                        'messages' => [[
                            'from' => '8801812345678', 'id' => $messageId, 'timestamp' => (string) now()->timestamp,
                            'type' => 'text', 'text' => ['body' => 'Hello '.$messageId],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/webhooks/meta', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET),
        ]), $body);
    }
}
