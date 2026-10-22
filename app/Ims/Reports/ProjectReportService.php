<?php

namespace App\Ims\Reports;

use App\Ims\Notifications\ImsNotifier;
use App\Ims\Profit\ProjectFinancials;
use App\Models\Company;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\ProjectReport;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Project reports for investors (IMS-V2/PRD.md FR-36): staff write a draft;
 * publishing freezes its figures (totals only, no customer, supplier or
 * price) and tells the project's investors. A published report never
 * changes and is never deleted; its PDF is drawn from the frozen figures.
 */
class ProjectReportService
{
    public function __construct(
        private ProjectFinancials $financials,
        private ImsNotifier $notifier,
        private AuditLogService $audit,
    ) {}

    public function publish(ProjectReport $report, User $by): ProjectReport
    {
        if (! $by->hasPermission('ims.updates.manage')) {
            throw ValidationException::withMessages(['report' => __('You do not have permission to publish reports.')]);
        }

        $published = DB::transaction(function () use ($report, $by): ProjectReport {
            $locked = ProjectReport::query()->lockForUpdate()->findOrFail($report->getKey());

            if ($locked->isPublished()) {
                throw ValidationException::withMessages(['report' => __('This report is already published.')]);
            }

            $project = $locked->project()->firstOrFail();
            $settlement = $project->settlement()->whereIn('status', ['confirmed', 'paid_out'])->first();

            $locked->forceFill([
                'status' => ProjectReport::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $by->getKey(),
                'summary' => [
                    'project' => ['code' => $project->project_code, 'name' => $project->name, 'status' => $project->status, 'start_date' => $project->start_date?->toDateString(), 'end_date' => $project->end_date?->toDateString()],
                    'figures' => $this->financials->compute($project),
                    'settled' => $settlement === null ? null : [
                        'revenue' => (float) $settlement->total_revenue,
                        'cost' => (float) $settlement->total_cost,
                        'net_profit' => (float) $settlement->net_profit,
                        'investor_pool' => (float) $settlement->investor_pool_amount,
                        'outcome' => $settlement->outcome,
                    ],
                    'capital' => round($project->totalCapitalBase(), 2),
                    'investor_share_percent' => (float) $project->investor_share_percent,
                ],
            ])->save();

            $this->audit->record('ims.report_published', $locked, null, ['project_id' => $project->getKey(), 'by' => $by->getKey()]);

            return $locked;
        });

        $project = $published->project;

        Investor::query()->whereIn('id', Investment::query()->where('project_id', $project->getKey())->pluck('investor_id'))->get()
            ->each(fn (Investor $investor) => $this->notifier->send('project_report', $investor, ['project' => (string) $project->name, 'title' => (string) $published->title]));

        return $published;
    }

    /** The PDF, drawn from the frozen figures only. */
    public function pdf(ProjectReport $report): string
    {
        abort_unless($report->isPublished(), 404);

        return ImsPdf::render('ims.pdf.project-report', [
            'report' => $report,
            'summary' => $report->summary ?? [],
            'company' => Company::query()->find($report->company_id),
        ]);
    }
}
