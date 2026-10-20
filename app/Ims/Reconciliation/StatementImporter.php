<?php

namespace App\Ims\Reconciliation;

use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Reads a bKash / Nagad / Rocket / bank statement (CSV or Excel) and keeps
 * its money-in lines (IMS-V2/PRD.md FR-24, old IMS §12). The columns are
 * found by their headings (date, amount or credit, transaction id or
 * reference, sender or description). A line already read before (same
 * source, date, amount, reference and sender) is skipped. Then the Matcher
 * looks for the waiting investment each line pays for.
 */
class StatementImporter
{
    private const HEADINGS = [
        'date' => ['date', 'time', 'তারিখ', 'transaction date', 'value date'],
        'amount' => ['amount', 'credit', 'deposit', 'cash in', 'received', 'টাকা', 'পরিমাণ'],
        'reference' => ['trx', 'trxid', 'transaction id', 'txn', 'reference', 'ref', 'cheque'],
        'counterparty' => ['sender', 'from', 'counterparty', 'description', 'narration', 'particulars', 'name', 'account'],
    ];

    public function __construct(private Matcher $matcher, private AuditLogService $audit) {}

    public function import(Company $company, string $source, UploadedFile|string $file, User $by, ?string $fileName = null): BankStatementImport
    {
        if (! $by->hasPermission('ims.reconcile')) {
            throw ValidationException::withMessages(['file' => __('You do not have permission to match statements.')]);
        }

        if (! array_key_exists($source, BankStatementImport::SOURCES)) {
            throw ValidationException::withMessages(['source' => __('Choose where the statement is from.')]);
        }

        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $fileName ??= $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);
        $rows = $this->rows($path, strtolower(pathinfo($fileName, PATHINFO_EXTENSION)));
        $lines = $this->lines($rows);

        if ($lines === []) {
            throw ValidationException::withMessages(['file' => __('No money-in lines were found. The file needs a date, an amount and (if there is one) a transaction id column with headings.')]);
        }

        $import = DB::transaction(function () use ($company, $source, $fileName, $lines, $by): BankStatementImport {
            $import = BankStatementImport::query()->create([
                'company_id' => $company->getKey(),
                'source' => $source,
                'file_name' => mb_substr($fileName, 0, 255),
                'imported_by' => $by->getKey(),
            ]);

            $kept = 0;

            foreach ($lines as $line) {
                $hash = hash('sha256', implode('|', [$source, $line['date'], $line['amount'], $line['reference'], $line['counterparty']]));

                if (BankStatementLine::query()->where('line_hash', $hash)->exists()) {
                    continue;
                }

                BankStatementLine::query()->create([
                    'company_id' => $company->getKey(),
                    'import_id' => $import->getKey(),
                    'line_hash' => $hash,
                    'transacted_on' => $line['date'],
                    'amount' => $line['amount'],
                    'reference' => $line['reference'],
                    'counterparty' => $line['counterparty'],
                ]);
                $kept++;
            }

            $import->forceFill(['lines_count' => $kept])->save();

            return $import;
        });

        $import->forceFill(['matched_count' => $this->matcher->suggest($import)])->save();
        $this->audit->record('ims.statement_imported', $import, null, ['source' => $source, 'lines' => $import->lines_count, 'suggested' => $import->matched_count]);

        return $import;
    }

    /** @return list<list<mixed>> */
    private function rows(string $path, string $extension): array
    {
        try {
            if (in_array($extension, ['xlsx', 'xls'], true)) {
                $reader = new XlsxReader;
                $reader->open($path);
                $rows = [];

                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $rows[] = $row->toArray();
                    }

                    break; // the first sheet only
                }

                $reader->close();

                return $rows;
            }

            $handle = fopen($path, 'r');
            $first = (string) fgets($handle);
            rewind($handle);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
            $rows = [];

            while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $rows[] = $row;
            }

            fclose($handle);

            return $rows;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('The file could not be read. Upload a CSV or Excel (.xlsx) file.')]);
        }
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return list<array{date: string|null, amount: float, reference: string|null, counterparty: string|null}>
     */
    private function lines(array $rows): array
    {
        $columns = null;
        $lines = [];

        foreach ($rows as $row) {
            $cells = array_map(fn ($cell): string => trim((string) ($cell instanceof \DateTimeInterface ? $cell->format('Y-m-d') : $cell)), $row);

            if ($columns === null) {
                $columns = $this->columns($cells);

                continue;
            }

            if (! isset($columns['amount'])) {
                break;
            }

            $amount = $this->amount($cells[$columns['amount']] ?? '');

            if ($amount === null || $amount <= 0) {
                continue; // money out, a total, or an empty line
            }

            $lines[] = [
                'date' => isset($columns['date']) ? $this->date($cells[$columns['date']] ?? '') : null,
                'amount' => $amount,
                'reference' => isset($columns['reference']) ? (mb_substr($cells[$columns['reference']] ?? '', 0, 255) ?: null) : null,
                'counterparty' => isset($columns['counterparty']) ? (mb_substr($cells[$columns['counterparty']] ?? '', 0, 255) ?: null) : null,
            ];
        }

        return $lines;
    }

    /** @param  list<string>  $cells @return array<string, int>|null */
    private function columns(array $cells): ?array
    {
        $found = [];

        foreach ($cells as $index => $cell) {
            $heading = mb_strtolower($cell);

            foreach (self::HEADINGS as $key => $words) {
                if (isset($found[$key])) {
                    continue;
                }

                foreach ($words as $word) {
                    if ($heading !== '' && str_contains($heading, $word)) {
                        $found[$key] = $index;

                        continue 3;
                    }
                }
            }
        }

        return $found;
    }

    private function amount(string $value): ?float
    {
        $value = strtr($value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $clean = preg_replace('/[^\d.\-]/', '', $value);

        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }

    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'm/d/Y', 'Y-m-d H:i:s', 'd/m/Y H:i', 'd M Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, trim($value));

                if ($date !== false) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                continue;
            }
        }

        try {
            return $value === '' ? null : CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
