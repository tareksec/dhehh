<?php

namespace App\Filament\Ims\Office\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Resources\InvestorGroups\InvestorGroupResource;
use App\Models\InvestorGroup;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/** The groups that are open or running, as cards on the office home. */
class RunningGroups extends Widget
{
    use GuardsWidgetRendering;

    protected static bool $isLazy = false;

    protected string $view = 'filament.ims.office.widgets.running-groups';

    /** @return Collection<int, array{group: InvestorGroup, label: string, color: string, money: float, people: int, url: string|null}> */
    public function groups(): Collection
    {
        $canOpen = InvestorGroupResource::canAccess();

        return InvestorGroup::query()->where('status', '!=', InvestorGroup::STATUS_CLOSED)
            ->withCount(['members as people_count' => fn ($query) => $query->whereIn('status', ['pending', 'active'])])
            ->latest('id')->limit(6)->get()
            ->map(fn (InvestorGroup $group): array => [
                'group' => $group,
                'label' => InvestorGroupResource::statusLabel($group->status),
                'color' => InvestorGroupResource::statusColor($group->status),
                'money' => (float) $group->totalActiveDeposits(),
                'people' => (int) $group->people_count,
                'url' => $canOpen ? InvestorGroupResource::getUrl('view', ['record' => $group], panel: 'office') : null,
            ]);
    }

    public function currency(): string
    {
        return app(\App\Services\CompanyContext::class)->company()?->currency ?: 'BDT';
    }
}
