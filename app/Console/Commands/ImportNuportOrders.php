<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\NuportOrderImportService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ImportNuportOrders extends Command
{
    protected $signature = 'orders:import-nuport
        {file : Path to the CSV from Nuport (Orders, Action, Export As CSV)}
        {company : Company slug to import into}
        {--apply : Save the orders. Without it the command only previews and saves nothing}';

    protected $description = 'Copy past orders (customer, products, payment, status) from a Nuport order export into a company';

    public function handle(NuportOrderImportService $service, CompanyContext $context): int
    {
        $company = Company::query()->where('slug', $this->argument('company'))->first();

        if (! $company) {
            $this->error("Company '{$this->argument('company')}' not found.");

            return self::FAILURE;
        }

        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("File '{$file}' not found.");

            return self::FAILURE;
        }

        $context->set($company);

        try {
            $report = $service->run($file, dryRun: ! $this->option('apply'));
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        } finally {
            $context->clear();
        }

        foreach ($service->summary($report) as $line) {
            $this->line($line);
        }

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
