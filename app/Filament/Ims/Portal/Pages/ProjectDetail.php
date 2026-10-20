<?php

namespace App\Filament\Ims\Portal\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Ims\Transparency\InvestorProjectSummary;
use App\Models\InvestmentProject;
use Filament\Pages\Page;

/**
 * One project as the investor sees it (IMS-V2/PRD.md FR-30–36, DESIGN §8.1):
 * news, the timeline, shipments, the totals (marked "estimate" until the
 * project is settled) and the published reports. Everything comes from
 * InvestorProjectSummary, which never lets out a customer, supplier, price
 * or another investor.
 */
class ProjectDetail extends Page
{
    use InvestorPortalPage;

    protected static ?string $portalRole = 'investor';

    protected static ?string $slug = 'projects/{project}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.ims.portal.project-detail';

    public int $projectId;

    /** @var array<string, mixed> */
    public array $summary = [];

    public function mount(int $project): void
    {
        $record = InvestmentProject::query()->find($project);
        $summary = $record ? app(InvestorProjectSummary::class)->for($record, $this->account()->investor) : null;

        abort_if($summary === null, 404);

        $this->projectId = $record->getKey();
        $this->summary = $summary;
    }

    public function getTitle(): string
    {
        return (string) ($this->summary['plan']['name'] ?? __('Project'));
    }
}
