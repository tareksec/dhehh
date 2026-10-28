<?php

namespace Tests\Feature;

use App\Filament\Resources\Investors\Pages\ListInvestors;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\ProjectCostItem;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorDeletionService;
use App\Services\Investment\InvestorGroupBackfillService;
use App\Services\Investment\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner 2026-10-28: select investors in the list and delete them, to import
 * them again. An investor whose money was paid out or booked is kept.
 */
class InvestorBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 10:00:00');

        $this->company = Company::query()->create([
            'name' => 'Delete Co',
            'slug' => 'delete-co',
            'invoice_prefix' => 'DEL',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->admin);
    }

    public function test_the_bulk_action_deletes_investors_with_their_investments_and_group_memberships(): void
    {
        $project = $this->project();
        $a = $this->investor('A', '01710000301');
        $b = $this->investor('B', '01710000302');
        $this->investment($project, $a, 100000);
        $this->investment($project, $b, 200000);
        app(InvestorGroupBackfillService::class)->apply();

        $this->assertSame(2, InvestorGroupMember::query()->count());

        Livewire::test(ListInvestors::class)
            ->callTableBulkAction('deleteInvestors', Investor::query()->get(), ['confirm' => 'DELETE'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(0, Investor::query()->count());
        $this->assertSame(0, Investment::query()->count());
        $this->assertSame(0, InvestorGroupMember::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'investor_deleted')->count());
        $this->assertTrue(InvestmentProject::query()->whereKey($project->id)->exists(), 'the project and its group stay');
        $this->assertSame(1, InvestorGroup::query()->count());
    }

    public function test_the_confirmation_word_is_required(): void
    {
        $investor = $this->investor('Keep', '01710000311');

        Livewire::test(ListInvestors::class)
            ->callTableBulkAction('deleteInvestors', collect([$investor]), ['confirm' => 'delete'])
            ->assertHasTableBulkActionErrors(['confirm']);

        $this->assertTrue(Investor::query()->whereKey($investor->id)->exists());
    }

    public function test_an_investor_with_a_payout_is_kept_and_named(): void
    {
        $project = $this->project(['status' => 'closed']);
        $paid = $this->investor('Paid', '01710000321');
        $this->investment($project, $paid, 200000);
        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 150000]);
        app(SettlementService::class)->calculateAndSettle($project, 250000, $this->admin->id, acknowledgeUnprovenCosts: true);

        $free = $this->investor('Free', '01710000322');

        $service = app(InvestorDeletionService::class);
        $this->assertNotNull($service->blocker($paid));
        $this->assertNull($service->blocker($free));

        Livewire::test(ListInvestors::class)
            ->callTableBulkAction('deleteInvestors', Investor::query()->get(), ['confirm' => 'DELETE']);

        $this->assertTrue(Investor::query()->whereKey($paid->id)->exists(), 'paid investor kept');
        $this->assertSame(1, Investment::query()->where('investor_id', $paid->id)->count());
        $this->assertFalse(Investor::query()->whereKey($free->id)->exists());
    }

    private function investor(string $name, string $phone): Investor
    {
        return Investor::query()->create(['company_id' => $this->company->id, 'name' => $name, 'phone' => $phone]);
    }

    private function project(array $overrides = []): InvestmentProject
    {
        return InvestmentProject::query()->create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Old Project',
            'duration_type' => '2_month',
            'start_date' => '2026-02-01',
            'investor_share_percent' => 40,
            'channel_partner_share_percent' => 10,
            'company_share_percent' => 50,
            'status' => 'running',
        ], $overrides));
    }

    private function investment(InvestmentProject $project, Investor $investor, float $amount): Investment
    {
        return Investment::query()->create([
            'company_id' => $project->company_id, 'project_id' => $project->id, 'investor_id' => $investor->id,
            'amount' => $amount, 'payment_method' => 'bank', 'invested_at' => '2026-02-02', 'received_by' => $this->admin->id,
        ]);
    }
}
