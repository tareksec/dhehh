<?php

namespace Tests\Feature;

use App\Filament\Pages\Integrations;
use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Support\SmsGatewayUrl;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The SMS gateway address follows the API key and sender ID boxes (owner request 2026-10-23): the owner types
 * those two and the address is built from them, the way {phone} and {message} are filled in at send time.
 */
class SmsGatewayUrlTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE = 'http://bulksmsbd.net/api/smsapi?api_key=api_key&type=text&number=Receiver&senderid=SenderId&message=TestSMS';

    private Company $company;

    private StorefrontSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Gateway Co', 'slug' => 'gateway-co', 'invoice_prefix' => 'GWC', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true,
        ]);
        $this->setting = StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => [],
        ]);
        app(CompanyContext::class)->set($this->company);
    }

    public function test_a_pasted_address_with_a_real_key_and_sender_id_goes_back_to_placeholders_and_the_values_move_to_their_boxes(): void
    {
        $tidy = SmsGatewayUrl::tidy('http://bulksmsbd.net/api/smsapi?api_key=AbC123&type=text&number=Receiver&senderid=8809617643387&message=TestSM');

        $this->assertSame(SmsGatewayUrl::BULKSMSBD, $tidy['url']);
        $this->assertSame('AbC123', $tidy['api_key']);
        $this->assertSame('8809617643387', $tidy['sender_id']);
        $this->assertTrue($tidy['changed']);
    }

    public function test_sample_words_are_dropped_and_never_taken_for_a_key_or_sender_id(): void
    {
        $tidy = SmsGatewayUrl::tidy(self::SAMPLE);

        $this->assertSame(SmsGatewayUrl::BULKSMSBD, $tidy['url']);
        $this->assertSame('', $tidy['api_key']);
        $this->assertSame('', $tidy['sender_id']);
    }

    public function test_a_filled_box_wins_over_a_value_typed_in_the_address(): void
    {
        $tidy = SmsGatewayUrl::tidy('http://bulksmsbd.net/api/smsapi?api_key=FromUrl&number={phone}&senderid=UrlSender&message={message}', 'FromBox', '8809617643387');

        $this->assertSame('FromBox', $tidy['api_key']);
        $this->assertSame('8809617643387', $tidy['sender_id']);
        $this->assertSame('http://bulksmsbd.net/api/smsapi?api_key={api_key}&number={phone}&senderid={sender_id}&message={message}', $tidy['url']);
    }

    public function test_an_address_that_is_already_right_and_another_gateways_address_are_left_alone(): void
    {
        $this->assertFalse(SmsGatewayUrl::tidy(SmsGatewayUrl::BULKSMSBD, 'k', '88096')['changed']);
        // A country code written before {phone} is kept as typed (the sender handles it).
        $this->assertFalse(SmsGatewayUrl::tidy('http://bulksmsbd.net/api/smsapi?api_key={api_key}&number=88{phone}&senderid={sender_id}&message={message}')['changed']);

        $other = 'http://sms.example.test/send?key=real&to=Receiver&msg=hello';
        $tidy = SmsGatewayUrl::tidy($other);
        $this->assertSame($other, $tidy['url']);
        $this->assertFalse($tidy['changed']);
        $this->assertFalse(SmsGatewayUrl::isBulkSmsBd($other));
        $this->assertTrue(SmsGatewayUrl::isBulkSmsBd(SmsGatewayUrl::BULKSMSBD));
    }

    public function test_the_preview_fills_the_sender_id_and_shows_only_the_start_of_the_key(): void
    {
        $preview = SmsGatewayUrl::preview(SmsGatewayUrl::BULKSMSBD, 'abcd1234wxyz', '8809617643387');

        $this->assertStringContainsString('api_key=abcd***&', $preview);
        $this->assertStringNotContainsString('1234wxyz', $preview);
        $this->assertStringContainsString('senderid=8809617643387&', $preview);
        $this->assertStringContainsString('number={phone}', $preview);
        $this->assertStringContainsString('message={message}', $preview);
        $this->assertStringContainsString('api_key=***&', SmsGatewayUrl::preview(SmsGatewayUrl::BULKSMSBD, 'short', ''));
    }

    public function test_typing_the_key_or_the_sender_id_builds_the_address_when_it_is_empty(): void
    {
        $this->signIn();

        Livewire::test(Integrations::class)
            ->assertSet('data.notification_credentials.sms_api_url', null)
            ->set('data.notification_credentials.sms_api_key', 'abcd1234wxyz')
            ->assertSet('data.notification_credentials.sms_api_url', SmsGatewayUrl::BULKSMSBD)
            ->set('data.notification_credentials.sms_sender_id', '8809617643387')
            ->assertSee('api_key=abcd***&amp;type=text&amp;number={phone}&amp;senderid=8809617643387&amp;message={message}', escape: false)
            ->assertDontSee('1234wxyz')
            ->call('save')
            ->assertHasNoFormErrors();

        $credentials = $this->setting->fresh()->notification_credentials;
        $this->assertSame(SmsGatewayUrl::BULKSMSBD, $credentials['sms_api_url']);
        $this->assertSame('abcd1234wxyz', $credentials['sms_api_key']);
        $this->assertSame('8809617643387', $credentials['sms_sender_id']);
    }

    public function test_another_gateways_address_is_not_replaced_when_the_key_is_typed(): void
    {
        $custom = 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}';
        $this->setting->update(['notification_credentials' => ['sms_api_url' => $custom]]);
        $this->signIn();

        Livewire::test(Integrations::class)
            ->set('data.notification_credentials.sms_api_key', 'abcd1234wxyz')
            ->assertSet('data.notification_credentials.sms_api_url', $custom);
    }

    /** The case that went wrong for the owner: the example address pasted into the box. */
    public function test_pasting_the_gateways_example_address_is_put_right_and_saves(): void
    {
        $this->signIn();

        Livewire::test(Integrations::class)
            ->set('data.notification_credentials.sms_api_url', 'http://bulksmsbd.net/api/smsapi?api_key=AbC123&type=text&number=Receiver&senderid=8809617643387&message=TestSM')
            ->assertSet('data.notification_credentials.sms_api_url', SmsGatewayUrl::BULKSMSBD)
            ->assertSet('data.notification_credentials.sms_api_key', 'AbC123')
            ->assertSet('data.notification_credentials.sms_sender_id', '8809617643387')
            ->call('save')
            ->assertHasNoFormErrors();

        $credentials = $this->setting->fresh()->notification_credentials;
        $this->assertSame(SmsGatewayUrl::BULKSMSBD, $credentials['sms_api_url']);
        $this->assertSame('AbC123', $credentials['sms_api_key']);
        $this->assertSame('8809617643387', $credentials['sms_sender_id']);
    }

    public function test_an_address_saved_earlier_with_sample_words_is_put_right_when_the_page_is_saved(): void
    {
        $this->setting->update(['notification_credentials' => ['sms_api_url' => self::SAMPLE, 'sms_api_key' => 'real-key', 'sms_sender_id' => '8809617643387']]);
        $this->signIn();

        Livewire::test(Integrations::class)->call('save')->assertHasNoFormErrors();

        $this->assertSame(SmsGatewayUrl::BULKSMSBD, $this->setting->fresh()->notification_credentials['sms_api_url']);
    }

    public function test_the_address_button_sets_the_bulksmsbd_address_and_keeps_a_key_found_in_the_old_one(): void
    {
        $this->signIn();
        $button = TestAction::make('useBulkSmsBdUrl')->schemaComponent('notification_credentials.sms_api_url');

        Livewire::test(Integrations::class)
            ->callAction($button)
            ->assertSet('data.notification_credentials.sms_api_url', SmsGatewayUrl::BULKSMSBD);

        // Another gateway's address is replaced (the button asks first); a BulkSMSBD one is tidied in place.
        $this->setting->update(['notification_credentials' => ['sms_api_url' => 'http://sms.example.test/send?to={phone}&msg={message}']]);

        Livewire::test(Integrations::class)
            ->callAction($button)
            ->assertSet('data.notification_credentials.sms_api_url', SmsGatewayUrl::BULKSMSBD);

        $this->setting->update(['notification_credentials' => ['sms_api_url' => 'http://bulksmsbd.net/api/smsapi?api_key=OldKey&number=Receiver&senderid=8809617643387&message=x']]);

        Livewire::test(Integrations::class)
            ->callAction($button)
            ->assertSet('data.notification_credentials.sms_api_url', 'http://bulksmsbd.net/api/smsapi?api_key={api_key}&number={phone}&senderid={sender_id}&message={message}')
            ->assertSet('data.notification_credentials.sms_api_key', 'OldKey')
            ->assertSet('data.notification_credentials.sms_sender_id', '8809617643387');
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey(), 'current_company_selection_explicit' => true]);
    }
}
