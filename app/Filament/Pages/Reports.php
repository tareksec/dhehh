<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Reports as ReportsCluster;
use App\Models\Supplier;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportFilters;
use App\Reports\ReportFormatter;
use App\Reports\ReportRegistry;
use App\Services\ReportExportService;
use App\Services\ReportService;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

class Reports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $cluster = ReportsCluster::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Reports';

    protected string $view = 'filament.pages.reports';

    public static function canAccess(): bool
    {
        return Auth::user()?->canAccessReports() ?? false;
    }

    public string $dateFrom;

    public string $dateTo;

    public string $reportType = 'sales';

    public ?int $supplierId = null;

    public array $summary = [];

    /** @var array<int, array<string, mixed>>|null rows of the active report, built once per request */
    private ?array $rowsCache = null;

    private ?ReportDefinition $definitionCache = null;

    public function getTitle(): string|Htmlable
    {
        return __('Reports');
    }

    public function mount(ReportService $reports): void
    {
        $this->dateFrom = (string) request('date_from', now()->toDateString());
        $this->dateTo = (string) request('date_to', now()->toDateString());
        $this->reportType = (string) request('report_type', 'sales');
        $this->supplierId = request()->integer('supplier_id') ?: null;

        if (ReportRegistry::find($this->reportType) === null) {
            $this->reportType = 'sales';
        }

        $this->summary = $reports->dashboardSummary();
    }

    public function definition(): ReportDefinition
    {
        // One instance per request, so a report that builds something heavy (a supplier statement) builds it once.
        return $this->definitionCache ??= ReportRegistry::find($this->reportType) ?? ReportRegistry::find('sales');
    }

    public function filters(): ReportFilters
    {
        return ReportFilters::forReport($this->definition(), $this->dateFrom, $this->dateTo, $this->supplierId);
    }

    /**
     * The account block of the active report (empty for plain table reports).
     *
     * @return array<int, array{label: string, value: float, strong: bool, note: ?string}>
     */
    public function summaryLines(): array
    {
        return $this->definition()->summary($this->filters());
    }

    public function summaryTitle(): string
    {
        return $this->definition()->summaryTitle();
    }

    public function notice(): ?string
    {
        return $this->definition()->notice($this->filters());
    }

    /**
     * @return array<string, string> report key => title
     */
    public function reportOptions(): array
    {
        return collect(ReportRegistry::all())
            ->map(fn (ReportDefinition $report): string => $report->title())
            ->all();
    }

    /**
     * @return array<string, array<string>> group => report keys
     */
    public function reportGroups(): array
    {
        return ReportRegistry::groups();
    }

    public function reportIcon(string $type): string
    {
        return ReportRegistry::find($type)?->icon() ?? 'heroicon-o-document-text';
    }

    /**
     * @return array<string, string> supplier id => name
     */
    public function supplierOptions(): array
    {
        return Supplier::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, array{0: string, 1: string}> label => [from, to]
     */
    public function datePresets(): array
    {
        $lastMonth = now()->subMonthNoOverflow();

        return [
            __('Today') => [now()->toDateString(), now()->toDateString()],
            __('Last 7 days') => [now()->subDays(6)->toDateString(), now()->toDateString()],
            __('This month') => [now()->startOfMonth()->toDateString(), now()->toDateString()],
            __('Last month') => [$lastMonth->startOfMonth()->toDateString(), $lastMonth->endOfMonth()->toDateString()],
        ];
    }

    public function presetUrl(string $from, string $to): string
    {
        return static::getUrl($this->urlParameters(['date_from' => $from, 'date_to' => $to]));
    }

    public function reportUrl(string $type): string
    {
        return static::getUrl($this->urlParameters(['report_type' => $type]));
    }

    public function activeReportTitle(): string
    {
        return $this->definition()->title();
    }

    public function activeReportDescription(): string
    {
        return $this->definition()->description();
    }

    public function activeReportCount(): int
    {
        return count($this->rows());
    }

    /**
     * Sums of the columns marked ->sum(), already formatted.
     *
     * @return array<string, string> label => total
     */
    public function totals(): array
    {
        return app(ReportExportService::class)->totals(
            collect($this->screenColumns()),
            $this->rows(),
        );
    }

    public function exportUrl(string $format): string
    {
        $route = match ($format) {
            'xlsx' => 'reports.export.xlsx',
            'pdf' => 'reports.export.pdf',
            'print' => 'reports.print',
            default => 'reports.export',
        };

        return route($route, array_filter([
            'type' => $this->reportType,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'supplier_id' => $this->supplierId,
            'print' => $format === 'print' ? 1 : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    public function money(float|int|string|null $amount): string
    {
        return MoneyFormatter::currency($amount);
    }

    public function table(Table $table): Table
    {
        $canExport = fn (): bool => (bool) Auth::user()?->canExportReports() && $this->notice() === null;

        return $table
            ->heading($this->activeReportTitle())
            ->description($this->activeReportDescription())
            ->records(fn (): array => $this->tableRecords())
            ->columns($this->tableColumns())
            ->headerActions([
                Action::make('exportCsv')
                    ->label(__('CSV'))
                    ->icon(Heroicon::ArrowDownTray)
                    ->color('gray')
                    ->visible($canExport)
                    ->url($this->exportUrl('csv')),
                Action::make('exportXlsx')
                    ->label(__('Excel'))
                    ->icon(Heroicon::TableCells)
                    ->color('gray')
                    ->visible($canExport)
                    ->url($this->exportUrl('xlsx')),
                Action::make('exportPrint')
                    ->label(__('Print'))
                    ->icon(Heroicon::Printer)
                    ->color('gray')
                    ->visible($canExport)
                    ->url($this->exportUrl('print'), shouldOpenInNewTab: true),
                Action::make('exportPdf')
                    ->label(__('PDF'))
                    ->icon(Heroicon::DocumentArrowDown)
                    ->visible($canExport)
                    ->url($this->exportUrl('pdf')),
            ])
            ->emptyStateHeading($this->definition()->emptyHeading())
            ->emptyStateDescription(__('Try a different report type or date range.'))
            ->emptyStateIcon($this->reportIcon($this->reportType))
            ->paginated(false)
            ->striped();
    }

    /**
     * @return array<ReportColumn>
     */
    private function screenColumns(): array
    {
        return collect($this->definition()->columns($this->filters()))
            ->filter(fn (ReportColumn $column): bool => $column->onScreen)
            ->values()
            ->all();
    }

    /**
     * @return array<TextColumn>
     */
    private function tableColumns(): array
    {
        return collect($this->screenColumns())
            ->map(function (ReportColumn $report): TextColumn {
                $column = TextColumn::make($report->key)
                    ->label($report->label)
                    ->placeholder(__('Not set'))
                    ->formatStateUsing(fn (mixed $state): string => ReportFormatter::screen($report, $state));

                if ($report->isNumeric()) {
                    $column->alignEnd();
                }

                if ($report->wrap) {
                    $column->wrap();
                }

                if ($report->copyable) {
                    $column->copyable();
                }

                if ($report->link) {
                    $column->url(fn (array $record): ?string => ($report->link)($record, $this->filters()));
                }

                if ($report->type === ReportColumn::BADGE) {
                    $column->badge()->color(fn (mixed $state): string => $report->colors[(string) $state] ?? $report->badgeColor);
                }

                return $column;
            })
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function tableRecords(): array
    {
        return collect($this->rows())
            ->mapWithKeys(fn (array $row, int $index): array => ["row-{$index}" => $row])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        if ($this->rowsCache !== null) {
            return $this->rowsCache;
        }

        $definition = $this->definition();
        $rows = collect($definition->rows($this->filters()))->values();

        if ($sort = $definition->screenSort()) {
            $rows = ($sort[1] === 'desc' ? $rows->sortByDesc($sort[0]) : $rows->sortBy($sort[0]))->values();
        }

        return $this->rowsCache = $rows->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function urlParameters(array $overrides): array
    {
        return array_filter([
            'report_type' => $this->reportType,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'supplier_id' => $this->supplierId,
            ...$overrides,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
