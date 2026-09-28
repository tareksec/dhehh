<?php

namespace Tests\Feature;

use App\Filament\Resources\Vouchers\Pages\ListVouchers;
use App\Filament\Resources\Vouchers\Pages\TrashedVouchers;
use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\Account;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CompanyContext;
use App\Services\VoucherService;
use App\Services\VoucherTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner's rule (2026-09-28): a voucher is never deleted directly. It goes to
 * Trash first, then is deleted permanently from the Trash page, and only a
 * user with the delete permission may do either.
 */
class VoucherTrashTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private FundSource $fundSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['name' => 'Trash Co', 'slug' => 'trash-co', 'invoice_prefix' => 'TRC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->admin);

        $account = Account::query()->create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 100000]);
        $this->fundSource = FundSource::query()->create(['name' => 'Main', 'type' => 'owner_investment', 'account_id' => $account->id]);
    }

    public function test_a_voucher_goes_to_trash_first_and_is_deleted_permanently_only_from_there(): void
    {
        $voucher = $this->voucher();

        $this->assertFalse(VoucherResource::canDelete($voucher));

        try {
            app(VoucherTrashService::class)->deletePermanently($voucher, $this->admin);
            $this->fail('A voucher outside the trash must not be deleted permanently.');
        } catch (ValidationException) {
        }

        Livewire::test(ListVouchers::class)
            ->callTableAction('moveToTrash', $voucher)
            ->assertCanNotSeeTableRecords([$voucher]);

        $this->assertSoftDeleted($voucher);
        $this->assertSame($this->admin->id, Voucher::onlyTrashed()->find($voucher->id)->deleted_by);

        Livewire::test(TrashedVouchers::class)
            ->assertCanSeeTableRecords([$voucher])
            ->callTableAction('restore', $voucher);

        $this->assertNotSoftDeleted($voucher);

        app(VoucherTrashService::class)->trash($voucher, $this->admin);

        Livewire::test(TrashedVouchers::class)->callTableAction('deletePermanently', $voucher);

        $this->assertDatabaseMissing('vouchers', ['id' => $voucher->id]);
    }

    public function test_an_approved_voucher_cannot_go_to_trash(): void
    {
        $voucher = $this->voucher();
        $voucher->forceFill(['status' => Voucher::STATUS_APPROVED])->saveQuietly();

        Livewire::test(ListVouchers::class)->assertTableActionHidden('moveToTrash', $voucher);

        $this->expectException(ValidationException::class);
        app(VoucherTrashService::class)->trash($voucher, $this->admin);
    }

    public function test_only_users_with_the_delete_permission_can_use_the_trash(): void
    {
        $voucher = $this->voucher();
        $accountant = User::factory()->create(['role' => 'accountant', 'is_active' => true]);
        $accountant->companies()->attach($this->company, ['role' => 'accountant', 'is_default' => true]);

        $this->assertFalse($accountant->canDeleteVoucher());

        try {
            app(VoucherTrashService::class)->trash($voucher, $accountant);
            $this->fail('A user without voucher.delete must not trash vouchers.');
        } catch (ValidationException) {
        }

        $this->assertNotSoftDeleted($voucher);

        Auth::login($accountant);
        $this->actingAs($accountant)->withSession(['current_company_id' => $this->company->id])
            ->get(VoucherResource::getUrl('trash'))
            ->assertForbidden();
    }

    public function test_trashed_vouchers_do_not_block_new_voucher_numbers(): void
    {
        $voucher = $this->voucher();
        app(VoucherTrashService::class)->trash($voucher, $this->admin);

        $this->assertTrue(Voucher::withTrashed()->where('voucher_number', $voucher->voucher_number)->exists());
        $this->assertNotSame($voucher->voucher_number, Voucher::nextVoucherNumber(Voucher::TYPE_DEBIT, $this->company));
    }

    private function voucher(): Voucher
    {
        return app(VoucherService::class)->submit([
            'company_id' => $this->company->id,
            'type' => Voucher::TYPE_DEBIT,
            'transaction_type' => 'investor_payout',
            'amount' => 5000,
            'fund_source_id' => $this->fundSource->id,
            'account_id' => $this->fundSource->account_id,
            'payment_method' => 'bank',
            'confirmation_source' => 'manual',
            'purpose' => 'Test payout',
        ], $this->admin);
    }
}
