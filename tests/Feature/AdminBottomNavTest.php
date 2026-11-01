<?php

namespace Tests\Feature;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Company;
use App\Models\User;
use App\Models\UserRole;
use App\Support\AdminBottomNav;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin panel's phone bottom bar (owner request 2026-11-01): Dashboard and
 * the company switch on the left, "+" in the middle (Create a new order / Add a
 * new product / purchase / expense, each opening the resource's own Create page
 * in a pop-up), Orders and Products on the right.
 */
class AdminBottomNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_gets_the_bar_in_the_requested_order_with_all_four_create_entries(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $html = $this->actingAs($user)->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('data-admin-bottom-nav', $html);

        // Left: Dashboard, company switch. Middle: "+". Right: Orders, Products.
        $positions = array_map(
            fn (string $key): int|false => strpos($html, 'data-bottom-nav="'.$key.'"'),
            ['dashboard', 'company', 'create', 'orders', 'products'],
        );
        $this->assertNotContains(false, $positions, 'Every slot of the bar should be drawn.');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Slots should run Dashboard, company, +, Orders, Products from left to right.');

        // The list links go to the real pages.
        foreach ([
            'dashboard' => Dashboard::getUrl(),
            'orders' => OrderResource::getUrl('index'),
            'products' => ProductResource::getUrl('index'),
        ] as $key => $url) {
            $this->assertMatchesRegularExpression(
                '/<a\s+href="'.preg_quote($url, '/').'"[^>]*data-bottom-nav="'.$key.'"/s',
                $html,
                "The {$key} slot should link to {$url}",
            );
        }

        // Only phone and tablet widths: Filament shows the sidebar from lg up.
        $this->assertMatchesRegularExpression('/class="zz-admin-bottom-nav lg:hidden"/', $html);

        // The four entries of "+", each pointing to its own Create page (path only, with the pop-up marker).
        $entries = $this->entriesOf($html);
        $this->assertSame(['order', 'product', 'purchase', 'expense'], array_keys($entries));

        foreach ([
            'order' => [OrderResource::class, 'Create a new order'],
            'product' => [ProductResource::class, 'Add a new product'],
            'purchase' => [PurchaseResource::class, 'Add a new purchase'],
            'expense' => [ExpenseResource::class, 'Add a new expense'],
        ] as $key => [$resource, $label]) {
            $this->assertSame($label, $entries[$key]['label']);
            $this->assertSame(
                parse_url($resource::getUrl('create'), PHP_URL_PATH).'?embed=1',
                $entries[$key]['url'],
                "The {$key} entry should open its Create page, relative to the current host.",
            );
        }

        // The behaviour script is on the page.
        $this->assertStringContainsString('window.zzQuickCreate', $html);
    }

    public function test_the_page_being_viewed_is_the_highlighted_slot(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $html = $this->actingAs($user)->get(OrderResource::getUrl('index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*data-bottom-nav="orders"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/aria-current="page"[^>]*data-bottom-nav="dashboard"/s', $html);
    }

    public function test_each_role_is_offered_only_what_it_may_create(): void
    {
        // Sales staff: sales.create, inventory.view — an order, but no product, purchase or expense.
        $sales = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $html = $this->actingAs($sales)->get('/admin')->assertOk()->getContent();
        $this->assertSame(['order'], $this->offered($html));
        $this->assertStringContainsString('data-bottom-nav="orders"', $html);
        $this->assertStringContainsString('data-bottom-nav="products"', $html);

        // Inventory staff: inventory.create, purchasing.view — a product only (purchasing is view-only).
        $inventory = User::factory()->create(['role' => 'inventory_staff', 'is_active' => true]);
        $this->assertSame(['product'], $this->offered($this->actingAs($inventory)->get('/admin')->assertOk()->getContent()));

        // Accountant: accounts.create — an expense only; sales and purchasing are view-only.
        $accountant = User::factory()->create(['role' => 'accountant', 'is_active' => true]);
        $this->assertSame(['expense'], $this->offered($this->actingAs($accountant)->get('/admin')->assertOk()->getContent()));
    }

    public function test_the_plus_button_is_left_out_when_nothing_can_be_created(): void
    {
        // A role that can only look at things: no create permission at all.
        UserRole::query()->create([
            'name' => 'Look only', 'slug' => 'look_only',
            'permissions' => ['dashboard.view', 'sales.view', 'inventory.view'], 'is_active' => true,
        ]);
        $viewer = User::factory()->create(['role' => 'look_only', 'is_active' => true]);

        $html = $this->actingAs($viewer)->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('data-admin-bottom-nav', $html);
        $this->assertSame([], $this->offered($html));
        $this->assertStringNotContainsString('data-bottom-nav="create"', $html);
    }

    public function test_the_create_page_inside_the_popup_has_no_bar_and_no_updater(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $html = $this->actingAs($user)
            ->get(OrderResource::getUrl('create').'?'.AdminBottomNav::EMBED_QUERY.'=1')
            ->assertOk()
            ->getContent();

        // The form is there, the bar (which would open a pop-up inside the pop-up) is not.
        $this->assertStringContainsString('wire:submit', $html);
        $this->assertStringNotContainsString('data-admin-bottom-nav', $html);
        $this->assertStringNotContainsString('zz-app-updater-config', $html);

        // The same page opened normally still has the bar.
        $normal = $this->actingAs($user)->get(OrderResource::getUrl('create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-admin-bottom-nav', $normal);
    }

    public function test_the_login_page_has_no_bar(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('data-admin-bottom-nav', false);
    }

    public function test_the_company_slot_lists_every_company_and_marks_the_open_one(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $first = $user->defaultCompany();
        $second = Company::query()->create([
            'name' => 'Second Shop Ltd', 'slug' => 'second-shop', 'invoice_prefix' => 'SEC',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        $user->companies()->sync([
            $first->getKey() => ['role' => 'super_admin', 'is_default' => true],
            $second->getKey() => ['role' => 'super_admin', 'is_default' => false],
        ]);

        $html = $this->actingAs($user)
            ->withSession(['current_company_id' => $second->getKey()])
            ->get('/admin')
            ->assertOk()
            ->getContent();

        $bar = $this->barOf($html);

        // The slot shows the open company, and the sheet offers All Companies plus both companies as POST forms.
        $this->assertMatchesRegularExpression('/data-bottom-nav="company".*?Second Shop Ltd/s', $bar);
        $this->assertStringContainsString('name="company_id" value="all"', $bar);
        $this->assertStringContainsString('name="company_id" value="'.$first->getKey().'"', $bar);
        $this->assertStringContainsString('name="company_id" value="'.$second->getKey().'"', $bar);
        $this->assertStringContainsString(route('admin.company.switch'), $bar);

        // The header drop-down is still in the markup (hidden on phones with the bar by CSS).
        $this->assertStringContainsString('zz-company-switcher', $html);
    }

    public function test_a_person_with_one_company_sees_it_but_cannot_open_a_switch_sheet(): void
    {
        $company = Company::query()->create([
            'name' => 'Only Shop', 'slug' => 'only-shop', 'invoice_prefix' => 'ONL',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $user->companies()->sync([$company->getKey() => ['role' => 'manager', 'is_default' => true]]);

        $bar = $this->barOf($this->actingAs($user)->get('/admin')->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*data-bottom-nav="company".*?Only Shop/s', $bar);
        $this->assertStringNotContainsString('zz-bottom-nav-company-title', $bar);
    }

    /**
     * The "+" sheet's entries as the page hands them to the pop-up (key => label and url).
     *
     * @return array<string, array{key: string, label: string, url: string}>
     */
    private function entriesOf(string $html): array
    {
        preg_match_all('/x-on:click="choose\(JSON\.parse\(\'(.*?)\'\)\)"[^>]*data-quick-create="([a-z]+)"/s', $html, $matches, PREG_SET_ORDER);

        $entries = [];
        foreach ($matches as [, $raw, $key]) {
            // The attribute holds a JS string literal of JSON: \u0022 for quotes, \\\/ for slashes.
            $entries[$key] = json_decode(stripcslashes(str_replace('\u0022', '"', $raw)), true);
        }

        return $entries;
    }

    /**
     * @return list<string> the keys of the Create entries in the "+" sheet
     */
    private function offered(string $html): array
    {
        preg_match_all('/data-quick-create="([a-z]+)"/', $html, $matches);

        return $matches[1];
    }

    /** The bar's own markup, so a company name in the header drop-down cannot satisfy an assertion. */
    private function barOf(string $html): string
    {
        $start = strpos($html, 'data-admin-bottom-nav');
        $this->assertNotFalse($start, 'The bottom bar should be on the page.');

        return substr($html, $start);
    }
}
