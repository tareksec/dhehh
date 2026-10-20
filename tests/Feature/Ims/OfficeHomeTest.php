<?php

namespace Tests\Feature\Ims;

use App\Http\Middleware\SetImsOfficeLocale;
use App\Ims\Office\OfficeFigures;
use App\Models\Investment;
use App\Models\InvestorGroupMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The redesigned office home (IMS-V2/DESIGN.md §6.1, the owner's reference
 * dashboard, 2026-10-19): the round top bar with the menu as pills, the icon
 * rail, the "Welcome back" heading and the cards, and the figures behind the
 * new cards (profit per month, the latest money in).
 */
class OfficeHomeTest extends TestCase
{
    use ImsTestHelpers;
    use RefreshDatabase;

    public function test_the_home_shows_the_reference_layout(): void
    {
        $this->imsCompany();
        $this->actingAs($this->staff());

        $this->get($this->imsUrl('office'))
            ->assertOk()
            ->assertSee('fi-topbar-nav-groups', false)
            ->assertSee('ims-rail', false)
            ->assertSeeInOrder([__('Welcome back,'), __('Add investor')])
            ->assertSee(__('Capital at work now'))
            ->assertSee(__('Profit paid to investors'))
            ->assertSee(__('What to do today'))
            ->assertSee(__('Latest money in'))
            ->assertSee(__('Payouts still to send'))
            ->assertSee(__('Groups now'));
    }

    public function test_the_home_speaks_bangla(): void
    {
        $this->imsCompany();
        $this->actingAs($this->staff());

        $this->withSession([SetImsOfficeLocale::SESSION_KEY => 'bn'])->get($this->imsUrl('office'))
            ->assertOk()
            ->assertSee('স্বাগতম,')
            ->assertSee('ইনভেস্টরদের দেওয়া লাভ');
    }

    public function test_profit_per_month_counts_paid_profit_by_the_month_it_was_paid(): void
    {
        $this->imsCompany();
        $now = CarbonImmutable::parse('2026-10-15');
        $series = app(OfficeFigures::class)->profitPaidByMonth(12, $now);

        $this->assertCount(12, $series);
        $this->assertSame('2025-11', array_key_first($series));
        $this->assertSame('2026-10', array_key_last($series));
        $this->assertSame([0.0], array_values(array_unique($series)));
    }

    public function test_the_latest_money_in_lists_group_money_and_loose_investments_newest_first(): void
    {
        $this->imsCompany();
        $staff = $this->staff();
        $early = $this->newInvestor(['name' => 'Early Investor']);
        $late = $this->newInvestor(['name' => 'Late Investor']);
        $group = $this->openGroup(['group_code' => 'G9']);

        InvestorGroupMember::query()->create(['company_id' => $this->company->getKey(), 'group_id' => $group->getKey(), 'investor_id' => $late->getKey(), 'amount' => 50000, 'invested_at' => now()->toDateString(), 'payment_method' => 'bank', 'status' => InvestorGroupMember::STATUS_PENDING]);
        $project = $this->closedProject(['status' => 'open']);
        Investment::query()->create(['company_id' => $this->company->getKey(), 'project_id' => $project->getKey(), 'investor_id' => $early->getKey(), 'amount' => 120000, 'payment_method' => 'bank', 'invested_at' => now()->subDays(3)->toDateString(), 'received_by' => $staff->getKey()]);

        $rows = app(OfficeFigures::class)->latestMoneyIn(5);

        $this->assertSame(['Late Investor', 'Early Investor'], array_column($rows, 'name'));
        $this->assertTrue($rows[0]['waiting']);
        $this->assertStringContainsString('G9', $rows[0]['where']);
        $this->assertSame(120000.0, $rows[1]['amount']);
    }
}
