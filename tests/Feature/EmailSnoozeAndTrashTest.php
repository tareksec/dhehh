<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailInbox;
use App\Filament\Pages\EmailInboxSettings;
use App\Models\Company;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\Email\Inbox\EmailInboxMaintenance;
use App\Services\Email\Inbox\EmailThreadActions;
use App\Services\Email\Inbox\InboundEmailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsEmailInbox;
use Tests\TestCase;

/**
 * Email inbox, Phase 2 step 8 (docs/email-inbox-plan.md): snooze (hide a conversation from the Inbox until a time, then it
 * comes back unread and the person is told), the Snoozed view, a new message ending a snooze, and the Trash that empties
 * itself after the number of days each mailbox sets.
 */
class EmailSnoozeAndTrashTest extends TestCase
{
    use BuildsEmailInbox;
    use RefreshDatabase;

    private Company $company;

    private EmailMailbox $mailbox;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        URL::forceScheme('https');
        $this->company = $this->makeCompany('Mail Co', 'mail-co');
        app(CompanyContext::class)->set($this->company);
        $this->mailbox = $this->makeMailbox($this->company, 'info@shop.test');
        $this->user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $this->user->companies()->attach($this->company->getKey(), ['role' => 'sales_staff', 'is_default' => true]);
        Auth::login($this->user);
    }

    // --- Snooze -----------------------------------------------------------------------------------------

    public function test_a_snoozed_conversation_leaves_the_inbox_and_shows_in_the_snoozed_view(): void
    {
        $kept = $this->received('Stays');
        $snoozed = $this->received('Later please');

        $page = Livewire::test(EmailInbox::class)->set('selected', [(string) $snoozed->getKey()])->call('snoozeSelected', 'tomorrow');

        $fresh = $snoozed->fresh();
        $this->assertNotNull($fresh->snoozed_until);
        $this->assertTrue($fresh->snoozed_until->isFuture());
        $this->assertSame($this->user->getKey(), (int) $fresh->snoozed_by);
        $this->assertSame(EmailThread::FOLDER_INBOX, $fresh->folder);

        $page->assertSee('Stays')->assertDontSee('Later please')->assertSet('selected', []);

        Livewire::test(EmailInbox::class)->call('showFolder', 'snoozed')->assertSee('Later please')->assertDontSee('Stays');
        $this->assertNull($kept->fresh()->snoozed_until);
    }

    public function test_the_ready_made_times_are_ahead_and_in_the_company_time_zone(): void
    {
        $this->company->forceFill(['timezone' => 'Asia/Dhaka'])->save();
        $thread = $this->received('Morning');

        Livewire::test(EmailInbox::class)->call('snooze', 'tomorrow', [$thread->getKey()]);

        $until = $thread->fresh()->snoozed_until->copy()->timezone('Asia/Dhaka');
        $this->assertSame('08:00', $until->format('H:i'));
        $this->assertTrue($until->isTomorrow());
    }

    public function test_a_typed_time_is_read_in_the_company_time_zone_and_must_be_ahead(): void
    {
        $this->company->forceFill(['timezone' => 'Asia/Dhaka'])->save();
        $thread = $this->received('Pick');
        $when = now('Asia/Dhaka')->addDays(2)->setTime(15, 30);

        Livewire::test(EmailInbox::class)
            ->set('snoozeCustom', $when->format('Y-m-d\TH:i'))
            ->call('snooze', 'custom', [$thread->getKey()])
            ->assertSet('snoozeCustom', '');

        $this->assertTrue($thread->fresh()->snoozed_until->equalTo($when));

        $past = $this->received('Past');
        Livewire::test(EmailInbox::class)
            ->set('snoozeCustom', now('Asia/Dhaka')->subHour()->format('Y-m-d\TH:i'))
            ->call('snooze', 'custom', [$past->getKey()]);
        $this->assertNull($past->fresh()->snoozed_until);

        $far = $this->received('Far');
        Livewire::test(EmailInbox::class)
            ->set('snoozeCustom', now('Asia/Dhaka')->addYears(2)->format('Y-m-d\TH:i'))
            ->call('snooze', 'custom', [$far->getKey()]);
        $this->assertNull($far->fresh()->snoozed_until);

        Livewire::test(EmailInbox::class)->set('snoozeCustom', 'tomorrow-ish')->call('snooze', 'custom', [$far->getKey()]);
        $this->assertNull($far->fresh()->snoozed_until);
        Livewire::test(EmailInbox::class)->call('snooze', 'nonsense', [$far->getKey()]);
        $this->assertNull($far->fresh()->snoozed_until);
    }

    public function test_only_conversations_in_the_inbox_can_be_snoozed(): void
    {
        $archived = $this->received('Old', folder: EmailThread::FOLDER_ARCHIVED);
        $spam = $this->received('Junk', folder: EmailThread::FOLDER_SPAM);

        Livewire::test(EmailInbox::class)->call('snooze', 'later', [$archived->getKey(), $spam->getKey()]);

        $this->assertNull($archived->fresh()->snoozed_until);
        $this->assertNull($spam->fresh()->snoozed_until);
    }

    public function test_the_open_conversation_can_be_snoozed_and_undone(): void
    {
        $thread = $this->received('Open one');

        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())->call('snoozeThread', 'next_week');

        $page->assertSet('threadId', null);
        $this->assertNotNull($thread->fresh()->snoozed_until);
        $this->assertSame([$thread->getKey()], $page->get('undo')['snoozed']);

        $page->call('undoMove');
        $this->assertNull($thread->fresh()->snoozed_until);
        $this->assertNull($thread->fresh()->snoozed_by);
    }

    public function test_unsnoozing_brings_a_conversation_back_at_once(): void
    {
        $thread = $this->received('Back now');
        Livewire::test(EmailInbox::class)->call('snooze', 'tomorrow', [$thread->getKey()]);
        $this->assertNotNull($thread->fresh()->snoozed_until);

        Livewire::test(EmailInbox::class)->call('showFolder', 'snoozed')->call('perform', 'unsnooze', [$thread->getKey()]);

        $this->assertNull($thread->fresh()->snoozed_until);
        Livewire::test(EmailInbox::class)->assertSee('Back now');
    }

    public function test_moving_a_snoozed_conversation_out_of_the_inbox_ends_the_snooze(): void
    {
        $thread = $this->received('Archive me');
        Livewire::test(EmailInbox::class)->call('snooze', 'tomorrow', [$thread->getKey()]);

        Livewire::test(EmailInbox::class)->call('showFolder', 'snoozed')->call('perform', 'archive', [$thread->getKey()]);

        $fresh = $thread->fresh();
        $this->assertSame(EmailThread::FOLDER_ARCHIVED, $fresh->folder);
        $this->assertNull($fresh->snoozed_until);
    }

    public function test_the_inbox_count_and_the_menu_badge_leave_out_snoozed_conversations(): void
    {
        $this->received('Visible');
        $hidden = $this->received('Hidden');
        Livewire::test(EmailInbox::class)->call('snooze', 'tomorrow', [$hidden->getKey()]);

        $this->assertSame(1, Livewire::test(EmailInbox::class)->instance()->counts['inbox']);
        $this->assertSame('1', (fn () => static::navigationBadge())->bindTo(null, EmailInbox::class)());
    }

    public function test_a_new_message_on_a_snoozed_conversation_brings_it_back(): void
    {
        $thread = $this->received('Needs answer', from: 'rahim@x.test', read: true, messageId: 'first@x.test');
        Livewire::test(EmailInbox::class)->call('snooze', 'tomorrow', [$thread->getKey()]);

        $raw = "Message-ID: <second@x.test>\r\nIn-Reply-To: <first@x.test>\r\nFrom: Rahim <rahim@x.test>\r\nTo: info@shop.test\r\nSubject: Re: Needs answer\r\nDate: ".now()->toRfc2822String()."\r\nContent-Type: text/plain\r\n\r\nAny news?";
        app(InboundEmailService::class)->receive($this->mailbox, $raw);

        $fresh = $thread->fresh();
        $this->assertNull($fresh->snoozed_until);
        $this->assertNull($fresh->snoozed_by);
        $this->assertSame(1, $fresh->unread_count);
    }

    public function test_snoozing_needs_the_manage_permission_and_stays_inside_the_company(): void
    {
        $thread = $this->received('Mine');
        $other = $this->makeCompany('Other Co', 'other-co');
        $foreignBox = $this->makeMailbox($other, 'info@other.test');
        $foreign = $this->received('Not yours', company: $other, mailbox: $foreignBox);
        app(CompanyContext::class)->set($this->company);

        Livewire::test(EmailInbox::class)->call('snooze', 'tomorrow', [$foreign->getKey()]);
        $this->assertNull($foreign->fresh()->snoozed_until);

        UserRole::query()->create(['name' => 'Mail viewer', 'slug' => 'mail_viewer', 'permissions' => ['crm.view'], 'is_active' => true]);
        Auth::login(User::factory()->create(['role' => 'mail_viewer', 'is_active' => true]));

        Livewire::test(EmailInbox::class)->call('snooze', 'tomorrow', [$thread->getKey()])->assertForbidden();
        Livewire::test(EmailInbox::class)->call('snoozeSelected', 'tomorrow')->assertForbidden();
        $this->assertNull($thread->fresh()->snoozed_until);
    }

    public function test_the_actions_refuse_a_time_in_the_past(): void
    {
        $thread = $this->received('Late');

        $this->assertSame(0, app(EmailThreadActions::class)->snooze([$thread->getKey()], now()->subMinute(), $this->user));
        $this->assertSame(1, app(EmailThreadActions::class)->snooze([$thread->getKey()], now()->addHour(), $this->user));
    }

    // --- Waking up --------------------------------------------------------------------------------------

    public function test_the_wake_command_brings_back_what_is_due_unread_and_tells_the_person(): void
    {
        $due = $this->received('Due now', read: true);
        $later = $this->received('Not yet', read: true);
        app(EmailThreadActions::class)->snooze([$due->getKey(), $later->getKey()], now()->addHour(), $this->user);
        DB::table('email_threads')->where('id', $due->getKey())->update(['snoozed_until' => now()->subMinute()]);

        $this->artisan('email:wake-snoozed')->expectsOutputToContain('Brought back 1 conversation(s).')->assertSuccessful();

        $fresh = $due->fresh();
        $this->assertNull($fresh->snoozed_until);
        $this->assertNull($fresh->snoozed_by);
        $this->assertSame(1, $fresh->unread_count);
        $this->assertNotNull($later->fresh()->snoozed_until);

        $this->assertSame(1, $this->user->notifications()->where('data->alert_kind', 'email.snooze_due')->count());
    }

    public function test_waking_twice_wakes_once(): void
    {
        $thread = $this->received('Once', read: true);
        app(EmailThreadActions::class)->snooze([$thread->getKey()], now()->addHour(), $this->user);
        DB::table('email_threads')->where('id', $thread->getKey())->update(['snoozed_until' => now()->subMinute()]);

        $maintenance = app(EmailInboxMaintenance::class);
        $this->assertSame(1, $maintenance->wakeSnoozed());
        $this->assertSame(0, $maintenance->wakeSnoozed());
        $this->assertSame(1, $this->user->notifications()->where('data->alert_kind', 'email.snooze_due')->count());
    }

    public function test_the_wake_command_works_company_by_company(): void
    {
        $other = $this->makeCompany('Other Co', 'other-co');
        $otherBox = $this->makeMailbox($other, 'info@other.test');
        $mine = $this->received('Mine', read: true);
        $theirs = $this->received('Theirs', read: true, company: $other, mailbox: $otherBox);
        DB::table('email_threads')->whereIn('id', [$mine->getKey(), $theirs->getKey()])->update(['snoozed_until' => now()->subMinute()]);
        app(CompanyContext::class)->clear();

        $this->artisan('email:wake-snoozed')->expectsOutputToContain('Brought back 2 conversation(s).')->assertSuccessful();

        $this->assertNull(EmailThread::withoutGlobalScopes()->find($mine->getKey())->snoozed_until);
        $this->assertNull(EmailThread::withoutGlobalScopes()->find($theirs->getKey())->snoozed_until);
    }

    // --- Trash that empties itself --------------------------------------------------------------------------

    public function test_moving_to_trash_notes_the_time_and_moving_out_clears_it(): void
    {
        $thread = $this->received('Bin');

        app(EmailThreadActions::class)->move([$thread->getKey()], EmailThread::FOLDER_TRASH);
        $trashedAt = $thread->fresh()->trashed_at;
        $this->assertNotNull($trashedAt);

        $this->travel(2)->hours();
        app(EmailThreadActions::class)->move([$thread->getKey()], EmailThread::FOLDER_TRASH);
        $this->assertTrue($thread->fresh()->trashed_at->equalTo($trashedAt), 'Trashing again must not restart the clock.');

        app(EmailThreadActions::class)->move([$thread->getKey()], EmailThread::FOLDER_INBOX);
        $this->assertNull($thread->fresh()->trashed_at);
    }

    public function test_a_reply_to_a_deleted_conversation_clears_its_trash_time(): void
    {
        $thread = $this->received('Bin', messageId: 'first@x.test', read: true);
        app(EmailThreadActions::class)->move([$thread->getKey()], EmailThread::FOLDER_TRASH);

        $raw = "Message-ID: <again@x.test>\r\nIn-Reply-To: <first@x.test>\r\nFrom: Rahim <rahim@x.test>\r\nTo: info@shop.test\r\nSubject: Re: Bin\r\nDate: ".now()->toRfc2822String()."\r\nContent-Type: text/plain\r\n\r\nHello?";
        app(InboundEmailService::class)->receive($this->mailbox, $raw);

        $fresh = $thread->fresh();
        $this->assertSame(EmailThread::FOLDER_INBOX, $fresh->folder);
        $this->assertNull($fresh->trashed_at);
    }

    public function test_the_purge_command_deletes_only_what_has_been_in_trash_long_enough(): void
    {
        $old = $this->received('Old bin');
        $recent = $this->received('Recent bin');
        $live = $this->received('Alive');
        app(EmailThreadActions::class)->move([$old->getKey(), $recent->getKey()], EmailThread::FOLDER_TRASH);
        DB::table('email_threads')->where('id', $old->getKey())->update(['trashed_at' => now()->subDays(31)]);
        DB::table('email_threads')->where('id', $recent->getKey())->update(['trashed_at' => now()->subDays(29)]);

        $this->artisan('email:purge-trash')->expectsOutputToContain('Deleted 1 conversation(s) from Trash.')->assertSuccessful();

        $this->assertNull(EmailThread::query()->find($old->getKey()));
        $this->assertNotNull(EmailThread::query()->find($recent->getKey()));
        $this->assertNotNull(EmailThread::query()->find($live->getKey()));
        $this->assertSame(0, EmailMessage::query()->where('thread_id', $old->getKey())->count());
    }

    public function test_a_mailbox_can_keep_its_trash_longer_or_forever(): void
    {
        $this->mailbox->forceFill(['settings' => ['trash_days' => 0]])->save();
        $quiet = $this->received('Kept');
        $second = $this->makeMailbox($this->company, 'sales@shop.test');
        $week = $this->received('Week old', mailbox: $second);
        $second->forceFill(['settings' => ['trash_days' => 7]])->save();
        app(EmailThreadActions::class)->move([$quiet->getKey(), $week->getKey()], EmailThread::FOLDER_TRASH);
        DB::table('email_threads')->whereIn('id', [$quiet->getKey(), $week->getKey()])->update(['trashed_at' => now()->subDays(8)]);

        $this->assertSame(1, app(EmailInboxMaintenance::class)->purgeTrash());

        $this->assertNotNull(EmailThread::query()->find($quiet->getKey()));
        $this->assertNull(EmailThread::query()->find($week->getKey()));
    }

    public function test_a_trashed_conversation_without_a_trash_time_counts_from_its_last_change(): void
    {
        $thread = $this->received('Odd one', folder: EmailThread::FOLDER_TRASH);
        DB::table('email_threads')->where('id', $thread->getKey())->update(['trashed_at' => null, 'updated_at' => now()->subDays(40)]);

        $this->assertSame(1, app(EmailInboxMaintenance::class)->purgeTrash());
        $this->assertNull(EmailThread::query()->find($thread->getKey()));
    }

    public function test_the_purge_never_touches_another_company(): void
    {
        $other = $this->makeCompany('Other Co', 'other-co');
        $otherBox = $this->makeMailbox($other, 'info@other.test');
        $theirs = $this->received('Theirs', company: $other, mailbox: $otherBox, folder: EmailThread::FOLDER_TRASH);
        DB::table('email_threads')->where('id', $theirs->getKey())->update(['trashed_at' => now()->subDays(90)]);

        $this->assertSame(0, app(EmailInboxMaintenance::class)->purgeTrash());
        $this->assertNotNull(EmailThread::withoutGlobalScopes()->find($theirs->getKey()));

        $this->expectException(AuthorizationException::class);
        app(EmailThreadActions::class)->purgeTrashedBefore($otherBox, now());
    }

    public function test_the_trash_view_says_when_it_empties_itself(): void
    {
        Livewire::test(EmailInbox::class)->call('showFolder', 'trash')->assertSee('deleted for good after 30 days');

        $this->mailbox->forceFill(['settings' => ['trash_days' => 0]])->save();
        Livewire::test(EmailInbox::class)->call('showFolder', 'trash')->assertDontSee('deleted for good after');
    }

    public function test_the_settings_page_changes_the_trash_days(): void
    {
        Auth::login(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        Livewire::test(EmailInboxSettings::class)
            ->assertSet('data.trash_days', 30)
            ->set('data.trash_days', 7)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(7, (int) $this->mailbox->fresh()->setting('trash_days'));

        Livewire::test(EmailInboxSettings::class)->set('data.trash_days', 0)->call('save');
        $this->assertSame(0, (int) $this->mailbox->fresh()->setting('trash_days'));

        Livewire::test(EmailInboxSettings::class)->set('data.trash_days', 11)->call('save')->assertHasErrors(['data.trash_days']);
        $this->assertSame(0, (int) $this->mailbox->fresh()->setting('trash_days'), 'A number that is not one of the choices is refused and nothing changes.');
    }
}
