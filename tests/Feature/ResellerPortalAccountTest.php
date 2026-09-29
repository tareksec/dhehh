<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\Earnings;
use App\Filament\Reseller\Pages\MyStore;
use App\Filament\Reseller\Pages\PayoutAccounts;
use App\Filament\Reseller\Pages\Profile;
use App\Filament\Reseller\Pages\Withdrawals;
use App\Filament\Reseller\Widgets\CommissionTrend;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerCommission;
use App\Models\ResellerPayoutAccount;
use App\Models\ResellerPayoutRequest;
use App\Models\ResellerProduct;
use App\Models\StockMovement;
use App\Models\StorefrontCustomerActivity;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\ResellerCommissionService;
use App\Support\MoneyFormatter;
use App\Support\StorefrontThemeRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 5 of 13_RESELLER_PORTAL_PLAN.md: the portal's Earnings, Withdrawals
 * (+ saved payout accounts), My Store and Profile pages, and the store header
 * that shows only the reseller's own name (owner's answer, §2.1 Q3).
 */
class ResellerPortalAccountTest extends TestCase
{
    use RefreshDatabase;

    // ---- Earnings ---------------------------------------------------------

    public function test_earnings_lists_only_this_resellers_commissions(): void
    {
        [$company, $reseller] = $this->setUpCompany('earn-own.example.test');
        $other = $this->makeReseller($company, 'Other Reseller');

        $own = $this->makeCommission($company, $reseller, ResellerCommission::STATUS_PAYABLE, 100);
        $notMine = $this->makeCommission($company, $other, ResellerCommission::STATUS_PAYABLE, 999);

        $this->asPortal($company, $reseller);

        Livewire::test(Earnings::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$notMine]);
    }

    public function test_earnings_can_be_filtered_by_status(): void
    {
        [$company, $reseller] = $this->setUpCompany('earn-filter.example.test');

        $payable = $this->makeCommission($company, $reseller, ResellerCommission::STATUS_PAYABLE, 100);
        $pending = $this->makeCommission($company, $reseller, ResellerCommission::STATUS_PENDING, 50);

        $this->asPortal($company, $reseller);

        Livewire::test(Earnings::class)
            ->filterTable('status', ResellerCommission::STATUS_PENDING)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$payable]);
    }

    public function test_monthly_totals_and_the_chart_ignore_cancelled_commissions(): void
    {
        [$company, $reseller] = $this->setUpCompany('earn-monthly.example.test');

        $this->makeCommission($company, $reseller, ResellerCommission::STATUS_PAYABLE, 100);
        $this->makeCommission($company, $reseller, ResellerCommission::STATUS_PENDING, 50);
        $this->makeCommission($company, $reseller, ResellerCommission::STATUS_CANCELLED, 999);

        $this->asPortal($company, $reseller);

        $totals = Livewire::test(Earnings::class)->instance()->monthlyTotals();

        $this->assertCount(1, $totals);
        $this->assertSame(2, $totals[0]['orders']);
        $this->assertSame(MoneyFormatter::currency(50, 'BDT'), $totals[0]['pending']);
        $this->assertSame(MoneyFormatter::currency(100, 'BDT'), $totals[0]['payable']);

        $chart = Livewire::test(CommissionTrend::class)->instance();
        $data = (fn (): array => $this->getData())->call($chart);

        $this->assertSame(150.0, (float) array_sum($data['datasets'][0]['data']));
    }

    // ---- Withdrawals ------------------------------------------------------

    public function test_a_withdrawal_request_is_created_and_the_account_can_be_saved(): void
    {
        [$company, $reseller] = $this->setUpCompany('wd-create.example.test', minWithdrawal: 100);
        $this->makeCommission($company, $reseller, ResellerCommission::STATUS_PAYABLE, 500);

        $this->asPortal($company, $reseller);

        Livewire::test(Withdrawals::class)
            ->callAction('requestWithdrawal', data: [
                'amount' => 200,
                'method' => 'bkash',
                'account_details' => '01711112222',
                'save_account' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('reseller_payout_requests', [
            'customer_id' => $reseller->getKey(),
            'company_id' => $company->getKey(),
            'amount' => 200,
            'method' => 'bkash',
            'account_details' => '01711112222',
            'status' => ResellerPayoutRequest::STATUS_REQUESTED,
        ]);

        $this->assertDatabaseHas('reseller_payout_accounts', [
            'customer_id' => $reseller->getKey(),
            'company_id' => $company->getKey(),
            'method' => 'bkash',
            'account_details' => '01711112222',
            'is_default' => true,
        ]);

        $this->assertSame(300.0, app(ResellerCommissionService::class)->balances($reseller)['available']);
    }

    public function test_a_withdrawal_below_the_minimum_or_above_the_balance_is_refused(): void
    {
        [$company, $reseller] = $this->setUpCompany('wd-limits.example.test', minWithdrawal: 100);
        $this->makeCommission($company, $reseller, ResellerCommission::STATUS_PAYABLE, 500);

        $this->asPortal($company, $reseller);

        Livewire::test(Withdrawals::class)
            ->callAction('requestWithdrawal', data: ['amount' => 50, 'method' => 'bkash', 'account_details' => '017'])
            ->assertHasActionErrors(['amount']);

        Livewire::test(Withdrawals::class)
            ->callAction('requestWithdrawal', data: ['amount' => 600, 'method' => 'bkash', 'account_details' => '017'])
            ->assertHasActionErrors(['amount']);

        $this->assertDatabaseCount('reseller_payout_requests', 0);
    }

    public function test_the_service_refuses_an_amount_over_the_available_balance(): void
    {
        [$company, $reseller] = $this->setUpCompany('wd-service.example.test');
        $this->makeCommission($company, $reseller, ResellerCommission::STATUS_PAYABLE, 500);

        $service = app(ResellerCommissionService::class);

        $this->assertNull($service->requestPayout($reseller, 500.01, 'bkash', '017'));
        $this->assertNotNull($service->requestPayout($reseller, 500, 'bkash', '017'));
        // The whole balance is now held by the first request.
        $this->assertNull($service->requestPayout($reseller, 1, 'bkash', '017'));
    }

    public function test_the_withdraw_button_is_disabled_while_the_balance_is_below_the_minimum(): void
    {
        [$company, $reseller] = $this->setUpCompany('wd-disabled.example.test', minWithdrawal: 100);
        $this->makeCommission($company, $reseller, ResellerCommission::STATUS_PAYABLE, 60);

        $this->asPortal($company, $reseller);

        Livewire::test(Withdrawals::class)->assertActionDisabled('requestWithdrawal');
    }

    public function test_the_default_saved_account_prefills_the_withdrawal_form(): void
    {
        [$company, $reseller] = $this->setUpCompany('wd-prefill.example.test');
        $this->makeCommission($company, $reseller, ResellerCommission::STATUS_PAYABLE, 500);
        PayoutAccounts::store($reseller, 'nagad', '01899998888');

        $this->asPortal($company, $reseller);

        Livewire::test(Withdrawals::class)
            ->mountAction('requestWithdrawal')
            ->assertActionDataSet(['method' => 'nagad', 'account_details' => '01899998888']);
    }

    public function test_the_history_shows_only_own_requests_and_only_exposes_the_note_on_rejection(): void
    {
        [$company, $reseller] = $this->setUpCompany('wd-history.example.test');
        $other = $this->makeReseller($company, 'Other Reseller');

        $paid = $this->makePayout($reseller, ResellerPayoutRequest::STATUS_PAID, ['payment_reference' => 'TXN-123', 'admin_note' => 'internal only']);
        $approved = $this->makePayout($reseller, ResellerPayoutRequest::STATUS_APPROVED, ['admin_note' => 'internal only']);
        $rejected = $this->makePayout($reseller, ResellerPayoutRequest::STATUS_REJECTED, ['admin_note' => 'Wrong number']);
        $notMine = $this->makePayout($other, ResellerPayoutRequest::STATUS_PAID, ['payment_reference' => 'TXN-OTHER']);

        $this->asPortal($company, $reseller);

        Livewire::test(Withdrawals::class)
            ->assertCanSeeTableRecords([$paid, $approved, $rejected])
            ->assertCanNotSeeTableRecords([$notMine])
            ->assertTableColumnStateSet('reference', 'TXN-123', $paid)
            ->assertTableColumnStateSet('reference', null, $approved)
            ->assertTableColumnStateSet('reference', 'Wrong number', $rejected);
    }

    // ---- Saved payout accounts -------------------------------------------

    public function test_the_first_saved_account_becomes_the_default_and_the_default_can_move(): void
    {
        [$company, $reseller] = $this->setUpCompany('acct-default.example.test');
        $this->asPortal($company, $reseller);

        Livewire::test(PayoutAccounts::class)
            ->callAction('add', data: ['method' => 'bkash', 'account_details' => '01711111111', 'label' => 'Personal', 'is_default' => false])
            ->callAction('add', data: ['method' => 'rocket', 'account_details' => '01722222222', 'is_default' => false]);

        $first = ResellerPayoutAccount::query()->where('account_details', '01711111111')->firstOrFail();
        $second = ResellerPayoutAccount::query()->where('account_details', '01722222222')->firstOrFail();

        $this->assertTrue($first->is_default);
        $this->assertFalse($second->is_default);
        $this->assertSame($company->getKey(), $first->company_id);

        Livewire::test(PayoutAccounts::class)->callTableAction('makeDefault', $second);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_saving_the_same_account_twice_does_not_duplicate_it(): void
    {
        [$company, $reseller] = $this->setUpCompany('acct-dupe.example.test');
        $this->asPortal($company, $reseller);

        PayoutAccounts::store($reseller, 'bkash', '01711111111');
        PayoutAccounts::store($reseller, 'bkash', '01711111111', 'Renamed');

        $this->assertSame(1, $reseller->resellerPayoutAccounts()->count());
        $this->assertSame('Renamed', $reseller->resellerPayoutAccounts()->first()->label);
    }

    public function test_an_account_can_be_edited_and_deleted_and_a_sent_request_keeps_its_own_copy(): void
    {
        [$company, $reseller] = $this->setUpCompany('acct-edit.example.test');
        $account = PayoutAccounts::store($reseller, 'bkash', '01711111111');
        $request = $this->makePayout($reseller, ResellerPayoutRequest::STATUS_REQUESTED, ['account_details' => '01711111111']);

        $this->asPortal($company, $reseller);

        Livewire::test(PayoutAccounts::class)
            ->callTableAction('edit', $account, data: ['method' => 'nagad', 'account_details' => '01833333333', 'label' => 'Shop', 'is_default' => true])
            ->assertHasNoTableActionErrors();

        $account->refresh();
        $this->assertSame('nagad', $account->method);
        $this->assertSame('01833333333', $account->account_details);
        $this->assertSame('Shop', $account->label);

        Livewire::test(PayoutAccounts::class)->callTableAction('delete', $account);

        $this->assertDatabaseMissing('reseller_payout_accounts', ['id' => $account->getKey()]);
        $this->assertSame('01711111111', $request->fresh()->account_details);
    }

    public function test_another_resellers_saved_account_cannot_be_seen_or_deleted(): void
    {
        [$company, $reseller] = $this->setUpCompany('acct-scope.example.test');
        $other = $this->makeReseller($company, 'Other Reseller');
        $foreign = PayoutAccounts::store($other, 'bkash', '01799990000');

        $this->asPortal($company, $reseller);

        Livewire::test(PayoutAccounts::class)
            ->assertCanNotSeeTableRecords([$foreign]);

        try {
            Livewire::test(PayoutAccounts::class)->callTableAction('delete', $foreign);
        } catch (\Throwable) {
            // Refusing the action outright is just as good as a no-op.
        }

        $this->assertDatabaseHas('reseller_payout_accounts', ['id' => $foreign->getKey()]);
    }

    // ---- My Store ---------------------------------------------------------

    public function test_the_store_url_name_and_store_name_can_be_saved(): void
    {
        [$company, $reseller] = $this->setUpCompany('store-save.example.test');
        $this->asPortal($company, $reseller);

        Livewire::test(MyStore::class)
            ->fillForm(['reseller_slug' => 'karims-shop', 'reseller_store_name' => "  Karim's Shop  "])
            ->call('save')
            ->assertHasNoFormErrors();

        $reseller->refresh();
        $this->assertSame('karims-shop', $reseller->reseller_slug);
        $this->assertSame("Karim's Shop", $reseller->reseller_store_name);

        Livewire::test(MyStore::class)
            ->fillForm(['reseller_slug' => 'karims-shop', 'reseller_store_name' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($reseller->fresh()->reseller_store_name);
    }

    public function test_the_store_url_name_must_be_unique_within_the_company_only(): void
    {
        [$company, $reseller] = $this->setUpCompany('store-unique.example.test');
        $taker = $this->makeReseller($company, 'Taker');
        $taker->update(['reseller_slug' => 'taken-name']);

        [, $otherCompanyReseller] = $this->setUpCompany('store-unique-other.example.test');
        $otherCompanyReseller->update(['reseller_slug' => 'elsewhere-name']);

        $this->asPortal($company, $reseller);

        Livewire::test(MyStore::class)
            ->fillForm(['reseller_slug' => 'taken-name'])
            ->call('save')
            ->assertHasFormErrors(['reseller_slug' => 'unique']);

        // Another company's slug doesn't block this one.
        Livewire::test(MyStore::class)
            ->fillForm(['reseller_slug' => 'elsewhere-name'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_store_url_name_rejects_invalid_characters(): void
    {
        [$company, $reseller] = $this->setUpCompany('store-invalid.example.test');
        $this->asPortal($company, $reseller);

        Livewire::test(MyStore::class)
            ->fillForm(['reseller_slug' => 'bad slug!'])
            ->call('save')
            ->assertHasFormErrors(['reseller_slug']);
    }

    // ---- Profile ----------------------------------------------------------

    public function test_the_profile_updates_name_and_email_but_never_the_phone(): void
    {
        [$company, $reseller] = $this->setUpCompany('profile-basic.example.test');
        $phone = $reseller->phone;
        $this->asPortal($company, $reseller);

        Livewire::test(Profile::class)
            ->fillForm(['name' => 'New Name', 'email' => 'new.name@example.com', 'phone' => '01000000000'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reseller->refresh();
        $this->assertSame('New Name', $reseller->name);
        $this->assertSame('new.name@example.com', $reseller->email);
        $this->assertSame($phone, $reseller->phone);
        $this->assertTrue(
            StorefrontCustomerActivity::query()
                ->where('customer_id', $reseller->getKey())
                ->where('type', StorefrontCustomerActivity::TYPE_PROFILE_UPDATED)
                ->exists()
        );
    }

    public function test_an_email_used_by_another_login_account_or_a_malformed_one_is_refused(): void
    {
        [$company, $reseller] = $this->setUpCompany('profile-email.example.test');
        $other = $this->makeReseller($company, 'Other Reseller');
        $other->update(['email' => 'taken@example.com']);

        $this->asPortal($company, $reseller);

        Livewire::test(Profile::class)
            ->fillForm(['email' => 'taken@example.com'])
            ->call('save')
            ->assertHasFormErrors(['email']);

        Livewire::test(Profile::class)
            ->fillForm(['email' => 'not-an-email'])
            ->call('save')
            ->assertHasFormErrors(['email']);
    }

    public function test_changing_the_password_needs_the_current_one(): void
    {
        [$company, $reseller] = $this->setUpCompany('profile-pass.example.test');
        $this->asPortal($company, $reseller);

        Livewire::test(Profile::class)
            ->fillForm(['current_password' => 'wrong-password', 'password' => 'N3w-Secure-Pass!', 'passwordConfirmation' => 'N3w-Secure-Pass!'])
            ->call('save')
            ->assertHasErrors(['data.current_password']);

        $this->assertTrue(Hash::check('secret-password', $reseller->fresh()->password));

        Livewire::test(Profile::class)
            ->fillForm(['current_password' => 'secret-password', 'password' => 'N3w-Secure-Pass!', 'passwordConfirmation' => 'N3w-Secure-Pass!'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertHasNoErrors();

        $reseller->refresh();
        $this->assertTrue(Hash::check('N3w-Secure-Pass!', $reseller->password));
        $this->assertSame($reseller->getAuthPassword(), session('password_hash_customer'));
        $this->assertTrue(
            StorefrontCustomerActivity::query()
                ->where('customer_id', $reseller->getKey())
                ->where('type', StorefrontCustomerActivity::TYPE_PASSWORD_CHANGED)
                ->exists()
        );
    }

    public function test_a_new_password_must_match_its_confirmation(): void
    {
        [$company, $reseller] = $this->setUpCompany('profile-confirm.example.test');
        $this->asPortal($company, $reseller);

        Livewire::test(Profile::class)
            ->fillForm(['current_password' => 'secret-password', 'password' => 'N3w-Secure-Pass!', 'passwordConfirmation' => 'different'])
            ->call('save')
            ->assertHasFormErrors(['password']);

        $this->assertTrue(Hash::check('secret-password', $reseller->fresh()->password));
    }

    public function test_repeated_wrong_current_passwords_lock_the_password_change(): void
    {
        [$company, $reseller] = $this->setUpCompany('profile-throttle.example.test');
        RateLimiter::clear('reseller-portal-password:'.$reseller->getKey());
        $this->asPortal($company, $reseller);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Profile::class)
                ->fillForm(['current_password' => 'wrong-'.$i, 'password' => 'N3w-Secure-Pass!', 'passwordConfirmation' => 'N3w-Secure-Pass!'])
                ->call('save')
                ->assertHasErrors(['data.current_password']);
        }

        // Even the right password is refused while locked.
        Livewire::test(Profile::class)
            ->fillForm(['current_password' => 'secret-password', 'password' => 'N3w-Secure-Pass!', 'passwordConfirmation' => 'N3w-Secure-Pass!'])
            ->call('save')
            ->assertHasErrors(['data.current_password']);

        $this->assertTrue(Hash::check('secret-password', $reseller->fresh()->password));
    }

    public function test_every_account_page_renders_over_http_with_its_navigation_entry(): void
    {
        [$company, $reseller] = $this->setUpCompany('render-all.example.test', minWithdrawal: 100);
        $this->makeCommission($company, $reseller, ResellerCommission::STATUS_PAYABLE, 500);
        PayoutAccounts::store($reseller, 'bkash', '01711112222');

        $this->actingAs($reseller, 'customer');

        $pages = [
            'earnings' => 'Monthly totals',
            'withdrawals' => 'Available to withdraw',
            'payout-accounts' => '01711112222',
            'my-store' => 'Store URL name',
            'profile' => 'Change password',
        ];

        foreach ($pages as $path => $expected) {
            $response = $this->get('http://reseller.render-all.example.test/portal/'.$path)->assertOk();

            $response->assertSee($expected);

            foreach (['Earnings', 'Withdrawals', 'Saved payout accounts', 'My Store', 'Profile'] as $navLabel) {
                $response->assertSee($navLabel);
            }
        }
    }

    // ---- Audit log --------------------------------------------------------

    public function test_a_reseller_portal_change_is_audited_without_pretending_a_staff_user_made_it(): void
    {
        [$company, $reseller] = $this->setUpCompany('audit-actor.example.test');
        $this->asPortal($company, $reseller);

        // The reseller's own id must not be stored as a staff user id.
        $this->assertSame($reseller->getKey(), Auth::id());

        Livewire::test(Profile::class)
            ->fillForm(['name' => 'Audited Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $log = AuditLog::query()
            ->where('auditable_type', Customer::class)
            ->where('auditable_id', $reseller->getKey())
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->user_id);
    }

    // ---- Access -----------------------------------------------------------

    public function test_a_pending_reseller_cannot_open_the_account_pages(): void
    {
        [$company, $pending] = $this->setUpCompany('access-pending.example.test', status: 'pending');
        $this->asPortal($company, $pending);

        foreach ([Earnings::class, Withdrawals::class, PayoutAccounts::class, MyStore::class, Profile::class] as $page) {
            $this->assertFalse($page::canAccess(), $page.' must be closed to a pending reseller.');
        }
    }

    // ---- Store header branding (§2.1 Q3) ---------------------------------

    public function test_a_reseller_store_header_shows_only_the_resellers_own_name(): void
    {
        foreach (array_keys(StorefrontThemeRegistry::themes()) as $theme) {
            $domain = Str::slug($theme).'-brand.example.test';
            [$company, $reseller] = $this->setUpCompany($domain, theme: $theme, companyName: 'Acme Trading Company');
            $this->pickProduct($company, $reseller);

            $reseller->update(['reseller_store_name' => null, 'business_name' => 'Rahim Fashion']);

            $header = $this->headerOf("http://{$domain}/store/{$reseller->reseller_slug}");
            $this->assertStringContainsString('Rahim Fashion', $header, "[{$theme}] header falls back to the business name");
            $this->assertStringNotContainsString('Acme Trading Company', $header, "[{$theme}] header must not carry the company name");

            $reseller->update(['reseller_store_name' => 'Rahim Boutique']);

            $header = $this->headerOf("http://{$domain}/store/{$reseller->reseller_slug}/products");
            $this->assertStringContainsString('Rahim Boutique', $header, "[{$theme}] header uses the chosen store name");
            $this->assertStringNotContainsString('Rahim Fashion', $header);
            $this->assertStringNotContainsString('Acme Trading Company', $header);

            // The company's own store is unchanged.
            $mainHeader = $this->headerOf("http://{$domain}/");
            $this->assertStringContainsString('Acme Trading Company', $mainHeader, "[{$theme}] main store still shows the company");
            $this->assertStringNotContainsString('Rahim', $mainHeader);
        }
    }

    // ---- Helpers ----------------------------------------------------------

    protected function headerOf(string $url): string
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<header[^>]*storefront-header.*?<\/header>/s', $html, $match), "No storefront header found on [{$url}].");

        return $match[0];
    }

    /** @return array{0: Company, 1: Customer} */
    protected function setUpCompany(
        string $domain,
        string $status = 'approved',
        float $minWithdrawal = 0,
        string $theme = StorefrontThemeRegistry::BUILT_IN,
        ?string $companyName = null,
    ): array {
        $company = Company::query()->create([
            'name' => $companyName ?? 'Portal '.$domain,
            'slug' => Str::slug($domain),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'reseller_module_enabled' => true,
        ])->fresh();

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'storefront_theme' => $theme,
            'reseller_cost_per_unit' => 0,
            'reseller_min_withdrawal' => $minWithdrawal,
        ]);

        $company = $company->fresh();

        return [$company, $this->makeReseller($company, 'Test Reseller', $status)];
    }

    protected function makeReseller(Company $company, string $name, string $status = 'approved'): Customer
    {
        app(CompanyContext::class)->set($company);

        $reseller = Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => $name,
            'phone' => '019'.random_int(10000000, 99999999),
            'reseller_status' => $status,
            'reseller_slug' => Str::slug($name).'-'.Str::random(6),
            'business_name' => $name.' Shop',
            'is_active' => true,
        ]);

        // password isn't mass-assignable on Customer.
        $reseller->forceFill(['password' => Hash::make('secret-password')])->save();

        return $reseller;
    }

    protected function asPortal(Company $company, Customer $reseller): void
    {
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->actingAs($reseller, 'customer');
    }

    protected function makeCommission(Company $company, Customer $reseller, string $status, float $amount): ResellerCommission
    {
        app(CompanyContext::class)->set($company);

        $buyer = Customer::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Buyer',
            'phone' => '018'.random_int(10000000, 99999999),
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'company_id' => $company->getKey(),
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'customer_id' => $buyer->getKey(),
            'customer_name' => 'Buyer',
            'order_date' => now(),
            'subtotal' => $amount,
            'discount' => 0,
            'vat' => 0,
            'shipping_fee' => 0,
            'total_amount' => $amount,
            'paid_amount' => 0,
            'due_amount' => $amount,
            'status' => Order::STATUS_CONFIRMED,
            'source' => Order::SOURCE_STOREFRONT,
        ]);

        return ResellerCommission::query()->create([
            'company_id' => $company->getKey(),
            'order_id' => $order->getKey(),
            'customer_id' => $reseller->getKey(),
            'sales_amount' => $amount + 100,
            'base_amount' => 100,
            'amount' => $amount,
            'status' => $status,
            'payable_at' => $status === ResellerCommission::STATUS_PAYABLE ? now() : null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function makePayout(Customer $reseller, string $status, array $attributes = []): ResellerPayoutRequest
    {
        app(CompanyContext::class)->set($reseller->company);

        return ResellerPayoutRequest::query()->create(array_merge([
            'company_id' => $reseller->company_id,
            'customer_id' => $reseller->getKey(),
            'amount' => 100,
            'method' => 'bkash',
            'account_details' => '01700000000',
            'status' => $status,
        ], $attributes));
    }

    protected function pickProduct(Company $company, Customer $reseller): Product
    {
        app(CompanyContext::class)->set($company);

        $product = Product::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Brand Test Product',
            'sku' => 'BT-'.Str::random(6),
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 0,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'tier_prices' => [['min_qty' => 1, 'price' => 300]],
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ])->fresh();

        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => 50]);

        ResellerProduct::query()->create([
            'customer_id' => $reseller->getKey(),
            'product_id' => $product->getKey(),
            'is_active' => true,
        ]);

        return $product;
    }
}
