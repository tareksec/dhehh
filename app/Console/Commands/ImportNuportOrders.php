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
        {--map= : Text file with lines of "NUPORT-SKU = ERP-SKU" for Nuport SKUs the ERP does not have}
        {--create-missing : Create products that no SKU or mapping finds (inactive, stock 0, cost price 0)}
        {--open-live : Bring orders still open in Nuport (no courier parcel yet) in as live orders that take stock}
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

        $map = '';

        if (filled($this->option('map'))) {
            if (! is_file((string) $this->option('map'))) {
                $this->error("Mapping file '{$this->option('map')}' not found.");

                return self::FAILURE;
            }

            $map = (string) file_get_contents((string) $this->option('map'));
        }

        $context->set($company);

        try {
            $report = $service->run(
                $file,
                dryRun: ! $this->option('apply'),
                skuMap: $map,
                createMissing: (bool) $this->option('create-missing'),
                openAsLive: (bool) $this->option('open-live'),
            );
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
