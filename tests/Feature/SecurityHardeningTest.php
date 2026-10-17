<?php

namespace Tests\Feature;

use App\Support\StorefrontErrorPages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    public function test_every_response_carries_the_baseline_security_headers(): void
    {
        Route::get('/_test/security-headers', fn () => 'ok')->middleware('web');

        $this->get('/_test/security-headers')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
    }

    public function test_responses_carry_a_script_safe_content_security_policy(): void
    {
        Route::get('/_test/csp', fn () => 'ok')->middleware('web');

        $this->get('/_test/csp')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
    }

    public function test_a_response_that_sets_its_own_content_security_policy_keeps_it(): void
    {
        Route::get('/_test/own-csp', fn () => response('ok')->header('Content-Security-Policy', "default-src 'none'; sandbox"))
            ->middleware('web');

        $this->get('/_test/own-csp')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        Route::get('/_test/hsts', fn () => 'ok')->middleware('web');

        $this->get('https://localhost/_test/hsts')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        $this->get('http://localhost/_test/hsts')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_sensitive_storefront_and_webhook_routes_are_throttled(): void
    {
        foreach ([
            'storefront.preview.account.login.store',
            'storefront.preview.account.register.store',
            'storefront.preview.account.forgot-password.store',
            'storefront.preview.account.reset-password.store',
            'storefront.preview.account.password.update',
            'storefront.account.password.update',
            'webhooks.meta.handle',
        ] as $name) {
            $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

            $this->assertNotEmpty(
                array_filter($middleware, fn ($entry): bool => is_string($entry) && str_starts_with($entry, 'throttle:')),
                "Route [{$name}] has no throttle middleware.",
            );
        }
    }

    public function test_robots_txt_is_served_by_the_app_not_as_a_static_file(): void
    {
        // /robots.txt answers per domain (SeoFilesController): the app's own
        // domain keeps "Disallow: /admin" and "/livewire", a storefront domain
        // adds its sitemap (StorefrontSeoTest). A static public/robots.txt
        // must not come back -- the web server would serve it instead of the route.
        $this->assertFileDoesNotExist(public_path('robots.txt'));

        // Nor may nginx answer it: a "location = /robots.txt" block without a fallback
        // turns the missing file into a 404 that crawlers read as "no rules",
        // even though Laravel (via error_page 404 /index.php) produced the body.
        $this->assertStringNotContainsString('location = /robots.txt {', (string) file_get_contents(base_path('nginx.template.conf')));
    }

    public function test_an_unhandled_storefront_exception_in_production_shows_a_generic_page_not_a_stack_trace(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false]);

        Route::get('/_test/boom', function (): never {
            throw new \RuntimeException('Something exploded with a secret file path.');
        });

        $response = $this->get('/_test/boom');

        $response->assertStatus(500);
        $response->assertSeeText('Something went wrong');
        $response->assertDontSeeText('Something exploded with a secret file path.');
        $response->assertDontSeeText(__FILE__);
    }

    public function test_a_404_in_production_shows_the_generic_storefront_page(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false]);

        $response = $this->get('/this-route-does-not-exist-at-all');

        $response->assertStatus(404);
        $response->assertSeeText('Page not found');
    }

    /**
     * Everything the production exception handler must leave completely
     * alone (Filament's own error handling, Livewire's AJAX updates,
     * API/webhook JSON responses) — tested directly against the
     * classification logic rather than through a real thrown-exception HTTP
     * round trip, since Laravel's own default exception rendering behaves
     * differently under PHPUnit than in a real request and is not what this
     * is meant to verify.
     */
    public function test_storefront_error_page_does_not_apply_to_admin_livewire_api_or_webhook_requests(): void
    {
        $this->assertFalse(StorefrontErrorPages::appliesTo(Request::create('/admin')));
        $this->assertFalse(StorefrontErrorPages::appliesTo(Request::create('/admin/orders')));
        $this->assertFalse(StorefrontErrorPages::appliesTo(Request::create('/api/whatever')));
        $this->assertFalse(StorefrontErrorPages::appliesTo(Request::create('/webhooks/woocommerce/1')));

        $livewireRequest = Request::create('/livewire-abc123/update');
        $livewireRequest->headers->set('X-Livewire', '1');
        $this->assertFalse(StorefrontErrorPages::appliesTo($livewireRequest));

        $jsonRequest = Request::create('/some-storefront-route');
        $jsonRequest->headers->set('Accept', 'application/json');
        $this->assertFalse(StorefrontErrorPages::appliesTo($jsonRequest));
    }

    public function test_storefront_error_page_applies_to_a_plain_storefront_page_request(): void
    {
        $this->assertTrue(StorefrontErrorPages::appliesTo(Request::create('/some-company-storefront-page')));
        $this->assertTrue(StorefrontErrorPages::appliesTo(Request::create('/cart')));
    }

    public function test_storefront_error_page_status_falls_back_to_500_for_anything_not_in_the_covered_list(): void
    {
        $this->assertSame(404, StorefrontErrorPages::statusFor(new NotFoundHttpException()));
        $this->assertSame(500, StorefrontErrorPages::statusFor(new \RuntimeException('whatever')));
    }

    /**
     * Regression test for a real production bug: a webhook route that threw
     * a 403 (e.g. WooCommerceWebhookController's signature check) rendered
     * this app's OWN branded "Access denied" storefront page instead of
     * Laravel's normal handling, even though StorefrontErrorPages::appliesTo()
     * correctly said not to intervene. Root cause: Laravel's exception
     * handler auto-discovers views at the exact conventional path
     * resources/views/errors/{status}.blade.php (the "errors::" view
     * namespace, registered by RegisterErrorViewPaths) for ANY
     * HTML-rendering exception app-wide — completely independent of
     * bootstrap/app.php's withExceptions() closure returning null. Simply
     * excluding a request in appliesTo() was not enough; the fix moved
     * these views to storefront.errors.* (a path Laravel never
     * auto-discovers) so they're only ever reachable through the explicit
     * render() call for genuinely-included (storefront) requests.
     */
    public function test_a_403_on_an_excluded_route_never_renders_the_branded_storefront_page(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false]);

        // CSRF exemption for real webhook routes is tested elsewhere
        // (bootstrap/app.php's validateCsrfTokens list) — irrelevant here.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        Route::post('/webhooks/_test-boom', function (): never {
            abort(403);
        })->middleware('web');

        $response = $this->post('/webhooks/_test-boom');

        $response->assertStatus(403);
        $response->assertDontSeeText('Access denied');
        $response->assertDontSeeText("You don't have permission to view this page.");
    }
}
