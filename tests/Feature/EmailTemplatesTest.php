<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailTemplates;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Ims\Notifications\ImsNotifier;
use App\Ims\Settings\ImsSettings;
use App\Jobs\SendImsMessageJob;
use App\Jobs\SendOrderEmailJob;
use App\Mail\ImsMessage;
use App\Mail\StorefrontLoginOtp;
use App\Mail\TemplatedEmail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\EmailLogService;
use App\Services\EmailTemplateService;
use App\Services\OrderStatusEmailService;
use App\Services\OrderStatusWorkflowService;
use App\Services\SmsTemplateService;
use App\Services\StorefrontNotificationService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Ims\ImsTestHelpers;
use Tests\TestCase;

/**
 * Email Templates (owner request 2026-10-24): the wording of the order emails,
 * the code and reset mails, the investors' IMS mails and the owner's own
 * templates. Order emails send nothing until the owner writes them; the other
 * built-in mails go out as they always did until she writes her own.
 */
class EmailTemplatesTest extends TestCase
{
    use ImsTestHelpers;
    use RefreshDatabase;

    private StorefrontSetting $site;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->company = $this->makeCompany('Template Co', 'template-co');
        $this->site = StorefrontSetting::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        app(CompanyContext::class)->set($this->company);
        Auth::login(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
    }

    // ---------------------------------------------------------------- the service

    public function test_a_message_is_filled_escaped_and_laid_out(): void
    {
        $this->write([EmailTemplateService::ORDER_PLACED => ['subject' => "Order :order\r\nBcc: evil@example.test", 'body' => "Dear :name,\n\nYour order :order is ready.\nPay at https://shop.test/pay?a=1&b=2.\n\n:company"]]);

        $message = app(EmailTemplateService::class)->render($this->company->id, EmailTemplateService::ORDER_PLACED, ['name' => '<b>Rahim</b>', 'order' => 'ZZ-1', 'company' => 'Template Co']);

        $this->assertSame('Order ZZ-1 Bcc: evil@example.test', $message['subject'], 'A line break in a subject would be a header injection.');
        $this->assertStringContainsString("Dear <b>Rahim</b>,\n\nYour order ZZ-1", $message['text']);
        $this->assertStringContainsString('Dear &lt;b&gt;Rahim&lt;/b&gt;,', $message['html']);
        $this->assertStringNotContainsString('<b>Rahim</b>', $message['html'], 'A value is never put into the page as HTML.');
        $this->assertSame(3, substr_count($message['html'], '<p '), 'A blank line starts a paragraph.');
        $this->assertStringContainsString('Your order ZZ-1 is ready.<br>', $message['html'], 'A single line break stays a line break.');
        $this->assertStringContainsString('<a href="https://shop.test/pay?a=1&amp;b=2" ', $message['html']);
        $this->assertStringContainsString('</a>.', $message['html'], 'The full stop after an address is not part of the link.');
    }

    public function test_an_empty_template_renders_nothing_and_a_blank_body_removes_the_row(): void
    {
        $service = app(EmailTemplateService::class);

        $this->assertNull($service->render($this->company->id, EmailTemplateService::ORDER_PLACED, []));

        $this->write([EmailTemplateService::ORDER_PLACED => ['subject' => 'Hello', 'body' => 'Body']]);
        $this->assertTrue($service->written($this->company->id, EmailTemplateService::ORDER_PLACED));

        $this->write([EmailTemplateService::ORDER_PLACED => ['subject' => 'Hello', 'body' => '  ']]);
        $this->assertFalse($service->written($this->company->id, EmailTemplateService::ORDER_PLACED));
        $this->assertSame(0, EmailTemplate::withoutGlobalScopes()->count());
    }

    public function test_a_code_template_must_keep_the_code_and_an_order_template_needs_a_subject(): void
    {
        $service = app(EmailTemplateService::class);

        $this->assertNotNull($service->problem(EmailTemplateService::LOGIN_CODE, 'Your code', 'Welcome back, nothing else.'));
        $this->assertNull($service->problem(EmailTemplateService::LOGIN_CODE, null, 'Your code is :code.'));
        $this->assertNotNull($service->problem(EmailTemplateService::RESELLER_RESET, null, 'Click the link.'));
        $this->assertNotNull($service->problem(EmailTemplateService::ORDER_PLACED, '', 'We got your order.'));
        $this->assertNull($service->problem(EmailTemplateService::ORDER_PLACED, 'Order', 'We got your order.'));
        $this->assertNull($service->problem(EmailTemplateService::ORDER_PLACED, '', ''), 'An empty message is simply not sent.');
    }

    public function test_templates_belong_to_their_own_company(): void
    {
        $other = $this->makeCompany('Other Co', 'other-co');
        $this->write([EmailTemplateService::ORDER_PLACED => ['subject' => 'Ours', 'body' => 'Ours']]);
        app(EmailTemplateService::class)->save($other->id, [EmailTemplateService::ORDER_PLACED => ['subject' => 'Theirs', 'body' => 'Theirs']]);

        $service = app(EmailTemplateService::class);

        $this->assertSame('Ours', $service->row($this->company->id, EmailTemplateService::ORDER_PLACED)->subject);
        $this->assertSame('Theirs', $service->row($other->id, EmailTemplateService::ORDER_PLACED)->subject);
        $this->assertCount(1, $service->rows($other->id));
    }

    public function test_the_layout_carries_the_sites_colour_and_the_company_name_and_details(): void
    {
        $this->company->forceFill(['phone' => '+880 1700 000000', 'address' => 'Dhaka, Bangladesh'])->save();
        $this->site->forceFill(['theme_color' => '#064C38'])->save();
        $this->write([EmailTemplateService::ORDER_PLACED => ['subject' => 'Hi', 'body' => 'Body']]);

        $service = app(EmailTemplateService::class);
        $html = $service->wrap($this->company->id, $service->render($this->company->id, EmailTemplateService::ORDER_PLACED, []))->render();

        $this->assertStringContainsString('#064C38', $html);
        $this->assertStringContainsString('Template Co', $html);
        $this->assertStringContainsString('Dhaka, Bangladesh', $html);
        $this->assertStringContainsString('+880 1700 000000', $html);
    }

    // ---------------------------------------------------------------- order emails

    public function test_a_stage_with_wording_mails_the_customer_and_a_stage_without_stays_silent(): void
    {
        $this->write([EmailTemplateService::orderKey(Order::STAGE_PROCESSING) => ['subject' => 'Order :order is :status', 'body' => "Hi :name, we are packing: :items. Due :due.\n\n:company"]]);
        [$order] = $this->orderFor('rahim@example.test');
        $workflow = app(OrderStatusWorkflowService::class);

        $order = $workflow->transition($order, Order::STAGE_CONFIRMED);
        Mail::assertNothingSent();
        $this->assertSame(0, EmailLog::query()->count(), 'A stage with no wording leaves no trace.');

        $workflow->transition($order, Order::STAGE_PROCESSING);

        Mail::assertSent(TemplatedEmail::class, 1);
        Mail::assertSent(TemplatedEmail::class, function (TemplatedEmail $mail) use ($order): bool {
            return $mail->hasTo('rahim@example.test')
                && $mail->mailSubject === "Order {$order->order_number} is Processing"
                && str_contains($mail->textBody, 'Hi Template Customer, we are packing: Template Product x2. Due 200.')
                && str_contains($mail->htmlBody, 'Template Product x2');
        });

        $log = EmailLog::query()->sole();
        $this->assertSame(EmailLog::KIND_ORDER, $log->kind);
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertSame('rahim@example.test', $log->recipient);
    }

    public function test_a_new_order_mails_the_customer_after_a_short_wait(): void
    {
        $this->write([EmailTemplateService::ORDER_PLACED => ['subject' => 'We got order :order', 'body' => 'Hi :name, total :total, due :due. Status: :status']]);
        Queue::fake();

        [$order] = $this->orderFor('rahim@example.test');

        Queue::assertPushed(SendOrderEmailJob::class, fn (SendOrderEmailJob $job): bool => $job->orderId === $order->id && $job->event === 'placed' && $job->delay !== null);

        $this->assertTrue(app(OrderStatusEmailService::class)->send($order->id, 'placed'));

        Mail::assertSent(TemplatedEmail::class, fn (TemplatedEmail $mail): bool => $mail->hasTo('rahim@example.test') && str_contains($mail->textBody, 'total 200, due 200. Status: Draft'));
    }

    public function test_nothing_is_queued_for_a_new_order_while_no_wording_is_written(): void
    {
        Queue::fake();

        $this->orderFor('rahim@example.test');

        Queue::assertNotPushed(SendOrderEmailJob::class);
    }

    public function test_orders_that_must_not_be_mailed_are_skipped_and_the_log_says_why(): void
    {
        $this->write([EmailTemplateService::orderKey(Order::STAGE_CONFIRMED) => ['subject' => 'Confirmed', 'body' => 'Order :order confirmed']]);
        $reseller = Customer::query()->create(['name' => 'A Reseller', 'phone' => '01799999999', 'email' => 'reseller@example.test']);
        $cases = [
            'excluded' => Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'Buyer', 'phone' => '01711111111', 'email' => 'buyer@example.test'])->id, 'reseller_customer_id' => $reseller->id, 'status' => Order::STATUS_DRAFT]),
            'no_email' => Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'No Email', 'phone' => '01733333333'])->id, 'status' => Order::STATUS_DRAFT]),
            'opted_out' => Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'Quiet', 'phone' => '01744444444', 'email' => 'quiet@example.test', 'opted_out_at' => now()])->id, 'status' => Order::STATUS_DRAFT]),
        ];

        foreach ($cases as $order) {
            $order->update(['status' => Order::STATUS_CONFIRMED]);
        }

        Mail::assertNothingSent();
        $this->assertEqualsCanonicalizing(array_keys($cases), EmailLog::query()->pluck('reason')->all());
        $this->assertSame([EmailLog::STATUS_SKIPPED], EmailLog::query()->pluck('status')->unique()->values()->all());

        $plain = Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'Plain', 'phone' => '01755555555', 'email' => 'plain@example.test'])->id, 'status' => Order::STATUS_DRAFT]);
        $plain->update(['status' => Order::STATUS_CONFIRMED]);

        Mail::assertSent(TemplatedEmail::class, 1);
        Mail::assertSent(TemplatedEmail::class, fn (TemplatedEmail $mail): bool => $mail->hasTo('plain@example.test'));
    }

    public function test_the_same_change_is_never_mailed_twice(): void
    {
        $this->write([EmailTemplateService::orderKey(Order::STAGE_CONFIRMED) => ['subject' => 'Confirmed', 'body' => 'Order :order confirmed']]);
        [$order] = $this->orderFor('rahim@example.test');
        $order->update(['status' => Order::STATUS_CONFIRMED]);

        $transitionId = (int) $order->statusTransitions()->latest('id')->value('id');
        $this->assertFalse(app(OrderStatusEmailService::class)->send($order->id, Order::STAGE_CONFIRMED, $transitionId), 'A retried job must not mail it again.');

        Mail::assertSent(TemplatedEmail::class, 1);
        $this->assertContains(EmailLogService::REASON_DUPLICATE, EmailLog::query()->pluck('reason')->all());
    }

    public function test_an_order_email_with_no_email_account_is_logged_not_lost(): void
    {
        $this->site->forceFill(['notification_credentials' => []])->save();
        config(['mail.default' => 'log']);
        $this->write([EmailTemplateService::orderKey(Order::STAGE_CONFIRMED) => ['subject' => 'Confirmed', 'body' => 'Order :order confirmed']]);
        [$order] = $this->orderFor('rahim@example.test');

        $order->update(['status' => Order::STATUS_CONFIRMED]);

        Mail::assertNothingSent();
        $this->assertSame(EmailLogService::REASON_NOT_CONFIGURED, EmailLog::query()->sole()->reason);
    }

    // ---------------------------------------------------------------- code, reset and IMS mails

    public function test_the_login_code_goes_out_as_before_until_a_template_is_written(): void
    {
        $notifications = app(StorefrontNotificationService::class);

        $this->assertTrue($notifications->sendLoginOtpEmail($this->site, 'buyer@example.test', 'Template Co', '482915'));
        Mail::assertSent(StorefrontLoginOtp::class, 1);
        Mail::assertNotSent(TemplatedEmail::class);

        $this->write([EmailTemplateService::LOGIN_CODE => ['subject' => 'Your :company code', 'body' => 'Use :code within :minutes minutes.']]);
        $this->assertTrue($notifications->sendLoginOtpEmail($this->site, 'buyer@example.test', 'Template Co', '482915'));

        Mail::assertSent(TemplatedEmail::class, fn (TemplatedEmail $mail): bool => $mail->mailSubject === 'Your Template Co code' && str_contains($mail->textBody, 'Use 482915 within 10 minutes.'));
        $this->assertStringNotContainsString('482915', json_encode(EmailLog::query()->get()->toArray()), 'A code is never kept in the log.');
    }

    public function test_the_reseller_reset_link_uses_the_template_and_keeps_the_link(): void
    {
        $this->write([EmailTemplateService::RESELLER_RESET => ['subject' => null, 'body' => "Reset here: :link\nValid :minutes minutes."]]);

        $this->assertTrue(app(StorefrontNotificationService::class)->sendResellerPasswordResetEmail($this->site, 'r@example.test', 'Template Co', 'https://shop.test/reset/abc', 30));

        Mail::assertSent(TemplatedEmail::class, fn (TemplatedEmail $mail): bool => $mail->mailSubject === 'Template Co reseller password reset'
            && str_contains($mail->textBody, 'Reset here: https://shop.test/reset/abc')
            && str_contains($mail->htmlBody, 'href="https://shop.test/reset/abc"'));
    }

    public function test_an_investor_code_mail_is_the_built_in_one_until_a_template_is_written(): void
    {
        $service = app(EmailTemplateService::class);
        $fallback = new ImsMessage('Template Co', 'Your code to join', ['Your code is 123456.']);

        $this->assertSame($fallback, $service->mailableOr($this->company->id, EmailTemplateService::IMS_JOIN_CODE, ['code' => '123456'], $fallback));

        $this->write([EmailTemplateService::IMS_JOIN_CODE => ['subject' => null, 'body' => 'Join with :code']]);
        $mail = $service->mailableOr($this->company->id, EmailTemplateService::IMS_JOIN_CODE, ['company' => 'Template Co', 'code' => '123456', 'minutes' => 10], $fallback);

        $this->assertInstanceOf(TemplatedEmail::class, $mail);
        $this->assertSame('Template Co — Your code to join', $mail->mailSubject);
        $this->assertStringContainsString('Join with 123456', $mail->textBody);
    }

    public function test_ims_news_uses_the_written_template_for_the_email(): void
    {
        $investor = $this->newInvestor(['email' => 'investor@example.test']);
        ImsSettings::save($this->company, ['channels' => ['payout_paid' => ['email' => true, 'sms' => false]]]);
        Queue::fake();

        app(ImsNotifier::class)->send('payout_paid', $investor, ['amount' => 1500]);
        Queue::assertPushed(SendImsMessageJob::class, fn (SendImsMessageJob $job): bool => $job->template === null);

        $this->write([EmailTemplateService::imsKey('payout_paid') => ['subject' => 'Money for :name', 'body' => "Dear :name,\n\nWe paid you :amount.\n\n:company"]]);
        Queue::fake();

        app(ImsNotifier::class)->send('payout_paid', $investor, ['amount' => 1500, 'url' => 'https://shop.test/portal']);

        Queue::assertPushed(SendImsMessageJob::class, function (SendImsMessageJob $job) use ($investor): bool {
            return $job->template !== null
                && $job->template['subject'] === "Money for {$investor->name}"
                && str_contains($job->template['text'], 'We paid you');
        });
    }

    public function test_the_ims_job_sends_the_branded_mail_with_a_button_and_the_pdf(): void
    {
        $this->site->forceFill(['notification_credentials' => $this->site->notification_credentials])->save();
        $service = app(EmailTemplateService::class);
        $template = ['subject' => 'Your statement', 'text' => 'Dear Investor, see attached.', 'html' => $service->toHtml('Dear Investor, see attached.', [])];

        (new SendImsMessageJob($this->company->id, '', null, 'investor@example.test', 'Statement', ['Body'], 'https://shop.test/portal', 'statement.pdf', base64_encode('%PDF-1.4'), $template))
            ->handle(app(SmsTemplateService::class), app(StorefrontNotificationService::class), $service);

        Mail::assertSent(TemplatedEmail::class, fn (TemplatedEmail $mail): bool => $mail->hasTo('investor@example.test')
            && $mail->link === 'https://shop.test/portal'
            && $mail->pdfName === 'statement.pdf'
            && count($mail->attachments()) === 1);
    }

    // ---------------------------------------------------------------- the page

    public function test_the_page_opens_for_a_settings_manager_and_not_for_other_staff(): void
    {
        $this->assertTrue(EmailTemplates::canAccess());

        Auth::login(User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));
        $this->assertFalse(EmailTemplates::canAccess());
    }

    public function test_the_page_saves_templates_and_removes_an_emptied_one(): void
    {
        $this->write([EmailTemplateService::orderKey(Order::STAGE_SHIPPING) => ['subject' => 'Old', 'body' => 'Old text']]);
        $field = fn (string $key, string $part): string => EmailTemplates::field($key, $part);

        Livewire::test(EmailTemplates::class)
            ->assertSet('data.'.$field(EmailTemplateService::orderKey(Order::STAGE_SHIPPING), 'subject'), 'Old')
            ->fillForm([
                $field(EmailTemplateService::orderKey(Order::STAGE_SHIPPING), 'subject') => '',
                $field(EmailTemplateService::orderKey(Order::STAGE_SHIPPING), 'body') => '',
                $field(EmailTemplateService::ORDER_PLACED, 'subject') => 'We got it',
                $field(EmailTemplateService::ORDER_PLACED, 'body') => 'Hi :name, order :order.',
                $field(EmailTemplateService::LOGIN_CODE, 'body') => 'Your code is :code',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $service = app(EmailTemplateService::class);

        $this->assertFalse($service->written($this->company->id, EmailTemplateService::orderKey(Order::STAGE_SHIPPING)));
        $this->assertSame('We got it', $service->row($this->company->id, EmailTemplateService::ORDER_PLACED)->subject);
        $this->assertNull($service->row($this->company->id, EmailTemplateService::LOGIN_CODE)->subject, 'A code message may keep the built-in subject.');
    }

    public function test_the_page_refuses_a_code_message_without_the_code_and_an_order_message_without_a_subject(): void
    {
        $field = fn (string $key, string $part): string => EmailTemplates::field($key, $part);

        Livewire::test(EmailTemplates::class)
            ->fillForm([
                $field(EmailTemplateService::LOGIN_CODE, 'body') => 'Welcome back.',
                $field(EmailTemplateService::ORDER_PLACED, 'subject') => '',
                $field(EmailTemplateService::ORDER_PLACED, 'body') => 'We got it.',
            ])
            ->call('save')
            ->assertHasErrors(['data.'.$field(EmailTemplateService::LOGIN_CODE, 'body'), 'data.'.$field(EmailTemplateService::ORDER_PLACED, 'body')]);

        $this->assertSame(0, EmailTemplate::withoutGlobalScopes()->count());
    }

    public function test_the_preview_shows_the_branded_message_with_example_values_and_sends_nothing(): void
    {
        $service = app(EmailTemplateService::class);
        $placeholders = $service->orderPlaceholders();

        $preview = $service->preview($this->company->id, EmailTemplateService::ORDER_PLACED, 'We got order :order', "Dear :name,\n\nTotal Tk :total", $placeholders);

        $this->assertSame('We got order ZZ-10245', $preview['subject']);
        $this->assertStringContainsString('Dear Rahim Uddin,', $preview['html']);
        $this->assertStringContainsString('Total Tk 3,450', $preview['html']);
        $this->assertStringContainsString('Template Co', $preview['html'], 'The preview wears the company\'s layout.');

        // An order message with no text has nothing to preview; a built-in message shows its built-in wording.
        $this->assertNull($service->preview($this->company->id, EmailTemplateService::ORDER_PLACED, null, '', $placeholders));
        $builtIn = $service->preview($this->company->id, EmailTemplateService::LOGIN_CODE, null, '', $service->definitions()[EmailTemplateService::LOGIN_CODE]['placeholders']);
        $this->assertStringContainsString('483920', $builtIn['html']);

        // The Preview button opens on the page without an error and sends nothing.
        $body = EmailTemplates::field(EmailTemplateService::ORDER_PLACED, 'body');
        Livewire::test(EmailTemplates::class)
            ->fillForm([EmailTemplates::field(EmailTemplateService::ORDER_PLACED, 'subject') => 'We got order :order', $body => 'Dear :name'])
            ->mountAction(TestAction::make('preview_'.$body)->schemaComponent($body, schema: 'form'))
            ->assertHasNoErrors();

        Mail::assertNothingSent();
    }

    public function test_a_test_email_goes_to_the_address_given_and_is_logged_as_a_test(): void
    {
        $subject = EmailTemplates::field(EmailTemplateService::ORDER_PLACED, 'subject');
        $body = EmailTemplates::field(EmailTemplateService::ORDER_PLACED, 'body');

        Livewire::test(EmailTemplates::class)
            ->fillForm([$subject => 'We got order :order', $body => 'Dear :name, order :order.'])
            ->callAction(TestAction::make('test_'.$body)->schemaComponent($body, schema: 'form'), ['email' => 'owner@example.test'])
            ->assertNotified('Test email sent');

        Mail::assertSent(TemplatedEmail::class, fn (TemplatedEmail $mail): bool => $mail->hasTo('owner@example.test')
            && $mail->mailSubject === 'We got order ZZ-10245'
            && str_contains($mail->textBody, 'Dear Rahim Uddin, order ZZ-10245.'));
        $this->assertSame(EmailLog::KIND_TEST, EmailLog::query()->sole()->kind);
    }

    public function test_a_test_email_without_an_email_account_says_so_and_sends_nothing(): void
    {
        $this->site->forceFill(['notification_credentials' => []])->save();
        config(['mail.default' => 'log']);
        $body = EmailTemplates::field(EmailTemplateService::ORDER_PLACED, 'body');

        Livewire::test(EmailTemplates::class)
            ->fillForm([EmailTemplates::field(EmailTemplateService::ORDER_PLACED, 'subject') => 'Hi', $body => 'Hello :name'])
            ->callAction(TestAction::make('test_'.$body)->schemaComponent($body, schema: 'form'), ['email' => 'owner@example.test'])
            ->assertNotified('No email account is set up');

        Mail::assertNothingSent();
    }

    public function test_the_owners_own_templates_are_added_edited_and_removed_on_the_page(): void
    {
        $page = Livewire::test(EmailTemplates::class)
            ->fillForm(['custom' => [['name' => 'Late parcel', 'subject' => 'Sorry :name', 'body' => 'Your order :order is late.']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $row = EmailTemplate::withoutGlobalScopes()->where('is_custom', true)->sole();
        $this->assertSame('Late parcel', $row->name);
        $this->assertStringStartsWith('custom.', $row->key);

        $page->fillForm(['custom' => [['id' => $row->id, 'name' => 'Late parcel 2', 'subject' => 'Sorry', 'body' => 'Late.']]])->call('save');
        $this->assertSame('Late parcel 2', $row->fresh()->name);

        $page->fillForm(['custom' => []])->call('save');
        $this->assertSame(0, EmailTemplate::withoutGlobalScopes()->count());
    }

    // ---------------------------------------------------------------- "Email the customer"

    public function test_staff_email_the_customer_a_template_of_their_own_from_the_order(): void
    {
        [$order] = $this->orderFor('rahim@example.test');
        app(EmailTemplateService::class)->saveCustom($this->company->id, [['name' => 'Late parcel', 'subject' => 'Sorry about order :order', 'body' => 'Dear :name, order :order (:items) is late.']]);
        $template = EmailTemplate::withoutGlobalScopes()->where('is_custom', true)->sole();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('emailCustomer')
            ->callAction('emailCustomer', ['template' => $template->id])
            ->assertNotified('Email sent');

        Mail::assertSent(TemplatedEmail::class, fn (TemplatedEmail $mail): bool => $mail->hasTo('rahim@example.test')
            && $mail->mailSubject === "Sorry about order {$order->order_number}"
            && str_contains($mail->textBody, 'Dear Template Customer, order '.$order->order_number.' (Template Product x2) is late.'));
        $this->assertSame(EmailLog::KIND_TEMPLATE, EmailLog::query()->sole()->kind);
    }

    public function test_the_button_is_hidden_without_a_template_or_an_address_and_a_missing_address_is_refused(): void
    {
        [$order] = $this->orderFor('rahim@example.test');

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->assertActionHidden('emailCustomer');

        app(EmailTemplateService::class)->saveCustom($this->company->id, [['name' => 'Late parcel', 'subject' => 'Sorry', 'body' => 'Late.']]);
        $template = EmailTemplate::withoutGlobalScopes()->where('is_custom', true)->sole();
        [$noEmail] = $this->orderFor(null);

        Livewire::test(ViewOrder::class, ['record' => $noEmail->getKey()])->assertActionHidden('emailCustomer');
        $this->assertSame('This customer has no email address.', app(OrderStatusEmailService::class)->sendCustom($noEmail, $template));
        Mail::assertNothingSent();
    }

    public function test_a_user_without_order_edit_rights_does_not_get_the_button(): void
    {
        [$order] = $this->orderFor('rahim@example.test');
        app(EmailTemplateService::class)->saveCustom($this->company->id, [['name' => 'Late parcel', 'subject' => 'Sorry', 'body' => 'Late.']]);

        UserRole::query()->create(['name' => 'Viewer', 'slug' => 'order_viewer', 'permissions' => ['sales.view'], 'is_active' => true]);
        $viewer = User::factory()->create(['role' => 'order_viewer', 'is_active' => true]);
        $viewer->companies()->attach($this->company, ['role' => 'order_viewer', 'is_default' => true]);
        Auth::login($viewer);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->assertActionHidden('emailCustomer');
    }

    // ---------------------------------------------------------------- helpers

    /** @param  array<string, array{subject: ?string, body: ?string}>  $templates */
    private function write(array $templates): void
    {
        app(EmailTemplateService::class)->save($this->company->id, $templates);
    }

    private function makeCompany(string $name, string $slug): Company
    {
        $company = Company::query()->create([
            'name' => $name, 'slug' => $slug, 'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT',
            'timezone' => 'UTC', 'is_active' => true,
        ]);

        StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => [
                'mail_host' => 'smtp.example.test', 'mail_port' => 587, 'mail_encryption' => 'tls',
                'mail_username' => 'user', 'mail_password' => 'secret', 'mail_from_address' => 'hello@shop.test',
            ],
        ]);

        return $company;
    }

    /** @return array{0: Order, 1: Customer} an order of 200 (2 x 100) */
    private function orderFor(?string $email): array
    {
        $customer = Customer::query()->create(['name' => 'Template Customer', 'phone' => '01712345678', 'email' => $email]);
        $product = Product::query()->create(['name' => 'Template Product', 'sku' => 'TPL-'.uniqid(), 'price' => 100, 'sale_price' => 100, 'stock' => 0]);
        StockMovement::query()->create(['product_id' => $product->id, 'type' => 'opening', 'quantity' => 5]);
        $order = Order::query()->create(['customer_id' => $customer->id, 'paid_amount' => 0, 'status' => Order::STATUS_DRAFT]);
        OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]);

        return [$order->fresh(), $customer];
    }
}
