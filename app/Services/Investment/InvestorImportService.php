<?php

namespace App\Services\Investment;

use App\Models\Investment;
use App\Models\Investor;
use App\Models\InvestorDraft;
use App\Models\InvestorSecurityInstrument;
use App\Services\CompanyContext;
use App\Support\InvestorIdentity;
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

    private const STRING_FIELDS = ['name', 'display_name', 'guardian_name', 'email', 'nominee_name', 'nominee_relation'];

    private const TEXT_FIELDS = ['address', 'nominee_address'];

    private const NID_FIELDS = ['nid_number', 'nominee_nid_or_passport'];

    private const PHONE_FIELDS = ['phone', 'nominee_phone'];

    /** Number-like text fields whose Bangla digits are turned into 0-9. */
    private const DIGIT_FIELDS = ['stamp_number', 'cheque_number'];

    /** Field name for known columns that are deliberately not imported (serial no.). */
    private const SKIP = '_skip';

    /** Label-to-field lookup built from the config aliases. */
    private ?array $aliases = null;

    /** Project / payment method / date chosen for the whole import. */
    private array $investmentDefaults = [];

    /**
     * @return array{created: int, ignored_columns: list<string>}
     */
    public function importFile(string $path, string $originalName, array $investment = []): array
    {
        $this->investmentDefaults = $this->investmentDefaults($investment);
        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === 'txt') {
            return $this->importText((string) file_get_contents($path), InvestorDraft::SOURCE_TEXT, $originalName, $investment);
        }

        if (in_array($extension, ['xlsx', 'ods'], true)) {
            $sheets = $this->readSpreadsheet($path, $extension);
            $records = [];
            $ignored = [];

            // Every sheet with a recognisable header row is read (one sheet
            // per investment batch is common); other sheets are skipped.
            foreach ($sheets as $sheetName => $rows) {
                $table = $this->tableRecords($rows);

                if ($table === null) {
                    continue;
                }

                $label = count($sheets) > 1 ? $originalName.' · '.$sheetName : $originalName;

                foreach ($table['records'] as $record) {
                    $records[] = [...$record, 'source_name' => $label];
                }

                $ignored = [...$ignored, ...$table['ignored']];
            }

            if ($records === [] && $ignored === []) {
                throw ValidationException::withMessages(['file' => __('No name or phone column was found. Download the sample CSV to see the column names.')]);
            }

            return $this->storeDrafts($records, InvestorDraft::SOURCE_EXCEL, $originalName, $ignored);
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
    public function importText(string $text, string $source = InvestorDraft::SOURCE_TEXT, ?string $sourceName = null, array $investment = []): array
    {
        $this->investmentDefaults = $this->investmentDefaults($investment);
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
     * Validate a reviewed draft, then in one transaction create the Investor
     * (or use the linked existing one), record the investment from the
     * sheet when a project and amount are set, and delete the draft.
     */
    public function confirm(InvestorDraft $draft): Investor
    {
        $companyId = (int) $draft->company_id;
        $createsInvestment = $draft->willCreateInvestment();
        $existing = null;

        if ($createsInvestment || filled($draft->project_id) || (float) $draft->investment_amount > 0) {
            Validator::make([
                'project_id' => $draft->project_id,
                'investment_amount' => $draft->investment_amount,
                'payment_method' => $draft->payment_method,
                'invested_at' => $draft->invested_at?->toDateString(),
            ], [
                'project_id' => ['required', Rule::exists('investment_projects', 'id')->where('company_id', $companyId)->whereIn('status', InvestorDraft::OPEN_PROJECT_STATUSES)],
                'investment_amount' => ['required', 'numeric', 'gt:0'],
                'payment_method' => ['required', Rule::in(array_keys(Investment::PAYMENT_METHODS))],
                'invested_at' => ['required', 'date'],
            ], [
                'project_id.required' => __('Pick a project for the investment amount, or clear the amount.'),
                'project_id.exists' => __('The chosen project is not open for investments.'),
                'investment_amount.required' => __('Enter the investment amount.'),
                'investment_amount.gt' => __('Enter the investment amount.'),
                'payment_method.required' => __('Pick the payment method.'),
                'invested_at.required' => __('Enter the investment date.'),
            ])->validate();
        }

        if ($draft->existing_investor_id) {
            $existing = Investor::query()->where('company_id', $companyId)->find($draft->existing_investor_id);

            if (! $existing) {
                throw ValidationException::withMessages(['existing_investor_id' => __('The linked existing investor was not found.')]);
            }

            if (! $createsInvestment) {
                throw ValidationException::withMessages(['project_id' => __(':name is already an investor. Pick a project and amount to add a new investment for them, or discard this draft.', ['name' => $existing->name])]);
            }

            $data = [];
        } else {
            $data = $this->validatedInvestorData($draft, $companyId);
        }

        [$investor, $investment] = DB::transaction(function () use ($draft, $data, $companyId, $existing, $createsInvestment): array {
            $investor = $existing ?? Investor::query()->create([...$data, 'company_id' => $companyId, 'is_channel_partner' => (bool) $data['is_channel_partner']]);
            $investment = null;

            if ($createsInvestment) {
                $investment = Investment::query()->create([
                    'company_id' => $companyId,
                    'project_id' => $draft->project_id,
                    'investor_id' => $investor->getKey(),
                    'amount' => $draft->investment_amount,
                    'payment_method' => $draft->payment_method,
                    'invested_at' => $draft->invested_at?->toDateString(),
                    'received_by' => Auth::id(),
                ]);

                if (filled($draft->stamp_number) || filled($draft->cheque_number)) {
                    InvestorSecurityInstrument::query()->create([
                        'investment_id' => $investment->getKey(),
                        'stamp_serial_numbers' => filled($draft->stamp_number)
                            ? array_values(array_filter(array_map('trim', explode(',', (string) $draft->stamp_number))))
                            : null,
                        'cheque_number' => $draft->cheque_number,
                    ]);
                }
            }

            $draft->delete();

            return [$investor, $investment];
        });

        if ($investment) {
            // Same pending capital voucher the project's Investments tab books.
            app(InvestmentLedgerService::class)->recordInvestmentReceived($investment);
        }

        return $investor;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedInvestorData(InvestorDraft $draft, int $companyId): array
    {
        $data = collect(InvestorDraft::INVESTOR_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => $draft->getAttribute($field)])
            ->all();
        $data['date_of_birth'] = $draft->date_of_birth?->toDateString();

        Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'nid_number' => ['required', function (string $attribute, mixed $value, \Closure $fail) use ($companyId): void {
                $owner = Investor::query()->where('company_id', $companyId)->where('nid_hash', InvestorIdentity::hash((string) $value) ?? '-')->first();

                if ($owner) {
                    $fail(__('This NID / passport number already belongs to investor :name.', ['name' => $owner->name]));
                }
            }],
            'phone' => ['required', 'string', 'max:255', Rule::unique('investors', 'phone')->where('company_id', $companyId)],
            'email' => ['nullable', 'email', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'channel_partner_id' => ['nullable', Rule::exists('investors', 'id')->where('company_id', $companyId)],
        ], [
            'name.required' => __('Name is missing.'),
            'nid_number.required' => __('NID / passport number is missing. It is the investor ID, so it is required.'),
            'phone.required' => __('Phone is missing.'),
            'phone.unique' => __('Phone :phone already belongs to another investor.', ['phone' => (string) $draft->phone]),
            'email.email' => __('Email :email is not valid.', ['email' => (string) $draft->email]),
        ])->validate();

        return $data;
    }

    /**
     * @return array{project_id?: int, payment_method?: string, invested_at?: string}
     */
    private function investmentDefaults(array $investment): array
    {
        return array_filter([
            'project_id' => filled($investment['project_id'] ?? null) ? (int) $investment['project_id'] : null,
            'payment_method' => array_key_exists((string) ($investment['payment_method'] ?? ''), Investment::PAYMENT_METHODS) ? $investment['payment_method'] : null,
            'invested_at' => filled($investment['invested_at'] ?? null) ? (string) $investment['invested_at'] : null,
            'channel_partner_id' => filled($investment['channel_partner_id'] ?? null)
                ? Investor::query()->whereKey((int) $investment['channel_partner_id'])->value('id')
                : null,
        ], fn ($value): bool => $value !== null);
    }

    public function sampleCsv(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            $sample = config('investor_import.sample', []);

            if (filled($sample['title'] ?? null)) {
                fputcsv($handle, [$sample['title']], ',', '"', '');
            }

            fputcsv($handle, $sample['headings'] ?? self::SAMPLE_HEADINGS, ',', '"', '');

            foreach ($sample['rows'] ?? [] as $row) {
                fputcsv($handle, $row, ',', '"', '');
            }
            fclose($handle);
        }, 'investors-import-sample.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return array{created: int, ignored_columns: list<string>}
     */
    private function importTable(array $rows, string $source, ?string $sourceName): array
    {
        $table = $this->tableRecords($rows);

        if ($table === null) {
            throw ValidationException::withMessages(['file' => __('No name or phone column was found. Download the sample CSV to see the column names.')]);
        }

        return $this->storeDrafts($table['records'], $source, $sourceName, $table['ignored']);
    }

    /**
     * The header is the first row (among the first 15 filled rows) that
     * names at least two known columns including name or phone, so a title
     * line above it is fine. Rows without a name, phone or NID (a totals
     * line, a blank-looking row) are skipped. Null when no header is found.
     *
     * @param  list<list<mixed>>  $rows
     * @return array{records: list<array>, ignored: list<string>}|null
     */
    private function tableRecords(array $rows): ?array
    {
        $headerIndex = null;
        $columns = [];
        $checked = 0;

        foreach ($rows as $index => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $found = [];

            foreach ($row as $position => $heading) {
                $field = $this->fieldFor($this->cellToString($heading));

                if ($field !== null && $field !== self::SKIP && ! in_array($field, $found, true)) {
                    $found[$position] = $field;
                }
            }

            if (count($found) >= 2 && (in_array('name', $found, true) || in_array('phone', $found, true))) {
                $headerIndex = $index;
                $columns = $found;
                break;
            }

            if (++$checked >= 15) {
                break;
            }
        }

        if ($headerIndex === null) {
            return null;
        }

        $ignored = [];

        foreach ($rows[$headerIndex] as $position => $heading) {
            $label = trim($this->cellToString($heading));

            if ($label !== '' && ! isset($columns[$position]) && $this->fieldFor($label) !== self::SKIP) {
                $ignored[] = $label;
            }
        }

        $records = [];

        foreach (array_slice($rows, $headerIndex + 1, null, true) as $index => $row) {
            $values = [];

            foreach ($columns as $position => $field) {
                $values[$field] = $row[$position] ?? null;
            }

            $identifying = array_filter(
                [$values['name'] ?? null, $values['phone'] ?? null, $values['nid_number'] ?? null],
                fn ($value): bool => trim($this->cellToString($value)) !== '',
            );

            if ($identifying === []) {
                continue;
            }

            $records[] = ['row' => $index + 1, 'values' => $values, 'notes' => []];
        }

        return ['records' => $records, 'ignored' => $ignored];
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
                    ...$this->investmentDefaults,
                    ...$this->draftAttributes($record['values'], $record['notes'], $companyId),
                    'company_id' => $companyId,
                    'batch_id' => $batchId,
                    'source' => $source,
                    'source_name' => ($record['source_name'] ?? $sourceName) ? Str::limit($record['source_name'] ?? $sourceName, 250, '') : null,
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

            if (in_array($field, self::DIGIT_FIELDS, true)) {
                $attributes[$field] = Str::limit((string) preg_replace('/[ \t]+/u', ' ', $this->latinDigits($text)), 255, '');
            } elseif (in_array($field, self::STRING_FIELDS, true)) {
                $attributes[$field] = Str::limit((string) preg_replace('/[ \t]+/u', ' ', $text), 255, '');
            } elseif (in_array($field, self::TEXT_FIELDS, true)) {
                $attributes[$field] = $text;
            } elseif (in_array($field, self::PHONE_FIELDS, true)) {
                // A cell may hold several numbers ("017…, 016…"): the first
                // is used, the rest are kept as a note for the reviewer.
                // A Bangladeshi mobile (01…) is preferred over other numbers.
                $numbers = array_values(array_filter(array_map(fn (string $number): string => $this->normalizePhone(trim($number)), preg_split('/[,;\/|\n]+/u', $text))));
                $mobiles = array_values(array_filter($numbers, fn (string $number): bool => (bool) preg_match('/^01\d{9}$/', $number)));
                $used = $mobiles[0] ?? $numbers[0] ?? $this->normalizePhone($text);
                $attributes[$field] = Str::limit($used, 255, '');

                if (count($numbers) > 1) {
                    $attributes['import_notes'][] = [
                        'message' => $field === 'phone'
                            ? 'More than one phone number was given. :used is used; the others were: :others'
                            : 'More than one nominee phone number was given. :used is used; the others were: :others',
                        'params' => ['used' => $attributes[$field], 'others' => implode(', ', array_diff($numbers, [$used]))],
                    ];
                }
            } elseif (in_array($field, self::NID_FIELDS, true)) {
                $nid = $this->latinDigits($text);
                $attributes[$field] = $nid;

                if (preg_match('/^01\d{9}$/', $nid)) {
                    $attributes['import_notes'][] = ['message' => 'The NID :nid looks like a phone number. Check the NID column.', 'params' => ['nid' => $nid]];
                }

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
            } elseif ($field === 'investment_amount') {
                $amount = (string) preg_replace('/[^\d.]/', '', $this->latinDigits($text));

                if ($amount !== '' && is_numeric($amount) && (float) $amount > 0) {
                    $attributes['investment_amount'] = round((float) $amount, 2);
                } else {
                    $attributes['import_notes'][] = ['message' => 'Investment amount ":value" could not be read. Enter it by hand.', 'params' => ['value' => Str::limit($text, 40)]];
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

        // Someone who already invested before (same NID / passport number —
        // the investor ID, owner's rule) gets this row's investment added to
        // their record instead of a duplicate investor, even if their phone
        // number has changed since.
        $nidHash = InvestorIdentity::hash($attributes['nid_number'] ?? null);

        if ($nidHash !== null) {
            $existing = Investor::query()->where('company_id', $companyId)->where('nid_hash', $nidHash)->first();

            if ($existing) {
                $attributes['existing_investor_id'] = $existing->getKey();
                $attributes['import_notes'][] = ['message' => 'NID matches existing investor :name. Confirming adds the investment to them; their details are not changed.', 'params' => ['name' => $existing->name]];

                if (filled($attributes['phone'] ?? null) && $attributes['phone'] !== $existing->phone) {
                    $attributes['import_notes'][] = ['message' => 'The phone in the file (:new) differs from the saved phone (:old). Update it on the investor if it has changed.', 'params' => ['new' => $attributes['phone'], 'old' => (string) $existing->phone]];
                }
            }
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
     * @return array<string, list<list<mixed>>> rows per sheet name
     */
    private function readSpreadsheet(string $path, string $extension): array
    {
        $reader = $extension === 'ods' ? new OdsReader : new XlsxReader;
        $sheets = [];

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];

                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }

                $sheets[$sheet->getName()] = $rows;
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('The file could not be read. Save it again as .xlsx or .csv and retry.')]);
        } finally {
            $reader->close();
        }

        return $sheets;
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
        $checked = 0;

        foreach (preg_split('/\R/u', $content) as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (++$checked > 15) {
                return null;
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

            if ($best !== null) {
                return $best;
            }
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

            foreach (config('investor_import.skip', []) as $name) {
                $this->aliases[$this->normalizeLabel($name)] ??= self::SKIP;
            }
        }

        return $this->aliases[$this->normalizeLabel($label)] ?? null;
    }

    private function normalizeLabel(string $label): string
    {
        $label = mb_strtolower(str_replace(["\xEF\xBB\xBF", "'", "\u{2019}"], '', $label));

        // Fold common Bangla spelling variants so "নমিনী"/"নমিনি" and
        // "স্টাম্প"/"স্ট্যাম্প" match: long i/u signs -> short, drop ya-phala.
        // Precomposed RRA/RHA/YYA are decomposed first so both encodings of
        // the same letter compare equal.
        $label = str_replace(["\u{09DC}", "\u{09DD}", "\u{09DF}"], ["\u{09A1}\u{09BC}", "\u{09A2}\u{09BC}", "\u{09AF}\u{09BC}"], $label);
        $label = str_replace(["\u{09C0}", "\u{09C2}", "\u{09CD}\u{09AF}"], ["\u{09BF}", "\u{09C1}", ''], $label);

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
