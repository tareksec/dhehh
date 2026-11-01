<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Supplier;
use App\Services\CompanyContext;
use App\Services\SupplierWorkbookImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportSupplierWorkbook extends Command
{
    protected $signature = 'supplier-account:import-workbook
        {file : Path to the supplier workbook (.xlsx) with the product tabs and the Summary sheet}
        {supplier : Supplier id or exact name}
        {company : Company slug to import into}
        {--map= : CSV with two columns, workbook product name and ERP SKU, for products whose names differ}
        {--date= : Date of the opening receipt (default: the earliest date in the workbook)}
        {--replace : Replace an earlier import of this supplier with these figures}
        {--skip-unmatched : Save the matched products and leave the others out}
        {--apply : Save the history. Without it the command only previews and saves nothing}';

    protected $description = 'Copy a hand-kept supplier workbook (received goods, goods the supplier took, advance, cash, shipping, ad cost) into the supplier account as history';

    public function handle(SupplierWorkbookImportService $service, CompanyContext $context): int
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
            $supplier = $this->supplier((string) $this->argument('supplier'));

            if (! $supplier) {
                $this->error("Supplier '{$this->argument('supplier')}' not found in {$company->name}.");

                return self::FAILURE;
            }

            $report = $service->run(
                $file,
                $supplier,
                dryRun: ! $this->option('apply'),
                openingDate: $this->option('date') ? Carbon::parse((string) $this->option('date')) : null,
                productMap: $this->productMap((string) $this->option('map')),
                replace: (bool) $this->option('replace'),
                skipUnmatched: (bool) $this->option('skip-unmatched'),
            );
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $context->clear();
        }

        foreach ($service->summary($report) as $line) {
            $this->line($line);
        }

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function supplier(string $key): ?Supplier
    {
        return Supplier::query()
            ->when(ctype_digit($key), fn ($query) => $query->whereKey((int) $key), fn ($query) => $query->where('name', $key))
            ->first();
    }

    /**
     * @return array<string, string>
     */
    private function productMap(string $path): array
    {
        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $map = [];
        $handle = fopen($path, 'r');

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 2 && filled($row[0]) && filled($row[1])) {
                $map[trim(ltrim((string) $row[0], "\xEF\xBB\xBF"))] = trim((string) $row[1]);
            }
        }

        fclose($handle);

        return $map;
    }
}
