<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailInbox;
use App\Mail\ComposedEmail;
use App\Models\Company;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Email\Inbox\EmailSendException;
use App\Services\Email\Inbox\OutboundEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsEmailInbox;
use Tests\TestCase;

/**
 * Email inbox, Phase 2 step 9 (docs/email-inbox-plan.md): Send later. A message is written, set to go at a time, shown
 * as Scheduled to every member of staff, sent by `email:send-due` when the time comes, and can be taken back or sent now
 * by its author only.
 */
class EmailScheduledSendTest extends TestCase
{
    use BuildsEmailInbox;
    use RefreshDatabase;

    private Company $company;

    private EmailMailbox $mailbox;

    private User $user;

    private OutboundEmailService $outbound;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        URL::forceScheme('https');
        $this->company = $this->makeCompany('Later Co', 'later-co');
        app(CompanyContext::class)->set($this->company);
        $this->makeSite($this->company);
        $this->mailbox = $this->makeMailbox($this->company, 'info@shop.test');
        $this->user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $this->user->companies()->attach($this->company->getKey(), ['role' => 'sales_staff', 'is_default' => true]);
        Auth::login($this->user);
        $this->outbound = app(OutboundEmailService::class);
    }

    private function draft(string $subject = 'Offer'): EmailMessage
    {
        $draft = $this->outbound->startNew($this->mailbox, $this->user);
        $this->outbound->saveDraft($draft, ['to' => 'rahim@x.test', 'subject' => $subject, 'body_html' => '<p>Hello</p>']);

        return $draft->fresh();
    }

    public function test_a_message_can_be_scheduled_and_waits_without_being_sent(): void
    {
        Mail::fake();
        $at = now()->addDay()->startOfSecond();

        $message = $this->outbound->queue($this->draft(), $this->user, $at);

        $this->assertSame(EmailMessage::STATUS_SCHEDULED, $message->status);
        $this->assertSame(EmailMessage::KIND_MESSAGE, $message->kind);
        $this->assertTrue($message->send_after->equalTo($at));
        $this->assertTrue($message->sent_at->equalTo($at));
        Mail::assertNothingSent();
        $this->assertSame(0, $this->outbound->deliverDue());
        $this->assertFalse($this->outbound->deliver($message->fresh()), 'It is not due yet.');
        $this->assertSame(0, (int) $message->thread->fresh()->message_count, 'A scheduled message is not part of the conversation yet.');
    }

    public function test_a_time_that_is_too_close_or_too_far_is_refused(): void
    {
        foreach ([now()->addSeconds(20), now()->subHour(), now()->addYear()->addDay()] as $bad) {
            try {
                $this->outbound->queue($this->draft(), $this->user, $bad);
                $this->fail('A bad time should be refused.');
            } catch (EmailSendException $exception) {
                $this->assertSame('Pick a time that is still ahead (within a year).', $exception->getMessage());
            }
        }

        $this->assertSame(0, EmailMessage::query()->where('status', EmailMessage::STATUS_SCHEDULED)->count());
    }

    public function test_when_the_time_comes_the_command_sends_it(): void
    {
        Mail::fake();
        $message = $this->outbound->queue($this->draft(), $this->user, now()->addHour());
        $this->travel(2)->hours();

        Artisan::call('email:send-due');

        Mail::assertSentCount(1);
        Mail::assertSent(ComposedEmail::class, fn (ComposedEmail $mail): bool => $mail->hasTo('rahim@x.test'));
        $this->assertSame(EmailMessage::STATUS_SENT, $message->fresh()->status);
        $this->assertSame(1, (int) $message->thread->fresh()->message_count);
    }

    public function test_the_author_can_take_it_back_into_a_draft_or_send_it_now(): void
    {
        Mail::fake();
        $message = $this->outbound->queue($this->draft(), $this->user, now()->addDay());

        $this->assertTrue($this->outbound->cancelScheduled($message));
        $back = $message->fresh();
        $this->assertSame(EmailMessage::STATUS_DRAFT, $back->status);
        $this->assertSame(EmailMessage::KIND_DRAFT, $back->kind);
        $this->assertNull($back->send_after);

        $again = $this->outbound->queue($back, $this->user, now()->addDay());
        $this->assertTrue($this->outbound->sendScheduledNow($again));
        $this->assertSame(EmailMessage::STATUS_SENT, $again->fresh()->status);
        Mail::assertSentCount(1);
        $this->assertFalse($this->outbound->cancelScheduled($again->fresh()), 'Too late once it went.');
    }

    public function test_a_scheduled_message_that_cannot_go_fails_and_the_author_gets_a_bell_message(): void
    {
        Mail::fake();
        $message = $this->outbound->queue($this->draft('Late offer'), $this->user, now()->addHour());
        $this->mailbox->forceFill(['is_active' => false])->save();
        $this->travel(2)->hours();

        $this->assertSame(0, $this->outbound->deliverDue());

        $failed = $message->fresh();
        $this->assertSame(EmailMessage::STATUS_FAILED, $failed->status);
        $this->assertSame('This mailbox is switched off.', $failed->error);
        Mail::assertNothingSent();
        $this->assertSame(1, $this->user->notifications()->count());
    }

    public function test_the_scheduled_view_lists_the_conversation_with_its_time_for_every_member_of_staff(): void
    {
        $message = $this->outbound->queue($this->draft('Quarterly price list'), $this->user, now()->addDay());
        $other = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $other->companies()->attach($this->company->getKey(), ['role' => 'sales_staff', 'is_default' => true]);
        $this->received('Not scheduled');

        Livewire::actingAs($other)->test(EmailInbox::class)
            ->call('showFolder', 'scheduled')
            ->assertSee('Quarterly price list')->assertDontSee('Not scheduled')
            ->call('openThread', $message->thread_id)
            ->assertSee('Scheduled for')->assertDontSee('Cancel and edit')->assertSee('Only');

        Livewire::actingAs($this->user)->test(EmailInbox::class)->call('showFolder', 'scheduled')->call('openThread', $message->thread_id)
            ->assertSee('Cancel and edit')->assertSee('Send now');
    }

    public function test_send_later_from_the_compose_window(): void
    {
        Mail::fake();
        $page = Livewire::test(EmailInbox::class)->call('composeNew')->set('composeTo', 'rahim@x.test')->set('composeSubject', 'Later')->set('composeData.body', '<p>Hi</p>')
            ->call('sendLater', 'tomorrow');

        $message = EmailMessage::query()->where('status', EmailMessage::STATUS_SCHEDULED)->firstOrFail();
        $this->assertTrue($message->send_after->isFuture());
        $page->assertNotified();
        Mail::assertNothingSent();
    }

    public function test_a_typed_time_is_read_in_the_company_time_zone_and_must_be_ahead(): void
    {
        $this->company->forceFill(['timezone' => 'Asia/Dhaka'])->save();
        $when = now('Asia/Dhaka')->addDays(2)->setTime(15, 30);

        Livewire::test(EmailInbox::class)->call('composeNew')->set('composeTo', 'rahim@x.test')->set('composeSubject', 'Typed')->set('composeData.body', '<p>Hi</p>')
            ->set('scheduleCustom', $when->format('Y-m-d\TH:i'))->call('sendLater', 'custom')->assertSet('scheduleCustom', '');

        $this->assertTrue(EmailMessage::query()->where('status', EmailMessage::STATUS_SCHEDULED)->firstOrFail()->send_after->equalTo($when));

        Livewire::test(EmailInbox::class)->call('composeNew')->set('composeTo', 'rahim@x.test')->set('composeSubject', 'Past')->set('composeData.body', '<p>Hi</p>')
            ->set('scheduleCustom', now('Asia/Dhaka')->subHour()->format('Y-m-d\TH:i'))->call('sendLater', 'custom');

        $this->assertSame(1, EmailMessage::query()->where('status', EmailMessage::STATUS_SCHEDULED)->count());
    }

    public function test_only_the_author_can_cancel_or_send_now_from_the_page(): void
    {
        Mail::fake();
        $message = $this->outbound->queue($this->draft(), $this->user, now()->addDay());
        $other = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $other->companies()->attach($this->company->getKey(), ['role' => 'sales_staff', 'is_default' => true]);

        Livewire::actingAs($other)->test(EmailInbox::class)->call('openThread', $message->thread_id)
            ->call('cancelScheduled', $message->getKey())->call('sendScheduledNow', $message->getKey());

        $this->assertSame(EmailMessage::STATUS_SCHEDULED, $message->fresh()->status);
        Mail::assertNothingSent();

        Livewire::actingAs($this->user)->test(EmailInbox::class)->call('openThread', $message->thread_id)->call('cancelScheduled', $message->getKey());
        $this->assertSame(EmailMessage::STATUS_DRAFT, $message->fresh()->status);
    }

    public function test_a_company_never_sees_another_companys_scheduled_mail(): void
    {
        $this->outbound->queue($this->draft('Ours'), $this->user, now()->addDay());
        $other = $this->makeCompany('Other Co', 'other-co');
        $thread = EmailThread::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'mailbox_id' => $this->makeMailbox($other, 'x@other.test')->getKey(), 'subject' => 'Theirs', 'folder' => 'inbox']);
        EmailMessage::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'thread_id' => $thread->getKey(), 'mailbox_id' => $thread->mailbox_id, 'direction' => 'out', 'kind' => 'message', 'status' => 'scheduled', 'send_after' => now()->addDay(), 'subject' => 'Theirs']);

        Livewire::test(EmailInbox::class)->call('showFolder', 'scheduled')->assertSee('Ours')->assertDontSee('Theirs');
    }
}
