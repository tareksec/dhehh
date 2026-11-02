<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailInbox;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmailAttachment;
use App\Models\EmailLabel;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Email\Inbox\EmailMessageView;
use App\Services\Email\Inbox\EmailThreadActions;
use App\Services\Email\Inbox\EmailThreadStats;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CRM → Email (owner request 2026-11-02): who may open it, the folders and what each one lists, the numbers beside them,
 * search and the quick filters, opening a conversation, stars, bulk actions with undo, labels, the mailbox switcher,
 * how a message is drawn (sandboxed frame, no remote pictures until asked), file downloads and company isolation.
 */
class EmailInboxPageTest extends TestCase
{
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

    // --- Who may open it ------------------------------------------------------------------------------

    public function test_staff_with_crm_access_open_the_page_and_staff_without_it_do_not(): void
    {
        $this->assertTrue(EmailInbox::canAccess());
        $this->actingAs($this->user)
            ->withSession(['current_company_id' => $this->company->getKey(), 'current_company_selection_explicit' => true])
            ->get(EmailInbox::getUrl())
            ->assertOk()
            ->assertSee('info@shop.test');

        Auth::login(User::factory()->create(['role' => 'accountant', 'is_active' => true]));
        $this->assertFalse(EmailInbox::canAccess());
        Livewire::test(EmailInbox::class)->assertForbidden();
    }

    public function test_a_company_without_a_mailbox_is_told_so(): void
    {
        app(CompanyContext::class)->set($this->makeCompany('Empty Co', 'empty-co'));

        Livewire::test(EmailInbox::class)->assertSee('No mailbox yet');
    }

    // --- Folders --------------------------------------------------------------------------------------

    public function test_each_folder_lists_what_belongs_in_it_and_nothing_from_another_mailbox_or_company(): void
    {
        $inbox = $this->received('In the inbox');
        $archived = $this->received('Archived one', folder: EmailThread::FOLDER_ARCHIVED);
        $starred = $this->received('Starred one', folder: EmailThread::FOLDER_ARCHIVED, starred: true);
        $spam = $this->received('Spam one', folder: EmailThread::FOLDER_SPAM);
        $trash = $this->received('Trash one', folder: EmailThread::FOLDER_TRASH);
        $sent = $this->sentOnly('We wrote first');
        $draftOnly = $this->draftOnly('Unfinished', $this->user);
        $othersDraft = $this->draftOnly('Someone else\'s draft', User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));
        $replied = $this->received('Answered');
        $this->outgoing($replied, 'We answered');

        $otherMailbox = $this->makeMailbox($this->company, 'sales@shop.test');
        $this->received('Other mailbox', mailbox: $otherMailbox);
        $foreign = $this->makeCompany('Foreign Co', 'foreign-co');
        $this->received('Foreign company', company: $foreign, mailbox: $this->makeMailbox($foreign, 'a@foreign.test'));

        $subjects = fn (string $folder): array => Livewire::test(EmailInbox::class)->call('showFolder', $folder)->instance()->threads->pluck('subject')->sort()->values()->all();

        $this->assertSame(['Answered', 'In the inbox'], $subjects('inbox'), 'The Inbox: received, not archived, not only a draft or only sent.');
        $this->assertSame(['Starred one'], $subjects('starred'));
        $this->assertSame(['Answered', 'We wrote first'], $subjects('sent'), 'Sent: every conversation with a message we sent.');
        $this->assertSame(['Unfinished'], $subjects('drafts'), 'Drafts: only the signed-in person\'s own.');
        $this->assertSame(['Spam one'], $subjects('spam'));
        $this->assertSame(['Trash one'], $subjects('trash'));
        $this->assertSame(['Answered', 'Archived one', 'In the inbox', 'Starred one', 'We wrote first'], $subjects('all'), 'All mail: everything except Spam, Trash and drafts.');
        $this->assertSame(['Answered', 'In the inbox'], $subjects('nonsense'), 'An unknown folder is the Inbox.');
    }

    public function test_the_numbers_beside_the_folders_count_unread_conversations_and_own_drafts(): void
    {
        $this->received('Unread 1');
        $this->received('Unread 2');
        $this->received('Already read', read: true);
        $this->received('Unread spam', folder: EmailThread::FOLDER_SPAM);
        $this->received('Unread archived', folder: EmailThread::FOLDER_ARCHIVED);
        $this->draftOnly('My draft', $this->user);
        $this->draftOnly('Their draft', User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));
        $label = EmailLabel::query()->create(['name' => 'Wholesale', 'color' => 'blue']);
        $this->received('Unread labelled', labels: [$label]);

        $page = Livewire::test(EmailInbox::class);
        $counts = $page->instance()->counts;

        $this->assertSame(3, $counts['inbox'], 'Unread conversations in the Inbox.');
        $this->assertSame(1, $counts['spam']);
        $this->assertSame(1, $counts['drafts']);
        $this->assertSame(1, $counts['labels'][$label->getKey()]);
        $page->assertSee('Wholesale');
    }

    public function test_the_menu_badge_counts_unread_conversations_in_the_inbox_of_this_company(): void
    {
        $this->assertNull($this->badge());

        $this->received('One');
        $this->received('Two');
        $this->received('Read', read: true);
        $this->received('Spam', folder: EmailThread::FOLDER_SPAM);
        $foreign = $this->makeCompany('Foreign Co', 'foreign-co');
        $this->received('Foreign', company: $foreign, mailbox: $this->makeMailbox($foreign, 'a@foreign.test'));

        $this->assertSame('2', $this->badge());
    }

    // --- Search and quick filters ---------------------------------------------------------------------

    public function test_search_looks_in_subjects_senders_texts_customers_and_order_numbers_across_the_mail_but_not_in_trash(): void
    {
        $this->received('Bulk price for fans', from: 'rahim@traders.test', name: 'Rahim Traders', text: 'We need two hundred pieces');
        $this->received('Delivery question', from: 'karim@y.test', name: 'Karim', text: 'Where is the parcel with tracking ZX-77?', folder: EmailThread::FOLDER_ARCHIVED);
        $this->received('Thanks', from: 'sadia@z.test', name: 'Sadia', text: 'ok', folder: EmailThread::FOLDER_TRASH);
        $customer = Customer::query()->create(['name' => 'Farhana Akter', 'phone' => '01712345678']);
        $this->received('About my account', from: 'f@x.test', name: 'F', text: 'hello', customer: $customer);

        $found = fn (string $words): array => Livewire::test(EmailInbox::class)->set('search', $words)->instance()->threads->pluck('subject')->sort()->values()->all();

        $this->assertSame(['Bulk price for fans'], $found('fans'), 'In the subject.');
        $this->assertSame(['Bulk price for fans'], $found('rahim@traders'), 'In the sender\'s address.');
        $this->assertSame(['Bulk price for fans'], $found('Rahim Traders'), 'In the sender\'s name.');
        $this->assertSame(['Bulk price for fans'], $found('two hundred'), 'In the text of a message.');
        $this->assertSame(['Delivery question'], $found('ZX-77'), 'An archived conversation is found from the Inbox.');
        $this->assertSame([], $found('Thanks'), 'Trash is not searched.');
        $this->assertSame(['About my account'], $found('Farhana'), 'By the customer it is matched to.');
        $this->assertSame(['About my account'], $found('01712345678'));
        $this->assertSame([], $found('%'), 'A percent sign is a character, not a wildcard.');
        $this->assertSame([], $found('no such words'));

        $trash = Livewire::test(EmailInbox::class)->call('showFolder', 'trash')->set('search', 'Thanks');
        $this->assertSame(['Thanks'], $trash->instance()->threads->pluck('subject')->all(), 'In the Trash view, the Trash is searched.');
    }

    public function test_the_quick_filters_narrow_the_list_and_combine(): void
    {
        $unread = $this->received('Unread, needs reply');
        $this->received('Read, needs reply', read: true);
        $answered = $this->received('Answered', read: true);
        $this->outgoing($answered, 'Done');
        $mine = $this->received('Mine with file', files: 1, assignedTo: $this->user->getKey());
        $closed = $this->received('Closed one', read: true, status: EmailThread::STATUS_CLOSED);

        $list = fn (array $filters): array => Livewire::test(EmailInbox::class)->set('filters', $filters)->instance()->threads->pluck('subject')->sort()->values()->all();

        $this->assertSame(['Mine with file', 'Unread, needs reply'], $list(['unread']));
        $this->assertSame(['Mine with file', 'Read, needs reply', 'Unread, needs reply'], $list(['needs_reply']), 'Not the answered one, not the closed one.');
        $this->assertSame(['Mine with file'], $list(['mine']));
        $this->assertSame(['Mine with file'], $list(['files']));
        $this->assertSame(['Mine with file'], $list(['unread', 'mine']), 'Filters add up.');

        $page = Livewire::test(EmailInbox::class)->call('toggleFilter', 'unread')->assertSet('filters', ['unread'])->call('toggleFilter', 'bogus')->assertSet('filters', ['unread']);
        $page->call('toggleFilter', 'unread')->assertSet('filters', []);
    }

    // --- Opening a conversation -----------------------------------------------------------------------

    public function test_opening_a_conversation_marks_it_read_and_shows_its_messages_oldest_first_with_the_newest_open(): void
    {
        $thread = $this->received('Bulk price', text: 'How much for 200?', messageId: 'a@x.test', when: now()->subDays(2));
        $this->message($thread, EmailMessage::OUT, 'It is 450 each.', from: 'info@shop.test', status: EmailMessage::STATUS_SENT, read: true, when: now()->subDay());
        $this->message($thread, EmailMessage::IN, 'Please send the invoice.', from: 'rahim@x.test', name: 'Rahim', when: now()->subHour(), messageId: 'c@x.test');

        $this->assertSame(2, $thread->fresh()->unread_count, 'Both received messages start unread.');

        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())->assertSet('threadId', $thread->getKey());

        $this->assertSame(0, $thread->fresh()->unread_count);
        $this->assertSame(0, EmailMessage::query()->where('thread_id', $thread->getKey())->where('direction', 'in')->where('is_read', false)->count());
        $this->assertSame(['How much for 200?', 'It is 450 each.', 'Please send the invoice.'], $page->instance()->messages->pluck('body_text')->all());
        $page->assertSee('Please send the invoice.');
        $this->assertFalse($page->instance()->isOpen($page->instance()->messages->first(), false), 'Older messages are one line until opened.');
        $this->assertTrue($page->instance()->isOpen($page->instance()->messages->last(), true));
    }

    public function test_an_older_message_opens_and_closes_by_hand_and_the_newest_can_be_closed(): void
    {
        $thread = $this->received('Two messages', text: 'First message body', messageId: 'a@x.test', when: now()->subDay());
        $second = $this->message($thread, EmailMessage::IN, 'Second message body', from: 'rahim@x.test', when: now()->subHour(), messageId: 'b@x.test');
        $first = $thread->messages()->orderBy('id')->first();

        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());
        $this->assertTrue($page->instance()->isOpen($second, true));
        $this->assertFalse($page->instance()->isOpen($first, false));

        $page->call('toggleMessage', $first->getKey(), false);
        $this->assertTrue($page->instance()->isOpen($first, false), 'Opened by hand.');
        $page->call('toggleMessage', $first->getKey(), false);
        $this->assertFalse($page->instance()->isOpen($first, false), 'Closed again.');
        $page->call('toggleMessage', $second->getKey(), true);
        $this->assertFalse($page->instance()->isOpen($second, true), 'The newest one can be closed too.');
    }

    public function test_a_conversation_of_another_mailbox_or_company_cannot_be_opened(): void
    {
        $otherMailbox = $this->makeMailbox($this->company, 'sales@shop.test');
        $elsewhere = $this->received('Other mailbox', mailbox: $otherMailbox);
        $foreign = $this->makeCompany('Foreign Co', 'foreign-co');
        $foreignThread = $this->received('Foreign company', company: $foreign, mailbox: $this->makeMailbox($foreign, 'a@foreign.test'));

        Livewire::test(EmailInbox::class)->call('openThread', $elsewhere->getKey())->assertSet('threadId', null);
        Livewire::test(EmailInbox::class)->call('openThread', $foreignThread->getKey())->assertSet('threadId', null);
        $this->assertSame(1, $elsewhere->fresh()->unread_count, 'And nothing was marked read.');
    }

    public function test_the_phone_shows_the_conversation_in_place_of_the_list_and_a_way_back(): void
    {
        $thread = $this->received('Hello');

        Livewire::test(EmailInbox::class)
            ->assertDontSeeHtml('zz-mail-body has-thread')
            ->call('openThread', $thread->getKey())
            ->assertSeeHtml('zz-mail-body has-thread')
            ->assertSeeHtml('wire:click="closeThread"')
            ->call('closeThread')
            ->assertSet('threadId', null)
            ->assertDontSeeHtml('zz-mail-body has-thread');
    }

    // --- Doing things to conversations ----------------------------------------------------------------

    public function test_stars_are_toggled_one_conversation_at_a_time(): void
    {
        $thread = $this->received('Star me');
        $page = Livewire::test(EmailInbox::class);

        $page->call('toggleStar', $thread->getKey());
        $this->assertTrue($thread->fresh()->is_starred);
        $page->call('toggleStar', $thread->getKey());
        $this->assertFalse($thread->fresh()->is_starred);
    }

    public function test_archive_spam_trash_and_back_move_the_ticked_conversations_and_the_last_move_can_be_undone(): void
    {
        $a = $this->received('A');
        $b = $this->received('B');
        $c = $this->received('C');

        $page = Livewire::test(EmailInbox::class)->set('selected', [(string) $a->getKey(), (string) $b->getKey()])->call('performSelected', 'archive');

        $this->assertSame(EmailThread::FOLDER_ARCHIVED, $a->fresh()->folder);
        $this->assertSame(EmailThread::FOLDER_ARCHIVED, $b->fresh()->folder);
        $this->assertSame(EmailThread::FOLDER_INBOX, $c->fresh()->folder);
        $page->assertSet('selected', [])->assertSee('2 archived')->assertSee('Undo');

        $page->call('undoMove');
        $this->assertSame(EmailThread::FOLDER_INBOX, $a->fresh()->folder);
        $this->assertSame(EmailThread::FOLDER_INBOX, $b->fresh()->folder);
        $page->assertSet('undo', null);

        $page->call('perform', 'spam', [$c->getKey()]);
        $this->assertSame(EmailThread::FOLDER_SPAM, $c->fresh()->folder);
        $page->call('perform', 'notspam', [$c->getKey()]);
        $this->assertSame(EmailThread::FOLDER_INBOX, $c->fresh()->folder);
        $page->call('perform', 'trash', [$c->getKey()]);
        $this->assertSame(EmailThread::FOLDER_TRASH, $c->fresh()->folder);
        $page->call('perform', 'restore', [$c->getKey()]);
        $this->assertSame(EmailThread::FOLDER_INBOX, $c->fresh()->folder);
    }

    public function test_undo_takes_each_conversation_back_to_the_folder_it_came_from(): void
    {
        $inbox = $this->received('From the inbox');
        $archived = $this->received('From the archive', folder: EmailThread::FOLDER_ARCHIVED);

        $page = Livewire::test(EmailInbox::class)->call('perform', 'trash', [$inbox->getKey(), $archived->getKey()]);
        $this->assertSame(EmailThread::FOLDER_TRASH, $archived->fresh()->folder);

        $page->call('undoMove');

        $this->assertSame(EmailThread::FOLDER_INBOX, $inbox->fresh()->folder);
        $this->assertSame(EmailThread::FOLDER_ARCHIVED, $archived->fresh()->folder);
    }

    public function test_read_and_unread_apply_to_the_ticked_conversations_and_unread_goes_by_the_newest_received_message(): void
    {
        $thread = $this->received('Read me', messageId: 'a@x.test', when: now()->subDay());
        $this->message($thread, EmailMessage::IN, 'Second', from: 'rahim@x.test', when: now()->subHour(), messageId: 'b@x.test');
        $page = Livewire::test(EmailInbox::class);

        $page->call('perform', 'read', [$thread->getKey()]);
        $this->assertSame(0, $thread->fresh()->unread_count);

        $page->call('perform', 'unread', [$thread->getKey()]);
        $this->assertSame(1, $thread->fresh()->unread_count, 'Only the newest message is unread, as in Gmail.');
        $this->assertSame('b@x.test', EmailMessage::query()->where('thread_id', $thread->getKey())->where('is_read', false)->value('message_id'));
    }

    public function test_marking_unread_closes_the_open_conversation_so_it_is_not_opened_and_read_again(): void
    {
        $thread = $this->received('Open one', read: true);

        Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())->call('perform', 'unread', [$thread->getKey()])->assertSet('threadId', null);
        $this->assertSame(1, $thread->fresh()->unread_count);
    }

    public function test_delete_forever_works_only_in_trash_removes_the_files_and_emptying_the_trash_clears_it(): void
    {
        $live = $this->received('Still live');
        $dead = $this->received('In the bin', folder: EmailThread::FOLDER_TRASH);
        $file = $this->attach($dead->messages()->firstOrFail(), 'doc.pdf', 'PDF');
        $second = $this->received('Also in the bin', folder: EmailThread::FOLDER_TRASH);
        $page = Livewire::test(EmailInbox::class);

        $page->call('perform', 'delete', [$live->getKey()]);
        $this->assertNotNull(EmailThread::query()->find($live->getKey()), 'A conversation that is not in Trash is not deleted.');

        $page->call('perform', 'delete', [$dead->getKey()]);
        $this->assertNull(EmailThread::query()->find($dead->getKey()));
        $this->assertSame(0, EmailMessage::query()->where('thread_id', $dead->getKey())->count());
        $this->assertSame(0, EmailAttachment::query()->count());
        $this->assertNull(app(CompanyStorageService::class)->readPrivate($file->path, $this->company), 'The file is gone from storage.');

        $page->call('emptyTrash');
        $this->assertNull(EmailThread::query()->find($second->getKey()));
        $this->assertNotNull(EmailThread::query()->find($live->getKey()));
    }

    public function test_a_conversation_that_is_only_a_draft_is_thrown_away_not_put_in_trash(): void
    {
        $draft = $this->draftOnly('Unfinished', $this->user);

        Livewire::test(EmailInbox::class)->call('perform', 'trash', [$draft->getKey()]);

        $this->assertNull(EmailThread::query()->find($draft->getKey()));
        $this->assertSame(0, EmailMessage::query()->where('thread_id', $draft->getKey())->count());
    }

    public function test_staff_who_may_only_view_can_read_but_not_change_anything(): void
    {
        UserRole::query()->create(['name' => 'Mail viewer', 'slug' => 'mail_viewer', 'permissions' => ['crm.view'], 'is_active' => true]);
        Auth::login(User::factory()->create(['role' => 'mail_viewer', 'is_active' => true]));
        $thread = $this->received('Read only');

        $page = Livewire::test(EmailInbox::class)->assertOk()->assertDontSee('Create label');
        $page->call('openThread', $thread->getKey())->assertSet('threadId', $thread->getKey());
        $this->assertSame(0, $thread->fresh()->unread_count, 'Reading still marks it read.');

        foreach ([['toggleStar', [$thread->getKey()]], ['perform', ['trash', [$thread->getKey()]]], ['emptyTrash', []], ['createLabel', []]] as [$method, $arguments]) {
            Livewire::test(EmailInbox::class)->call($method, ...$arguments)->assertForbidden();
        }

        $this->assertFalse($thread->fresh()->is_starred);
        $this->assertSame(EmailThread::FOLDER_INBOX, $thread->fresh()->folder);
    }

    public function test_ids_of_another_company_or_mailbox_are_ignored_by_every_action(): void
    {
        $foreign = $this->makeCompany('Foreign Co', 'foreign-co');
        $theirs = $this->received('Not ours', company: $foreign, mailbox: $this->makeMailbox($foreign, 'a@foreign.test'));
        $elsewhere = $this->received('Other mailbox', mailbox: $this->makeMailbox($this->company, 'sales@shop.test'));
        $page = Livewire::test(EmailInbox::class);

        foreach (['trash', 'spam', 'archive', 'star', 'read', 'delete'] as $action) {
            $page->call('perform', $action, [$theirs->getKey(), $elsewhere->getKey()]);
        }

        $page->call('toggleStar', $theirs->getKey());

        $this->assertSame(EmailThread::FOLDER_INBOX, $theirs->fresh()->folder);
        $this->assertFalse($theirs->fresh()->is_starred);
        $this->assertSame(1, $theirs->fresh()->unread_count);
        $this->assertSame(EmailThread::FOLDER_INBOX, $elsewhere->fresh()->folder);
        $this->assertNotNull(EmailThread::withoutGlobalScopes()->find($theirs->getKey()));
    }

    // --- Labels ---------------------------------------------------------------------------------------

    public function test_a_label_is_created_checked_and_applied_to_ticked_conversations_and_taken_off_again(): void
    {
        $a = $this->received('A');
        $b = $this->received('B');
        $page = Livewire::test(EmailInbox::class);

        $page->set('labelName', '')->call('createLabel')->assertHasErrors(['labelName']);
        $page->set('labelName', 'Wholesale')->set('labelColor', 'chartreuse')->call('createLabel')->assertHasErrors(['labelColor']);
        $page->set('labelColor', 'blue')->call('createLabel')->assertHasNoErrors()->assertSet('showLabelForm', false)->assertSee('Wholesale');
        $page->set('labelName', 'wholesale')->call('createLabel');

        $label = EmailLabel::query()->where('name', 'Wholesale')->firstOrFail();
        $this->assertSame(1, EmailLabel::query()->count(), 'A second label with the same name is refused.');
        $this->assertSame($this->company->getKey(), $label->company_id);

        $page->set('selected', [$a->getKey(), $b->getKey()])->call('applyLabelToSelected', $label->getKey());
        $this->assertSame(2, $label->threads()->count());

        $page->set('selected', [$a->getKey(), $b->getKey()])->call('applyLabelToSelected', $label->getKey());
        $this->assertSame(0, $label->threads()->count(), 'Applying a label every ticked conversation has takes it off.');

        $page->call('applyLabel', $label->getKey(), [$a->getKey()], true);
        $this->assertSame([$a->getKey()], $label->threads()->pluck('email_threads.id')->all());

        $page->call('showLabel', $label->getKey())->assertSet('labelId', $label->getKey());
        $this->assertSame(['A'], $page->instance()->threads->pluck('subject')->all(), 'The label view lists its conversations.');

        $page->call('applyLabel', $label->getKey(), [$a->getKey()], false);
        $this->assertSame(0, $label->threads()->count());
    }

    public function test_a_label_of_another_company_cannot_be_used(): void
    {
        $thread = $this->received('Mine');
        $foreign = $this->makeCompany('Foreign Co', 'foreign-co');
        $theirLabel = EmailLabel::withoutGlobalScopes()->create(['company_id' => $foreign->getKey(), 'name' => 'Secret', 'color' => 'red']);

        Livewire::test(EmailInbox::class)->call('applyLabel', $theirLabel->getKey(), [$thread->getKey()], true)->call('showLabel', $theirLabel->getKey())->assertSet('labelId', null);
        $this->assertSame(0, $theirLabel->threads()->count());

        $this->expectException(AuthorizationException::class);
        app(EmailThreadActions::class)->label([$thread->getKey()], $theirLabel, true);
    }

    // --- Mailboxes ------------------------------------------------------------------------------------

    public function test_the_mailbox_can_be_switched_and_only_to_one_of_this_companys(): void
    {
        $other = $this->makeMailbox($this->company, 'sales@shop.test');
        $this->received('In info');
        $this->received('In sales', mailbox: $other);
        $foreign = $this->makeCompany('Foreign Co', 'foreign-co');
        $foreignBox = $this->makeMailbox($foreign, 'a@foreign.test');

        $page = Livewire::test(EmailInbox::class)->assertSee('sales@shop.test');
        $this->assertSame(['In info'], $page->instance()->threads->pluck('subject')->all(), 'The company\'s first (main) mailbox opens first.');

        $page->call('switchMailbox', $other->getKey());
        $this->assertSame(['In sales'], $page->instance()->threads->pluck('subject')->all());
        $this->assertSame($other->getKey(), $page->instance()->mailbox->getKey());

        $page->call('switchMailbox', $foreignBox->getKey());
        $this->assertSame($other->getKey(), $page->instance()->mailbox->getKey(), 'Another company\'s mailbox is ignored.');
    }

    // --- How a message is drawn -----------------------------------------------------------------------

    public function test_html_mail_goes_into_a_sandboxed_frame_with_a_policy_that_blocks_scripts_and_remote_pictures(): void
    {
        $thread = $this->received('Newsletter', html: '<p>Hello <b>there</b></p><img src="https://track.example/pixel.gif" alt="">');

        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());

        $page->assertSeeHtml('sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"')
            ->assertDontSeeHtml('allow-scripts')
            ->assertSee('Pictures from the internet are hidden')
            ->assertSee('Show pictures');

        $document = app(EmailMessageView::class)->document($thread->messages()->firstOrFail(), $this->company, false);
        $this->assertStringContainsString('default-src &#039;none&#039;', $document, 'The policy is in the document\'s own head.');
        $this->assertStringContainsString('img-src data:;', $document);
        $this->assertStringNotContainsString('https:', explode('<body>', $document)[0], 'No remote source is allowed by the policy.');
        $this->assertStringContainsString('<b>there</b>', $document);

        $shown = app(EmailMessageView::class)->document($thread->messages()->firstOrFail(), $this->company, true);
        $this->assertStringContainsString('img-src data: https: http:;', $shown);

        $messageId = $thread->messages()->firstOrFail()->getKey();
        $page->call('showPictures', $messageId)->assertDontSee('Pictures from the internet are hidden');
        $this->assertTrue($page->instance()->picturesAreShown($thread->messages()->firstOrFail()));
    }

    public function test_the_mailbox_setting_can_show_pictures_for_every_message(): void
    {
        $this->mailbox->forceFill(['settings' => ['load_remote_images' => true]])->save();
        $thread = $this->received('Newsletter', html: '<img src="https://cdn.example/logo.png" alt="">');

        Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())->assertDontSee('Pictures from the internet are hidden');
    }

    public function test_plain_text_mail_is_escaped_and_keeps_its_line_breaks(): void
    {
        $thread = $this->received('Plain', text: "Line one <script>alert(1)</script>\nLine two & more");

        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());

        $page->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertSeeHtml('Line one &lt;script&gt;alert(1)&lt;/script&gt;<br>')
            ->assertSeeHtml('Line two &amp; more');
    }

    public function test_pictures_inside_the_mail_are_put_in_as_data_but_vector_and_oversize_ones_are_not(): void
    {
        $thread = $this->received('With logo', html: '<img src="cid:logo@x"><img src="cid:vector@x"><img src="cid:huge@x"><img src="cid:missing@x">');
        $message = $thread->messages()->firstOrFail();
        $this->attach($message, 'logo.png', 'PNGBYTES', mime: 'image/png', contentId: '<logo@x>', inline: true);
        $this->attach($message, 'vector.svg', '<svg onload="alert(1)"/>', mime: 'image/svg+xml', contentId: 'vector@x', inline: true);
        $huge = $this->attach($message, 'huge.png', 'x', mime: 'image/png', contentId: 'huge@x', inline: true);
        $huge->forceFill(['size' => EmailMessageView::MAX_INLINE_IMAGE_BYTES + 1])->save();

        $document = app(EmailMessageView::class)->document($message->fresh(), $this->company, false);

        $this->assertStringContainsString('src="data:image/png;base64,'.base64_encode('PNGBYTES').'"', $document);
        $this->assertStringContainsString('src="cid:vector@x"', $document, 'An SVG can carry scripts: it is not embedded.');
        $this->assertStringContainsString('src="cid:huge@x"', $document);
        $this->assertStringContainsString('src="cid:missing@x"', $document);
    }

    public function test_an_outgoing_message_shows_its_state_and_a_failed_one_shows_why(): void
    {
        $thread = $this->received('Question', when: now()->subHours(3));
        $this->message($thread, EmailMessage::OUT, 'On its way', from: 'info@shop.test', status: EmailMessage::STATUS_PENDING, read: true, when: now()->subHours(2));
        $this->message($thread, EmailMessage::OUT, 'Broken one', from: 'info@shop.test', status: EmailMessage::STATUS_FAILED, read: true, when: now()->subHour(), error: 'Sender address is not verified');

        $page = Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey());
        $messages = $page->instance()->messages;

        $page->call('toggleMessage', $messages[1]->getKey(), false)->assertSee('Sending…')->assertSee('Not sent')->assertSee('Sender address is not verified');
    }

    public function test_notes_are_shown_apart_and_another_persons_draft_is_not_shown(): void
    {
        $thread = $this->received('Complaint');
        $this->message($thread, EmailMessage::OUT, 'Called him, ship tomorrow', from: 'sadia@shop.test', name: 'Sadia', kind: EmailMessage::KIND_NOTE, status: EmailMessage::STATUS_NOTE, read: true);
        $this->message($thread, EmailMessage::OUT, 'My unfinished reply', from: 'info@shop.test', kind: EmailMessage::KIND_DRAFT, status: EmailMessage::STATUS_DRAFT, read: true, userId: $this->user->getKey());
        $this->message($thread, EmailMessage::OUT, 'Their secret draft', from: 'info@shop.test', kind: EmailMessage::KIND_DRAFT, status: EmailMessage::STATUS_DRAFT, read: true, userId: User::factory()->create(['role' => 'sales_staff', 'is_active' => true])->getKey());

        Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())
            ->assertSee('Internal note, only staff see it')
            ->assertSee('Called him, ship tomorrow')
            ->assertSee('My unfinished reply')
            ->assertDontSee('Their secret draft');
    }

    // --- Files ----------------------------------------------------------------------------------------

    public function test_a_file_downloads_as_an_attachment_for_staff_of_the_same_company_only(): void
    {
        $thread = $this->received('With file');
        $file = $this->attach($thread->messages()->firstOrFail(), 'offer.html', '<script>alert(1)</script>', mime: 'text/html');
        $session = ['current_company_id' => $this->company->getKey(), 'current_company_selection_explicit' => true];

        $response = $this->actingAs($this->user)->withSession($session)->get(route('email-attachments.download', $file));
        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('offer.html', (string) $response->headers->get('content-disposition'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
        $this->assertStringNotContainsString('text/html', (string) $response->headers->get('content-type'), 'A received web page is never served as one.');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));

        $accountant = User::factory()->create(['role' => 'accountant', 'is_active' => true]);
        $accountant->companies()->attach($this->company->getKey(), ['role' => 'accountant', 'is_default' => true]);
        $this->actingAs($accountant)->withSession($session)->get(route('email-attachments.download', $file))->assertNotFound();

        $foreign = $this->makeCompany('Foreign Co', 'foreign-co');
        $foreignUser = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $this->actingAs($foreignUser)->withSession(['current_company_id' => $foreign->getKey(), 'current_company_selection_explicit' => true])
            ->get(route('email-attachments.download', $file))->assertNotFound();

        Auth::logout();
        $this->flushSession();
        $this->get(route('email-attachments.download', $file))->assertRedirect();
    }

    public function test_a_message_lists_its_files_with_a_download_link_but_not_its_inline_pictures(): void
    {
        $thread = $this->received('With files');
        $message = $thread->messages()->firstOrFail();
        $file = $this->attach($message, 'price-list.xlsx', 'DATA');
        $this->attach($message, 'logo.png', 'PNG', mime: 'image/png', contentId: 'logo@x', inline: true);

        Livewire::test(EmailInbox::class)->call('openThread', $thread->getKey())
            ->assertSee('price-list.xlsx')
            ->assertSeeHtml(route('email-attachments.download', $file))
            ->assertDontSee('logo.png');
    }

    // --- Theme ----------------------------------------------------------------------------------------

    public function test_the_page_styles_live_in_the_admin_theme_with_a_phone_layout_and_dark_colours(): void
    {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

        foreach (['.zz-mail-body.has-thread', '.dark .zz-mail', '.zz-mail-folders.is-open', '.zz-mail-row.is-unread', '.zz-mail-frame', '.zz-mail-toast', '@media (max-width: 760px)', '@media (max-width: 980px)'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }
    }

    // --- Stats ----------------------------------------------------------------------------------------

    public function test_a_page_check_with_nothing_new_leaves_the_page_alone_and_a_change_draws_it_again(): void
    {
        $this->received('First');
        $page = Livewire::test(EmailInbox::class)->call('checkNew');
        $first = $page->get('signature');
        $this->assertNotNull($first);

        $page->call('checkNew')->assertSet('signature', $first);

        $this->received('Second');
        $page->call('checkNew');
        $this->assertNotSame($first, $page->get('signature'));
        $this->assertSame(['Second', 'First'], $page->instance()->threads->pluck('subject')->all());
    }

    // --- Helpers --------------------------------------------------------------------------------------

    private function badge(): ?string
    {
        $method = new \ReflectionMethod(EmailInbox::class, 'navigationBadge');

        return $method->invoke(null);
    }

    /** A conversation with one received message. */
    private function received(
        string $subject,
        string $from = 'rahim@x.test',
        ?string $name = 'Rahim',
        string $text = 'Hello',
        ?string $html = null,
        string $folder = EmailThread::FOLDER_INBOX,
        bool $starred = false,
        bool $read = false,
        int $files = 0,
        ?int $assignedTo = null,
        string $status = EmailThread::STATUS_OPEN,
        ?Customer $customer = null,
        array $labels = [],
        ?Company $company = null,
        ?EmailMailbox $mailbox = null,
        ?string $messageId = null,
        ?\DateTimeInterface $when = null,
    ): EmailThread {
        $company ??= $this->company;
        $mailbox ??= $this->mailbox;
        $thread = EmailThread::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'mailbox_id' => $mailbox->getKey(),
            'subject' => $subject,
            'folder' => $folder,
            'is_starred' => $starred,
            'contact_email' => $from,
            'contact_name' => $name,
            'assigned_to' => $assignedTo,
            'status' => $status,
            'customer_id' => $customer?->getKey(),
        ]);

        $message = $this->message($thread, EmailMessage::IN, $text, from: $from, name: $name, read: $read, html: $html, when: $when, messageId: $messageId ?? uniqid('m').'@x.test', company: $company, mailbox: $mailbox);

        for ($i = 0; $i < $files; $i++) {
            $this->attach($message, "file{$i}.pdf", 'PDF');
        }

        foreach ($labels as $label) {
            $thread->labels()->attach($label->getKey());
        }

        return $this->refreshed($thread);
    }

    private function sentOnly(string $subject): EmailThread
    {
        $thread = EmailThread::query()->create(['mailbox_id' => $this->mailbox->getKey(), 'subject' => $subject, 'folder' => EmailThread::FOLDER_ARCHIVED, 'contact_email' => 'karim@y.test']);
        $this->message($thread, EmailMessage::OUT, 'Hello from us', from: 'info@shop.test', status: EmailMessage::STATUS_SENT, read: true);

        return $this->refreshed($thread);
    }

    private function outgoing(EmailThread $thread, string $text): EmailMessage
    {
        $message = $this->message($thread, EmailMessage::OUT, $text, from: 'info@shop.test', status: EmailMessage::STATUS_SENT, read: true, when: now()->addMinute());
        $this->refreshed($thread);

        return $message;
    }

    private function draftOnly(string $subject, User $user): EmailThread
    {
        $thread = EmailThread::query()->create(['mailbox_id' => $this->mailbox->getKey(), 'subject' => $subject, 'folder' => EmailThread::FOLDER_ARCHIVED]);
        $this->message($thread, EmailMessage::OUT, 'Draft text', from: 'info@shop.test', kind: EmailMessage::KIND_DRAFT, status: EmailMessage::STATUS_DRAFT, read: true, userId: $user->getKey());

        return $thread;
    }

    private function message(
        EmailThread $thread,
        string $direction,
        string $text,
        string $from = 'rahim@x.test',
        ?string $name = null,
        string $kind = EmailMessage::KIND_MESSAGE,
        ?string $status = null,
        bool $read = false,
        ?string $html = null,
        ?\DateTimeInterface $when = null,
        ?string $messageId = null,
        ?string $error = null,
        ?int $userId = null,
        ?Company $company = null,
        ?EmailMailbox $mailbox = null,
    ): EmailMessage {
        $message = EmailMessage::withoutGlobalScopes()->create([
            'company_id' => ($company ?? $this->company)->getKey(),
            'thread_id' => $thread->getKey(),
            'mailbox_id' => ($mailbox ?? $this->mailbox)->getKey(),
            'direction' => $direction,
            'kind' => $kind,
            'status' => $status ?? ($direction === EmailMessage::IN ? EmailMessage::STATUS_RECEIVED : EmailMessage::STATUS_SENT),
            'message_id' => $messageId,
            'from_address' => $from,
            'from_name' => $name,
            'to_addresses' => [['address' => 'info@shop.test', 'name' => null]],
            'subject' => $thread->subject,
            'body_text' => $text,
            'body_html' => $html,
            'is_read' => $read,
            'sent_at' => $when ?? now()->subMinutes(5),
            'error' => $error,
            'user_id' => $userId,
        ]);

        if ($kind === EmailMessage::KIND_MESSAGE) {
            $this->stats($thread);
        }

        return $message;
    }

    private function refreshed(EmailThread $thread): EmailThread
    {
        return $this->stats($thread);
    }

    /** A conversation's counters, worked out inside its own company. */
    private function stats(EmailThread $thread): EmailThread
    {
        $thread = EmailThread::withoutGlobalScopes()->findOrFail($thread->getKey());

        return app(CompanyContext::class)->within(Company::query()->findOrFail($thread->company_id), fn () => app(EmailThreadStats::class)->refresh($thread));
    }

    private function attach(EmailMessage $message, string $name, string $content, string $mime = 'application/pdf', ?string $contentId = null, bool $inline = false): EmailAttachment
    {
        $company = Company::query()->findOrFail($message->company_id);
        $path = app(CompanyStorageService::class)->putPrivate($company, 'email-attachments', $name, $content);

        $file = EmailAttachment::withoutGlobalScopes()->create([
            'company_id' => $message->company_id,
            'message_id' => $message->getKey(),
            'filename' => $name,
            'mime' => $mime,
            'size' => strlen($content),
            'path' => $path,
            'content_id' => $contentId,
            'is_inline' => $inline,
        ]);

        $this->stats(EmailThread::withoutGlobalScopes()->findOrFail($message->thread_id));

        return $file;
    }

    private function makeCompany(string $name, string $slug): Company
    {
        return Company::query()->create(['name' => $name, 'slug' => $slug, 'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
    }

    private function makeMailbox(Company $company, string $address): EmailMailbox
    {
        return EmailMailbox::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'name' => $address,
            'address' => $address,
            'receive_mode' => EmailMailbox::RECEIVE_WEBHOOK,
            'is_active' => true,
        ]);
    }
}
