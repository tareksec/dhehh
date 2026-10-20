<?php

namespace App\Console\Commands\Ims;

use App\Ims\Profit\ProposalService;
use App\Models\Company;

/** Daily: a profit proposal for every closed cycle without a settlement (PRD FR-45). Makes each only once. */
class MakeProfitProposals extends ImsCompanyCommand
{
    protected $signature = 'ims:profit-proposals {--company= : Company id (default: every company with IMS on)}';

    protected $description = 'IMS v2: work out a profit proposal for every finished cycle that has none.';

    protected function job(): string
    {
        return 'profit_proposals';
    }

    protected function runFor(Company $company): int
    {
        return app(ProposalService::class)->generate($company);
    }
}
