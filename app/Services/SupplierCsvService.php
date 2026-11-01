<?php

namespace App\Services;

use App\Models\Supplier;
use App\Support\CsvSafe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierCsvService
{
    public const HEADINGS = [
        'name',
        'company_name',
        'phone',
        'email',
        'opening_balance',
        'is_active',
        'address',
    ];

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::HEADINGS);

            Supplier::query()
                ->orderBy('name')
                ->chunk(200, function (Collection $suppliers) use ($handle): void {
                    foreach ($suppliers as $supplier) {
                        fputcsv($handle, CsvSafe::row($this->supplierRow($supplier)));
                    }
                });

            fclose($handle);
        }, 'suppliers-export.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function sample(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::HEADINGS);
            fputcsv($handle, [
                'Demo Supplier',
                'Demo Trading Co.',
                '+8613800138000',
                'supplier@example.com',
                '2500',
                'yes',
                'Huaqiangbei, Shenzhen, China',
            ]);
            fclose($handle);
        }, 'suppliers-import-sample.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function import(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            throw ValidationException::withMessages([
                'csv' => 'Unable to read the uploaded CSV file.',
            ]);
        }

        $headings = $this->normalizeHeadings(fgetcsv($handle) ?: []);
        $missingHeadings = array_diff(['name'], $headings);

        if ($missingHeadings !== []) {
            fclose($handle);

            throw ValidationException::withMessages([
                'csv' => 'Missing required CSV columns: '.implode(', ', $missingHeadings),
            ]);
        }

        $created = 0;
        $updated = 0;
        $rowNumber = 1;
        $errors = [];

        try {
            DB::transaction(function () use ($handle, $headings, &$created, &$updated, &$rowNumber, &$errors): void {
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNumber++;
                    $data = $this->combineRow($headings, $row);

                    if ($this->isEmptyRow($data)) {
                        continue;
                    }

                    try {
                        $supplier = $this->upsertSupplier($data);
                        $supplier->wasRecentlyCreated ? $created++ : $updated++;
                    } catch (ValidationException $exception) {
                        foreach ($exception->errors() as $messages) {
                            foreach ($messages as $message) {
                                $errors[] = "Row {$rowNumber}: {$message}";
                            }
                        }
                    }
                }

                if ($errors !== []) {
                    throw ValidationException::withMessages([
                        'csv' => $errors,
                    ]);
                }
            });
        } finally {
            fclose($handle);
        }

        return [
            'created' => $created,
            'updated' => $updated,
        ];
    }

    protected function upsertSupplier(array $data): Supplier
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Supplier name is required.']);
        }

        $email = $this->nullableString($data['email'] ?? null);
        $phone = $this->nullableString($data['phone'] ?? null);
        $companyName = $this->nullableString($data['company_name'] ?? null);
        $supplier = $this->findExistingSupplier($email, $phone, $companyName) ?? new Supplier;

        $supplier->fill([
            'name' => $name,
            'company_name' => $companyName,
            'phone' => $phone,
            'email' => $email,
            'address' => $this->nullableText($data['address'] ?? null),
            'opening_balance' => $this->decimal($data['opening_balance'] ?? 0) ?? 0,
            'is_active' => $this->boolean($data['is_active'] ?? true),
        ]);

        $supplier->save();

        return $supplier;
    }

    protected function findExistingSupplier(?string $email, ?string $phone, ?string $companyName): ?Supplier
    {
        if ($email) {
            $supplier = Supplier::query()->where('email', $email)->first();

            if ($supplier) {
                return $supplier;
            }
        }

        if ($phone) {
            $supplier = Supplier::query()->where('phone', $phone)->first();

            if ($supplier) {
                return $supplier;
            }
        }

        if ($companyName) {
            return Supplier::query()->where('company_name', $companyName)->first();
        }

        return null;
    }

    protected function supplierRow(Supplier $supplier): array
    {
        return [
            $supplier->name,
            $supplier->company_name,
            $supplier->phone,
            $supplier->email,
            $supplier->opening_balance,
            $supplier->is_active ? 'yes' : 'no',
            $supplier->address,
        ];
    }

    protected function normalizeHeadings(array $headings): array
    {
        return collect($headings)
            ->map(fn ($heading): string => Str::of((string) $heading)
                ->replace("\xEF\xBB\xBF", '')
                ->trim()
                ->lower()
                ->replace([' ', '-'], '_')
                ->toString())
            ->all();
    }

    protected function combineRow(array $headings, array $row): array
    {
        $data = [];

        foreach ($headings as $index => $heading) {
            if ($heading === '') {
                continue;
            }

            $data[$heading] = $row[$index] ?? null;
        }

        return $data;
    }

    protected function isEmptyRow(array $data): bool
    {
        return collect($data)
            ->filter(fn ($value): bool => trim((string) $value) !== '')
            ->isEmpty();
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 255, '');
    }

    protected function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function decimal(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace(',', '', $value);

        if (! is_numeric($normalized) || (float) $normalized < 0) {
            throw ValidationException::withMessages([
                'opening_balance' => 'Opening balance must be a non-negative number.',
            ]);
        }

        return (float) $normalized;
    }

    protected function boolean(mixed $value): bool
    {
        $value = Str::lower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'y', 'active', ''], true);
    }
}
