<?php

namespace App\Jobs;

use App\Models\InvestmentProject;
use App\Models\ProjectSettlement;
use App\Services\InvestorProjectMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Texts the investors of a project about its new status (see InvestorProjectMessageService). */
class SendInvestorProjectStatusSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** An SMS is not safe to repeat, so a failed run is not retried. */
    public int $tries = 1;

    public function __construct(public int $projectId, public string $key, public ?int $settlementId = null) {}

    public function handle(InvestorProjectMessageService $messages): void
    {
        $project = InvestmentProject::withoutGlobalScopes()->with('company')->find($this->projectId);

        if (! $project) {
            return;
        }

        $settlement = $this->settlementId ? ProjectSettlement::withoutGlobalScopes()->find($this->settlementId) : null;

        $messages->send($project, $this->key, $settlement);
    }
}
