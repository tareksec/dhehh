<?php

namespace App\Filament\Ims\Portal\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Filament\Investor\Pages\Requests;
use App\Models\InvestmentProject;
use App\Models\InvestorGroup;
use App\Services\InvestorPortfolio;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The investor's projects and the ones raising money now
 * (IMS-V2/PRD.md FR-35 level 1: a plan raising money is seen by everyone),
 * from every company the person invests in, with the company's name
 * (18_IMS_ALL_COMPANIES_PLAN.md §4). A card of another company opens that
 * company's page (?business=, OpenLinkedInvestorBusiness).
 */
class Projects extends Page
{
    use InvestorPortalPage;

    protected static ?string $portalRole = 'investor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.ims.portal.projects';

    public static function getNavigationGroup(): ?string
    {
        return __('Overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('Projects');
    }

    public function getTitle(): string
    {
        return __('Projects');
    }

    /**
     * Every project the person put money in, from every company they invest
     * in (18_IMS_ALL_COMPANIES_PLAN.md §4), each with its company's name.
     *
     * @return list<array{company: \App\Models\Company, company_name: string, project: InvestmentProject, currency: string, url: string}>
     */
    public function mine(): array
    {
        return app(InvestorPortfolio::class)->projects($this->account());
    }

    /** @return array{groups: list<array{company: \App\Models\Company, company_name: string, group: InvestorGroup, url: string|null}>, projects: list<array{company: \App\Models\Company, company_name: string, project: InvestmentProject, currency: string, url: string}>} */
    public function raising(): array
    {
        return app(InvestorPortfolio::class)->raising($this->account(), Requests::canAccess() ? Requests::getUrl() : null);
    }

    /** Whether to name the company on each card: only when the person invests in more than one. */
    public function showsCompanies(): bool
    {
        return app(InvestorPortfolio::class)->spansCompanies($this->account());
    }
}
