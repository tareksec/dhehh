<?php

namespace App\Services;

use App\Models\Customer;
use App\Support\CsvSafe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerCsvService
{
    public const HEADINGS = [
        'name',
        'phone',
        'email',
        'customer_type',
        'customer_source',
        'opening_balance',
        'is_active',
        'address',
    ];

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::HEADINGS);

            Customer::query()
                ->orderBy('name')
                ->chunk(200, function (Collection $customers) use ($handle): void {
                    foreach ($customers as $customer) {
                        fputcsv($handle, CsvSafe::row($this->customerRow($customer)));
                    }
                });

            fclose($handle);
        }, 'customers-export.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function sample(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::HEADINGS);
            fputcsv($handle, [
                'Demo Customer',
                '+8801712345678',
                'customer@example.com',
                'retail',
                'facebook',
                '1200',
                'yes',
                'Mirpur, Dhaka',
            ]);
            fclose($handle);
        }, 'customers-import-sample.csv', [
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
                        $customer = $this->upsertCustomer($data);
                        $customer->wasRecentlyCreated ? $created++ : $updated++;
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

    protected function upsertCustomer(array $data): Customer
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Customer name is required.']);
        }

        $email = $this->nullableString($data['email'] ?? null);
        $phone = $this->nullableString($data['phone'] ?? null);
        $customer = $this->findExistingCustomer($email, $phone) ?? new Customer;

        $customer->fill([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'address' => $this->nullableText($data['address'] ?? null),
            'customer_type' => Customer::typeKey($this->nullableString($data['customer_type'] ?? null) ?: 'regular'),
            'customer_source' => $this->source($data['customer_source'] ?? null),
            'opening_balance' => $this->decimal($data['opening_balance'] ?? 0) ?? 0,
            'is_active' => $this->boolean($data['is_active'] ?? true),
        ]);

        $customer->save();

        return $customer;
    }

    protected function findExistingCustomer(?string $email, ?string $phone): ?Customer
    {
        if ($email) {
            $customer = Customer::query()->where('email', $email)->first();

            if ($customer) {
                return $customer;
            }
        }

        if ($phone) {
            return Customer::query()->where('phone', $phone)->first();
        }

        return null;
    }

    protected function source(mixed $value): ?string
    {
        $source = $this->nullableString($value);

        return $source ? Customer::sourceKey($source) : null;
    }

    protected function customerRow(Customer $customer): array
    {
        return [
            $customer->name,
            $customer->phone,
            $customer->email,
            $customer->customer_type,
            $customer->customer_source,
            $customer->opening_balance,
            $customer->is_active ? 'yes' : 'no',
            $customer->address,
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
