<?php

namespace App\Events;

use App\Models\InvestmentProject;
use App\Models\InvestorGroup;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A group's project reached the end of its cycle and was closed (ready for
 * settlement). The investors' "project completed" message hangs off this
 * (16_INVESTOR_GROUPS_PLAN.md §3.8, phase G6).
 */
class InvestorGroupProjectCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly InvestmentProject $project,
        public readonly InvestorGroup $group,
        public readonly ?InvestmentProject $nextProject = null,
    ) {}
}
