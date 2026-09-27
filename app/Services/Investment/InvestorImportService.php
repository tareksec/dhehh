<?php

namespace App\Services\Investment;

use App\Models\Investor;
use App\Models\InvestorDraft;
use App\Services\CompanyContext;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Reads investors from an Excel/CSV file or pasted text into InvestorDraft
 * rows for review, and turns a reviewed draft into a real Investor.
 *
 * Files are tables: the first non-empty row is the header, matched against
 * config('investor_import.fields'). Pasted text is either a table copied
 * from a spreadsheet (tab/comma/semicolon/pipe separated, with a header
 * row) or "Label: value" lines, one investor per block separated by a blank
 * line.
 */
class InvestorImportService
{
    public const SAMPLE_HEADINGS = ['name', 'display_name', 'guardian_name', 'date_of_birth', 'phone', 'email', 'nid_number', 'address', 'stamp_number', 'cheque_number', 'is_channel_partner', 'channel_partner_phone', 'nominee_name', 'nominee_nid_or_passport', 'nominee_phone', 'nominee_relation', 'nominee_address'];

    private const STRING_FIELDS = ['name', 'display_name', 'guardian_name', 'email', 'stamp_number', 'cheque_number', 'nominee_name', 'nominee_relation'];

    private const TEXT_FIELDS = ['address', 'nominee_address'];

    private const NID_FIELDS = ['nid_number', 'nominee_nid_or_passport'];

    private const PHONE_FIELDS = ['phone', 'nominee_phone'];

    /** Label-to-field lookup built from the config aliases. */
    private ?array $aliases = null;

    /**
     * @return array{created: int, ignored_columns: list<string>}
     */
    public function importFile(string $path, string $originalName): array
    {
        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === 'txt') {
            return $this->importText((string) file_get_contents($path), InvestorDraft::SOURCE_TEXT, $originalName);
        }

        if (in_array($extension, ['xlsx', 'ods'], true)) {
            $rows = $this->readSpreadsheet($path, $extension);

            return $this->importTable($rows, InvestorDraft::SOURCE_EXCEL, $originalName);
        }

        if ($extension === 'csv') {
            $content = $this->cleanText((string) file_get_contents($path));
            $delimiter = $this->detectDelimiter($content) ?? ',';

            return $this->importTable($this->splitDelimited($content, $delimiter), InvestorDraft::SOURCE_CSV, $originalName);
        }

        throw ValidationException::withMessages(['file' => __('Upload an Excel (.xlsx), CSV or text file.')]);
    }

    /**
     * @return array{created: int, ignored_columns: list<string>}
     */
    public function importText(string $text, string $source = InvestorDraft::SOURCE_TEXT, ?string $sourceName = null): array
    {
        $text = $this->cleanText($text);

        if (trim($text) === '') {
            throw ValidationException::withMessages(['text' => __('Paste the investor details first.')]);
        }

        $delimiter = $this->detectDelimiter($text);

        if ($delimiter !== null) {
            return $this->importTable($this->splitDelimited($text, $delimiter), $source, $sourceName);
        }

        return $this->storeDrafts($this->readLabelledText($text), $source, $sourceName, []);
    }

    /**
     * Validate a reviewed draft, create the Investor and delete the draft.
     */
    public function confirm(InvestorDraft $draft): Investor
    {
        $companyId = (int) $draft->company_id;
        $data = collect(InvestorDraft::INVESTOR_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => $draft->getAttribute($field)])
            ->all();
        $data['date_of_birth'] = $draft->date_of_birth?->toDateString();

        Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255', Rule::unique('investors', 'phone')->where('company_id', $companyId)],
            'email' => ['nullable', 'email', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'channel_partner_id' => ['nullable', Rule::exists('investors', 'id')->where('company_id', $companyId)],
        ], [
            'name.required' => __('Name is missing.'),
            'phone.required' => __('Phone is missing.'),
            'phone.unique' => __('Phone :phone already belongs to another investor.', ['phone' => (string) $draft->phone]),
            'email.email' => __('Email :email is not valid.', ['email' => (string) $draft->email]),
        ])->validate();

        return DB::transaction(function () use ($draft, $data, $companyId): Investor {
            $investor = Investor::query()->create([...$data, 'company_id' => $companyId, 'is_channel_partner' => (bool) $data['is_channel_partner']]);
            $draft->delete();

            return $investor;
        });
    }

    public function sampleCsv(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::SAMPLE_HEADINGS);
            fputcsv($handle, ['Abdul Karim', '', 'Abdul Rahim', '1980-05-01', '01712345678', 'karim@example.com', '1990123456789', 'House 4, Road 2, Dhaka', '9363512', 'SBL-4457821', 'no', '', 'Karima Begum', '19901234567', '01812345678', 'Wife', 'House 4, Road 2, Dhaka']);
            fclose($handle);
        }, 'investors-import-sample.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return array{created: int, ignored_columns: list<string>}
     */
    private function importTable(array $rows, string $source, ?string $sourceName): array
    {
        $headerIndex = null;

        foreach ($rows as $index => $row) {
            if (! $this->isEmptyRow($row)) {
                $headerIndex = $index;
                break;
            }
        }

        if ($headerIndex === null) {
            throw ValidationException::withMessages(['file' => __('No investors were found. Check that the first row holds the column names.')]);
        }

        $columns = [];
        $ignored = [];

        foreach ($rows[$headerIndex] as $position => $heading) {
            $label = trim($this->cellToString($heading));
            $field = $this->fieldFor($label);

            if ($field !== null && ! in_array($field, $columns, true)) {
                $columns[$position] = $field;
            } elseif ($label !== '') {
                $ignored[] = $label;
            }
        }

        if (! in_array('name', $columns, true) && ! in_array('phone', $columns, true)) {
            throw ValidationException::withMessages(['file' => __('No name or phone column was found. Download the sample CSV to see the column names.')]);
        }

        $records = [];

        foreach (array_slice($rows, $headerIndex + 1, null, true) as $index => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $values = [];

            foreach ($columns as $position => $field) {
                $values[$field] = $row[$position] ?? null;
            }

            $records[] = ['row' => $index + 1, 'values' => $values, 'notes' => []];
        }

        return $this->storeDrafts($records, $source, $sourceName, $ignored);
    }

    /**
     * "Label: value" lines. A blank line, a separator line (---), or a label
     * that repeats within the current investor starts the next investor.
     *
     * @return list<array{row: int, values: array<string, mixed>, notes: list<array>}>
     */
    private function readLabelledText(string $text): array
    {
        $records = [];
        $current = null;
        $lastField = null;

        $finish = function () use (&$records, &$current, &$lastField): void {
            if ($current !== null && $current['values'] !== []) {
                $records[] = $current;
            }

            $current = null;
            $lastField = null;
        };

        foreach (preg_split('/\R/u', $text) as $index => $line) {
            $line = trim($line);

            if (! preg_match('/[\p{L}\p{N}]/u', $line)) {
                $finish();

                continue;
            }

            $line = (string) preg_replace('/^(?:\p{N}{1,3}[.)]|[-*\x{2022}])\s+/u', '', $line);
            $field = null;

            if (preg_match('/^([^:=\x{FF1A}]{1,60}?)\s*[:=\x{FF1A}]\s*(.*)$/u', $line, $match)) {
                $field = $this->fieldFor($match[1]);
                $value = trim($match[2]);
            }

            if ($field === null) {
                if ($current !== null && in_array($lastField, self::TEXT_FIELDS, true)) {
                    $current['values'][$lastField] = trim($current['values'][$lastField]."\n".$line);
                } elseif ($current !== null) {
                    $current['notes'][] = ['message' => 'Line :line was not understood and was left out: :text', 'params' => ['line' => $index + 1, 'text' => Str::limit($line, 80)]];
                } else {
                    $current = ['row' => $index + 1, 'values' => [], 'notes' => [['message' => 'Line :line was not understood and was left out: :text', 'params' => ['line' => $index + 1, 'text' => Str::limit($line, 80)]]]];
                }

                continue;
            }

            if ($current !== null && array_key_exists($field, $current['values'])) {
                $finish();
            }

            $current ??= ['row' => $index + 1, 'values' => [], 'notes' => []];
            $current['values'][$field] = $value;
            $lastField = $field;
        }

        $finish();

        return $records;
    }

    /**
     * @param  list<array{row: int, values: array<string, mixed>, notes: list<array>}>  $records
     * @param  list<string>  $ignoredColumns
     * @return array{created: int, ignored_columns: list<string>}
     */
    private function storeDrafts(array $records, string $source, ?string $sourceName, array $ignoredColumns): array
    {
        $context = app(CompanyContext::class);

        if (! $context->hasCompany() || $context->isAllCompanies()) {
            throw ValidationException::withMessages(['file' => __('Switch to a single company before importing investors.')]);
        }

        if ($records === []) {
            throw ValidationException::withMessages(['text' => __('No investors were found. Use one "Label: value" line per detail, or paste a table with a header row.')]);
        }

        $max = (int) config('investor_import.max_rows', 500);

        if (count($records) > $max) {
            throw ValidationException::withMessages(['file' => __('Import at most :max investors at a time.', ['max' => $max])]);
        }

        $companyId = (int) $context->id();
        $batchId = (string) Str::uuid();

        DB::transaction(function () use ($records, $source, $sourceName, $companyId, $batchId): void {
            foreach ($records as $record) {
                InvestorDraft::query()->create([
                    ...$this->draftAttributes($record['values'], $record['notes'], $companyId),
                    'company_id' => $companyId,
                    'batch_id' => $batchId,
                    'source' => $source,
                    'source_name' => $sourceName ? Str::limit($sourceName, 250, '') : null,
                    'source_row' => $source === InvestorDraft::SOURCE_TEXT && $sourceName === null ? null : $record['row'],
                    'created_by' => Auth::id(),
                ]);
            }
        });

        return ['created' => count($records), 'ignored_columns' => array_values(array_unique($ignoredColumns))];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array>  $notes
     */
    private function draftAttributes(array $values, array $notes, int $companyId): array
    {
        $attributes = ['import_notes' => $notes];

        foreach ($values as $field => $raw) {
            $text = trim($this->cellToString($raw));

            if ($text === '') {
                continue;
            }

            if (in_array($field, self::STRING_FIELDS, true)) {
                $attributes[$field] = Str::limit((string) preg_replace('/[ \t]+/u', ' ', $text), 255, '');
            } elseif (in_array($field, self::TEXT_FIELDS, true)) {
                $attributes[$field] = $text;
            } elseif (in_array($field, self::PHONE_FIELDS, true)) {
                $attributes[$field] = Str::limit($this->normalizePhone($text), 255, '');
            } elseif (in_array($field, self::NID_FIELDS, true)) {
                $nid = $this->latinDigits($text);
                $attributes[$field] = $nid;

                if ((is_float($raw) && abs($raw) >= 1e15) || preg_match('/^\d(\.\d+)?e\+?\d+$/i', $nid)) {
                    $attributes['import_notes'][] = ['message' => 'The NID :nid looks rounded by Excel. Check it against the NID card.', 'params' => ['nid' => $nid]];
                }
            } elseif ($field === 'date_of_birth') {
                $date = $raw instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($raw) : $this->parseDate($text);

                if ($date && $date <= new DateTimeImmutable('today') && (int) $date->format('Y') >= 1900) {
                    $attributes['date_of_birth'] = $date->format('Y-m-d');
                } else {
                    $attributes['import_notes'][] = ['message' => 'Date of birth ":value" could not be read. Enter it by hand.', 'params' => ['value' => Str::limit($text, 40)]];
                }
            } elseif ($field === 'is_channel_partner') {
                $attributes['is_channel_partner'] = in_array(Str::lower($text), array_map(Str::lower(...), config('investor_import.yes_values', [])), true);
            } elseif ($field === 'channel_partner') {
                $partner = $this->findChannelPartner($text, $companyId);

                if ($partner) {
                    $attributes['channel_partner_id'] = $partner->getKey();
                } else {
                    $attributes['import_notes'][] = ['message' => 'Channel partner ":value" was not found among investors. Pick them by hand.', 'params' => ['value' => Str::limit($text, 60)]];
                }
            }
        }

        if (isset($attributes['email'])) {
            $attributes['email'] = Str::lower($attributes['email']);
        }

        return $attributes;
    }

    private function findChannelPartner(string $value, int $companyId): ?Investor
    {
        $query = fn () => Investor::query()->where('company_id', $companyId);
        $phone = $this->normalizePhone($value);

        if (preg_match('/\d{6,}/', $phone)) {
            $partner = $query()->where('phone', $phone)->first();

            if ($partner) {
                return $partner;
            }
        }

        $matches = $query()->whereRaw('LOWER(name) = ?', [Str::lower($value)])->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @return list<list<mixed>>
     */
    private function readSpreadsheet(string $path, string $extension): array
    {
        $reader = $extension === 'ods' ? new OdsReader : new XlsxReader;
        $rows = [];

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }

                break;
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('The file could not be read. Save it again as .xlsx or .csv and retry.')]);
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private function splitDelimited(string $content, string $delimiter): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * The separator whose header line names the most known columns (at
     * least two, including name or phone); null when the text is not a table.
     */
    private function detectDelimiter(string $content): ?string
    {
        foreach (preg_split('/\R/u', $content) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $best = null;
            $bestCount = 1;

            foreach (["\t", ',', ';', '|'] as $delimiter) {
                $fields = array_filter(array_map(fn ($cell): ?string => $this->fieldFor((string) $cell), str_getcsv($line, $delimiter, '"', '')));

                if (count($fields) > $bestCount && (in_array('name', $fields, true) || in_array('phone', $fields, true))) {
                    $best = $delimiter;
                    $bestCount = count($fields);
                }
            }

            return $best;
        }

        return null;
    }

    private function fieldFor(string $label): ?string
    {
        if ($this->aliases === null) {
            $this->aliases = [];

            foreach (config('investor_import.fields', []) as $field => $names) {
                foreach ([$field, ...$names] as $name) {
                    $this->aliases[$this->normalizeLabel($name)] ??= $field;
                }
            }

            // The sample CSV's own heading for the channel partner column.
            $this->aliases[$this->normalizeLabel('channel_partner_phone')] ??= 'channel_partner';
        }

        return $this->aliases[$this->normalizeLabel($label)] ?? null;
    }

    private function normalizeLabel(string $label): string
    {
        $label = mb_strtolower(str_replace(["\xEF\xBB\xBF", "'", "\u{2019}"], '', $label));

        return trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $label));
    }

    private function normalizePhone(string $value): string
    {
        $value = trim($this->latinDigits($value));
        $digits = (string) preg_replace('/\D+/', '', $value);

        if ($digits === '') {
            return $value;
        }

        if (strlen($digits) === 13 && str_starts_with($digits, '880')) {
            return '0'.substr($digits, 3);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            return '0'.$digits;
        }

        return str_starts_with($value, '+') ? '+'.$digits : $digits;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($this->latinDigits($value));

        // Excel serial day number (a date column saved in a CSV as a number).
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $value) && (float) $value >= 5000) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) $value.' days');
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'd/m/y', 'd-m-y', 'j M Y', 'j F Y', 'j-M-Y', 'M j, Y', 'F j, Y', 'Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        return null;
    }

    private function latinDigits(string $value): string
    {
        $bangla = array_map(fn (int $digit): string => mb_chr(0x09E6 + $digit, 'UTF-8'), range(0, 9));

        return str_replace($bangla, array_map('strval', range(0, 9)), $value);
    }

    private function cellToString(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'yes' : 'no',
            is_float($value) && floor($value) === $value && abs($value) < 1e20 => number_format($value, 0, '', ''),
            default => (string) $value,
        };
    }

    private function cleanText(string $text): string
    {
        $text = str_replace("\xEF\xBB\xBF", '', $text);

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim($this->cellToString($cell)) !== '') {
                return false;
            }
        }

        return true;
    }
}
