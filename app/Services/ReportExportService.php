<?php

namespace App\Services;

use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Reports\ReportFormatter;
use App\Reports\ReportRegistry;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the file and print versions of a report from its ReportDefinition.
 */
class ReportExportService
{
    public function __construct(
        protected AuditLogService $auditLogs,
    ) {}

    public function definition(string $type): ReportDefinition
    {
        $definition = ReportRegistry::find($type);

        abort_if($definition === null, 404);

        return $definition;
    }

    /**
     * Everything a file or print view needs.
     *
     * @return array{
     *     filename: string, title: string, headings: array<string>, rows: iterable<array<mixed>>,
     *     date_from: CarbonInterface, date_to: CarbonInterface, uses_dates: bool, as_of: bool,
     *     summary: array<int, array{label: string, value: float, strong: bool, note: ?string}>, summary_title: string,
     *     columns: Collection<int, ReportColumn>, definition: ReportDefinition, filters: ReportFilters
     * }
     */
    public function export(string $type, ?string $dateFrom = null, ?string $dateTo = null, ?int $supplierId = null): array
    {
        [$definition, $filters, $columns] = $this->prepare($type, $dateFrom, $dateTo, $supplierId, forExport: true);

        return [
            'filename' => $definition->filename().'.csv',
            'title' => $definition->title(),
            'headings' => $columns->map(fn (ReportColumn $column): string => $column->label)->all(),
            'rows' => $this->fileRows($definition->rows($filters), $columns, false),
            'date_from' => $filters->from,
            'date_to' => $filters->to,
            'uses_dates' => $definition->usesDateRange(),
            'as_of' => $definition->usesAsOfDate(),
            'summary' => $definition->summary($filters),
            'summary_title' => $definition->summaryTitle(),
            'columns' => $columns,
            'definition' => $definition,
            'filters' => $filters,
        ];
    }

    public function download(string $type, ?string $dateFrom = null, ?string $dateTo = null, ?int $supplierId = null): StreamedResponse
    {
        $export = $this->export($type, $dateFrom, $dateTo, $supplierId);

        return response()->streamDownload(function () use ($export): void {
            $handle = fopen('php://output', 'w');
            // Byte-order mark: Excel then reads the file as UTF-8, so Bangla names stay readable.
            fwrite($handle, "\xEF\xBB\xBF");

            if ($export['summary'] !== []) {
                fputcsv($handle, [$export['summary_title']]);

                foreach ($export['summary'] as $line) {
                    fputcsv($handle, [$line['label'], $line['value'], $line['note'] ?? '']);
                }

                fputcsv($handle, []);
            }

            fputcsv($handle, $export['headings']);

            foreach ($export['rows'] as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $export['filename'], [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function downloadXlsx(string $type, ?string $dateFrom = null, ?string $dateTo = null, ?int $supplierId = null): StreamedResponse
    {
        [$definition, $filters, $columns] = $this->prepare($type, $dateFrom, $dateTo, $supplierId, forExport: true);
        $rows = $this->fileRows($definition->rows($filters), $columns, true);
        $summary = $definition->summary($filters);
        $summaryTitle = $definition->summaryTitle();
        $filename = $definition->filename().'.xlsx';

        return response()->streamDownload(function () use ($columns, $rows, $definition, $summary, $summaryTitle): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName($this->sheetName($definition->title()));

            if ($summary !== []) {
                $bold = (new Style)->setFontBold();
                $writer->addRow(Row::fromValues([$summaryTitle], $bold));

                foreach ($summary as $line) {
                    $writer->addRow(Row::fromValues([$line['label'], $line['value'], $line['note'] ?? ''], $line['strong'] ? $bold : null));
                }

                $writer->addRow(Row::fromValues([]));
            }

            $writer->addRow(Row::fromValues(
                $columns->map(fn (ReportColumn $column): string => $column->label)->all(),
                (new Style)->setFontBold(),
            ));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Rows as a person reads them (formatted money and dates), plus the
     * summed columns, for the printable page.
     *
     * @return array{columns: Collection<int, ReportColumn>, rows: array<int, array<string, string>>, totals: array<string, string>, summary: array<int, array{label: string, value: string, strong: bool, note: ?string}>, summary_title: string}
     */
    public function printable(string $type, ?string $dateFrom = null, ?string $dateTo = null, ?int $supplierId = null): array
    {
        [$definition, $filters, $columns] = $this->prepare($type, $dateFrom, $dateTo, $supplierId, forExport: false);

        $rows = collect($definition->rows($filters))->values();

        if ($sort = $definition->screenSort()) {
            $rows = $sort[1] === 'desc' ? $rows->sortByDesc($sort[0]) : $rows->sortBy($sort[0]);
        }

        return [
            'columns' => $columns,
            'rows' => $rows
                ->map(fn (array $row): array => $columns
                    ->mapWithKeys(fn (ReportColumn $column): array => [
                        $column->key => ReportFormatter::screen($column, $row[$column->key] ?? null),
                    ])
                    ->all())
                ->values()
                ->all(),
            'totals' => $this->totals($columns, $rows),
            'summary' => collect($definition->summary($filters))
                ->map(fn (array $line): array => [...$line, 'value' => ReportFormatter::screen(ReportColumn::money('value', $line['label']), $line['value'])])
                ->all(),
            'summary_title' => $definition->summaryTitle(),
        ];
    }

    /**
     * @param  Collection<int, ReportColumn>  $columns
     * @return array<string, string> label => formatted sum, for the columns marked ->sum()
     */
    public function totals(Collection $columns, iterable $rows): array
    {
        $rows = collect($rows);

        return $columns
            ->filter(fn (ReportColumn $column): bool => $column->summed)
            ->mapWithKeys(fn (ReportColumn $column): array => [
                $column->label => ReportFormatter::screen(
                    $column,
                    $rows->sum(fn (array $row): float => (float) ($row[$column->key] ?? 0)),
                ),
            ])
            ->all();
    }

    public function audit(Request $request, string $format, string $type): void
    {
        $this->auditLogs->record('report_exported', self::class, null, [
            'format' => $format,
            'type' => $type,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'supplier_id' => $request->query('supplier_id'),
        ], $request);
    }

    /**
     * @return array{0: ReportDefinition, 1: ReportFilters, 2: Collection<int, ReportColumn>}
     */
    private function prepare(string $type, ?string $dateFrom, ?string $dateTo, ?int $supplierId, bool $forExport): array
    {
        $definition = $this->definition($type);
        $filters = ReportFilters::forReport($definition, $dateFrom, $dateTo, $supplierId);

        // A report about one supplier means nothing without one: refuse instead of writing an empty file.
        abort_if($definition->requiresSupplier() && $definition->notice($filters) !== null, 422, (string) $definition->notice($filters));

        $columns = collect($definition->columns($filters))
            ->filter(fn (ReportColumn $column): bool => $forExport ? $column->inExport : $column->onScreen)
            ->values();

        return [$definition, $filters, $columns];
    }

    /**
     * @param  Collection<int, ReportColumn>  $columns
     * @return iterable<array<mixed>>
     */
    private function fileRows(iterable $rows, Collection $columns, bool $spreadsheet): iterable
    {
        foreach ($rows as $row) {
            yield $columns
                ->map(fn (ReportColumn $column): mixed => ReportFormatter::file($column, $row[$column->key] ?? null, $spreadsheet))
                ->all();
        }
    }

    private function sheetName(string $title): string
    {
        $name = trim(preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', ' ', $title) ?? '');

        return mb_substr($name !== '' ? $name : 'Report', 0, 31);
    }
}
