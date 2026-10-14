<?php

namespace Tests\Feature;

use App\Models\StorefrontSetting;
use App\Services\StorefrontNotificationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StorefrontSmsGatewayTest extends TestCase
{
    private const BULKSMSBD_URL = 'http://bulksmsbd.net/api/smsapi?api_key={api_key}&type=text&number={phone}&senderid={sender_id}&message={message}';

    /** @var mixed what the faked gateway answers next */
    protected $reply;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['bulksmsbd.net/*' => fn () => $this->reply]);
    }

    protected function setting(): StorefrontSetting
    {
        return (new StorefrontSetting)->forceFill([
            'company_id' => 1,
            'notification_credentials' => ['sms_api_url' => self::BULKSMSBD_URL, 'sms_api_key' => 'k&1', 'sms_sender_id' => '8809617000000'],
        ]);
    }

    protected function send(string $phone = '01712345678'): bool
    {
        return app(StorefrontNotificationService::class)->sendSms($this->setting(), $phone, 'Your Shop OTP is 123456 & more');
    }

    public function test_the_request_follows_the_bulksmsbd_format_with_the_country_code_and_an_encoded_message(): void
    {
        $this->reply = Http::response(['response_code' => 202, 'success_message' => 'SMS Submitted Successfully'], 200);

        $this->assertTrue($this->send('01712345678'));

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['number'] === '8801712345678'
                && $query['api_key'] === 'k&1'
                && $query['senderid'] === '8809617000000'
                && $query['type'] === 'text'
                && $query['message'] === 'Your Shop OTP is 123456 & more';
        });
    }

    public function test_numbers_already_in_country_code_form_are_not_changed(): void
    {
        $this->reply = Http::response(['response_code' => 202], 200);

        $this->assertTrue($this->send('+8801712345678'));
        $this->assertTrue($this->send('8801712345678'));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'number=8801712345678&'));
    }

    public function test_a_refusal_inside_an_http_200_is_a_failed_send(): void
    {
        Log::spy();

        foreach ([1007, 1002, 1032] as $code) {
            $this->reply = Http::response(['response_code' => $code, 'error_message' => 'refused'], 200);

            $this->assertFalse($this->send(), "code {$code} must not count as sent");
        }

        Log::shouldHaveReceived('warning')->with('Storefront SMS refused by gateway', \Mockery::on(
            fn (array $context): bool => $context['code'] === 1032 && $context['meaning'] === 'IP not whitelisted',
        ));
    }

    public function test_a_plain_text_reply_code_is_understood_too(): void
    {
        $this->reply = Http::response('1007', 200);
        $this->assertFalse($this->send());

        $this->reply = Http::response('202', 200);
        $this->assertTrue($this->send());
    }

    public function test_gateways_without_a_reply_code_still_go_by_the_http_status(): void
    {
        $this->reply = Http::response('OK', 200);
        $this->assertTrue($this->send());

        // A message id that happens to be four digits is not a reply code.
        $this->reply = Http::response('4821', 200);
        $this->assertTrue($this->send());

        $this->reply = Http::response('down', 500);
        $this->assertFalse($this->send());
    }
}
