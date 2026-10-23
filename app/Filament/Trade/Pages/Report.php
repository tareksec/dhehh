<?php

namespace App\Filament\Trade\Pages;

use App\Services\TradeReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * One report on screen (TradeReportService): the period to show (a day, a
 * month, from–to, or as of today) and the table — cards on a phone — with
 * PDF and Excel buttons for the same period. Needs "trade.reports".
 */
class Report extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'report';

    protected string $view = 'filament.trade.pages.report';

    #[Url]
    public string $type = 'daily_sales';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.reports') ?? false;
    }

    public function mount(): void
    {
        abort_unless(array_key_exists($this->type, TradeReportService::REPORTS), 404);

        $kind = $this->kind();
        $this->from = $this->from ?: match ($kind) {
            'range' => now()->startOfMonth()->toDateString(),
            'month' => now()->format('Y-m'),
            default => now()->toDateString(),
        };
        $this->to = $this->to ?: now()->toDateString();
    }

    public function kind(): string
    {
        return TradeReportService::REPORTS[$this->type][1] ?? 'none';
    }

    public function getTitle(): string
    {
        return __(TradeReportService::REPORTS[$this->type][0] ?? 'Reports');
    }

    /** The dates handed to the service: a month picker gives "2026-10". */
    protected function fromDate(): string
    {
        return $this->kind() === 'month' && preg_match('/^\d{4}-\d{2}$/', $this->from) ? $this->from.'-01' : $this->from;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $query = ['from' => $this->fromDate(), 'to' => $this->to];

        return [
            'report' => app(TradeReportService::class)->build($this->type, $this->fromDate(), $this->to),
            'kind' => $this->kind(),
            'printUrl' => route('filament.trade.reports.download', ['report' => $this->type, 'format' => 'print', ...$query]),
            'pdfUrl' => route('filament.trade.reports.download', ['report' => $this->type, 'format' => 'pdf', ...$query]),
            'excelUrl' => route('filament.trade.reports.download', ['report' => $this->type, 'format' => 'xlsx', ...$query]),
            'backUrl' => Reports::getUrl(panel: 'trade'),
        ];
    }
}
