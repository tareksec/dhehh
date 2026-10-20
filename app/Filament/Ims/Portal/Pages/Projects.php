<?php

namespace App\Filament\Ims\Portal\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestorGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * The investor's projects and the ones raising money now
 * (IMS-V2/PRD.md FR-35 level 1: a plan raising money is seen by everyone).
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

    /** @return Collection<int, InvestmentProject> */
    public function mine(): Collection
    {
        $ids = Investment::query()->where('investor_id', $this->account()->investor_id)->pluck('project_id')->unique();

        return InvestmentProject::query()->whereIn('id', $ids)->with('group')->orderByDesc('start_date')->orderByDesc('id')->get();
    }

    /** @return Collection<int, InvestorGroup> */
    public function openGroups(): Collection
    {
        return InvestorGroup::query()->where('status', InvestorGroup::STATUS_OPEN)->orderByDesc('id')->get();
    }

    /** @return Collection<int, InvestmentProject> */
    public function openProjects(): Collection
    {
        return InvestmentProject::query()->ungrouped()->where('status', 'open')->orderByDesc('id')->get();
    }
}
