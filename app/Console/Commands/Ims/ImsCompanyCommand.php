<?php

namespace App\Console\Commands\Ims;

use App\Ims\Settings\ImsSettings;
use App\Models\Company;
use App\Services\CompanyContext;
use App\Support\ModuleGuard;
use Illuminate\Console\Command;

/**
 * The IMS v2 daily jobs (IMS-V2/TRD.md §6): run per company that has IMS
 * on, one company's failure never stops the others (ModuleGuard), each can
 * be switched off in IMS settings, and the company is set and cleared in
 * CompanyContext around the work.
 */
abstract class ImsCompanyCommand extends Command
{
    /** The IMS settings switch for this job (jobs.*). */
    abstract protected function job(): string;

    abstract protected function runFor(Company $company): int;

    public function handle(CompanyContext $context): int
    {
        $companies = Company::withoutGlobalScopes()
            ->where('is_active', true)
            ->where('ims_enabled', true)
            ->whereNotNull('ims_domain')
            ->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            if (! ImsSettings::jobOn($company, $this->job())) {
                continue;
            }

            ModuleGuard::run('ims.'.$this->job().'.company-'.$company->getKey(), function () use ($company, $context): void {
                $context->set($company);

                try {
                    $done = $this->runFor($company);
                    $this->info("{$company->name}: {$done}");
                } finally {
                    $context->clear();
                }
            });
        }

        return self::SUCCESS;
    }
}
