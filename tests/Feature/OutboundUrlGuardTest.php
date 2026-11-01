<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebsiteWebhookJob;
use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Rules\SafeOutboundUrl;
use App\Services\StorefrontNotificationService;
use App\Services\ZiniPayClient;
use App\Support\OutboundUrlGuard;
use App\Support\UnsafeOutboundUrl;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Server-side request forgery: an address an admin (or a remote service) hands
 * the server must never lead it to the internal network.
 */
class OutboundUrlGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        OutboundUrlGuard::resolveUsing(null);

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/hook'],
            'loopback range' => ['https://127.1.2.3/hook'],
            'localhost' => ['https://localhost/hook'],
            'localhost subdomain' => ['https://app.localhost/hook'],
            'private 10/8' => ['https://10.0.0.5/hook'],
            'private 172.16/12' => ['https://172.16.4.2/hook'],
            'private 192.168/16' => ['https://192.168.1.10/hook'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'carrier grade nat' => ['https://100.64.0.1/hook'],
            'unspecified' => ['https://0.0.0.0/hook'],
            'ipv6 loopback' => ['https://[::1]/hook'],
            'ipv6 unique local' => ['https://[fd00::1]/hook'],
            'ipv6 link local' => ['https://[fe80::1]/hook'],
            'ipv4 mapped ipv6 loopback' => ['https://[::ffff:127.0.0.1]/hook'],
            'ipv4 mapped ipv6 metadata' => ['https://[::ffff:169.254.169.254]/hook'],
            'decimal ip' => ['https://2130706433/hook'],
            'hex ip' => ['https://0x7f.0.0.1/hook'],
            'octal ip' => ['https://017700000001/hook'],
            'single label host' => ['https://whatsapp-bridge/hook'],
            'internal tld' => ['https://metadata.google.internal/computeMetadata/v1/'],
            'local tld' => ['https://printer.local/hook'],
            'credentials in the url' => ['https://user:pass@example.com/hook'],
            'ftp scheme' => ['ftp://example.com/file'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://example.com/_x'],
            'plain http' => ['http://example.com/hook'],
            'not a url' => ['not a url'],
            'no host' => ['https:///hook'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_unsafe_addresses_are_refused(string $url): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host): array => ['93.184.216.34']);

        $this->assertNotNull(OutboundUrlGuard::reason($url), "{$url} should have been refused");
        $this->assertFalse(OutboundUrlGuard::isSafe($url));

        $this->expectException(UnsafeOutboundUrl::class);
        OutboundUrlGuard::assertSafe($url);
    }

    public function test_a_public_https_address_is_allowed_and_its_connection_is_pinned_to_the_checked_ip(): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host): array => ['93.184.216.34']);

        $this->assertNull(OutboundUrlGuard::reason('https://shop.example.com/wp-json'));

        $options = (fn () => $this->options)->call(OutboundUrlGuard::http('https://shop.example.com:8443/x'));

        $this->assertSame(['shop.example.com:8443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    public function test_plain_http_is_allowed_only_where_the_caller_says_so_and_still_must_be_public(): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host): array => ['93.184.216.34']);

        $this->assertNull(OutboundUrlGuard::reason('http://sms.example.com/api', requireHttps: false));
        $this->assertNotNull(OutboundUrlGuard::reason('http://10.1.1.1/api', requireHttps: false));
        $this->assertNotNull(OutboundUrlGuard::reason('http://169.254.169.254/', requireHttps: false));
    }

    public function test_a_name_that_resolves_to_a_private_address_is_refused(): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host): array => ['127.0.0.1']);
        $this->assertNotNull(OutboundUrlGuard::reason('https://innocent.example.com/hook'));

        // One private answer among public ones is enough (DNS rebinding / round robin).
        OutboundUrlGuard::resolveUsing(fn (string $host): array => ['93.184.216.34', '10.0.0.7']);
        $this->assertNotNull(OutboundUrlGuard::reason('https://mixed.example.com/hook'));

        OutboundUrlGuard::resolveUsing(fn (string $host): array => ['2606:2800:220:1:248:1893:25c8:1946', '::1']);
        $this->assertNotNull(OutboundUrlGuard::reason('https://v6.example.com/hook'));
    }

    public function test_a_name_that_does_not_resolve_is_refused_in_production_only(): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host): array => []);

        $this->assertNull(OutboundUrlGuard::reason('https://made-up.example.test/hook'));

        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertNotNull(OutboundUrlGuard::reason('https://made-up.example.test/hook'));
    }

    public function test_a_redirect_to_the_internal_network_is_refused(): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host): array => ['93.184.216.34']);

        $options = (fn () => $this->options)->call(OutboundUrlGuard::http('https://shop.example.com/x'));
        $redirect = $options['allow_redirects'];

        $this->assertSame(['https'], $redirect['protocols']);
        $this->assertLessThanOrEqual(3, $redirect['max']);

        $request = new Request('GET', 'https://shop.example.com/x');
        $response = new Response(302);

        // A public redirect target is fine ...
        $redirect['on_redirect']($request, $response, new Uri('https://www.example.com/x'));

        // ... the metadata address is not.
        $this->expectException(UnsafeOutboundUrl::class);
        $redirect['on_redirect']($request, $response, new Uri('https://169.254.169.254/latest/meta-data/'));
    }

    public function test_the_settings_can_open_private_hosts_for_a_development_machine(): void
    {
        $this->assertNotNull(OutboundUrlGuard::reason('http://localhost:3000/hook'));

        config(['outbound.allowed_hosts' => ['erp-bridge']]);
        $this->assertNull(OutboundUrlGuard::reason('https://erp-bridge/hook'));

        config(['outbound.allowed_hosts' => [], 'outbound.allow_private_hosts' => true]);
        $this->assertNull(OutboundUrlGuard::reason('http://localhost:3000/hook'));
    }

    public function test_the_validation_rule_refuses_unsafe_addresses_when_they_are_saved(): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host): array => ['93.184.216.34']);

        $check = fn (string $value, SafeOutboundUrl $rule): bool => ! Validator::make(['url' => $value], ['url' => [$rule]])->fails();

        $this->assertTrue($check('https://shop.example.com', new SafeOutboundUrl));
        $this->assertTrue($check('', new SafeOutboundUrl));
        $this->assertFalse($check('https://192.168.0.9', new SafeOutboundUrl));
        $this->assertFalse($check('http://shop.example.com', new SafeOutboundUrl));

        // An SMS gateway template keeps its {placeholders}; only its host is judged.
        $sms = new SafeOutboundUrl(requireHttps: false, template: true);
        $this->assertTrue($check('http://sms.example.com/api?key={api_key}&to={phone}&msg={message}', $sms));
        $this->assertFalse($check('http://169.254.169.254/?to={phone}&msg={message}', $sms));
    }

    public function test_the_website_webhook_never_calls_an_internal_address(): void
    {
        Http::fake();

        (new DispatchWebsiteWebhookJob('https://169.254.169.254/latest/meta-data/', 'secret', 'ping', []))->handle();
        (new DispatchWebsiteWebhookJob('https://10.0.0.8:6379/', 'secret', 'ping', []))->handle();

        Http::assertNothingSent();
    }

    public function test_the_website_webhook_still_reaches_a_public_site(): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host): array => ['93.184.216.34']);
        Http::fake(['website.example.com/*' => Http::response(['ok' => true])]);

        (new DispatchWebsiteWebhookJob('https://website.example.com/webhooks/zamzam', 'secret', 'ping', []))->handle();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://website.example.com/webhooks/zamzam'
            && $request->hasHeader('X-ZamZam-Signature'));
    }

    public function test_a_payment_gateway_base_url_pointing_inside_the_network_is_never_called(): void
    {
        Http::fake();

        $setting = new StorefrontSetting(['payment_credentials' => ['zinipay_api_key' => 'k', 'zinipay_base_url' => 'https://127.0.0.1:8080']]);

        try {
            app(ZiniPayClient::class)->verifyPayment($setting, 'INV-1');
            $this->fail('The internal gateway address should have been refused.');
        } catch (UnsafeOutboundUrl) {
            // expected
        }

        Http::assertNothingSent();
    }

    public function test_an_sms_gateway_template_pointing_at_the_metadata_service_is_never_called(): void
    {
        Http::fake();

        $company = Company::query()->create([
            'name' => 'Sms Co', 'slug' => 'sms-co', 'invoice_prefix' => 'SMS',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        $setting = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'notification_credentials' => [
                'sms_api_url' => 'http://169.254.169.254/latest/meta-data/?to={phone}&msg={message}',
                'sms_api_key' => 'k',
            ],
        ]);

        $result = app(StorefrontNotificationService::class)->sendSmsDetailed($setting, '01711223344', 'hello', ownGatewayOnly: true);

        $this->assertFalse($result['ok']);
        $this->assertSame('gateway_unreachable', $result['reason']);
        Http::assertNothingSent();
    }
}
