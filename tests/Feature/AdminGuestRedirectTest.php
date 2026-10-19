<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Plain `auth` routes under /admin (the legacy cluster redirects, order print,
 * report exports) have no Filament middleware of their own. Laravel's default
 * guest redirect is route('login'), which this app does not define, so a
 * logged-out visit to e.g. /admin/users threw RouteNotFoundException and was
 * reported to Deploy Error Logs as module:page:admin.clusters.legacy.
 */
class AdminGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function guestPaths(): array
    {
        return [
            'legacy cluster redirect' => ['/admin/users'],
            'legacy cluster redirect with a sub-path' => ['/admin/orders/12/edit'],
            'order print' => ['/admin/orders/1/print'],
            'report export' => ['/admin/reports/export/sales'],
        ];
    }

    #[DataProvider('guestPaths')]
    public function test_a_guest_on_an_auth_protected_admin_route_is_sent_to_the_admin_login(string $path): void
    {
        $this->get($path)->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_the_guest_redirect_remembers_the_page_so_login_can_return_to_it(): void
    {
        $this->get('/admin/users')->assertSessionHas('url.intended', url('/admin/users'));
    }
}
