<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Services\InvestorPortfolio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Page 3 of §6.1: the investor's statement, with a date filter and a
 * printable copy (browser "Save as PDF"; dompdf cannot shape Bangla). Lines
 * from every company the person invests in, with a Project filter
 * (18_IMS_ALL_COMPANIES_PLAN.md §4); the printable copy and the monthly PDFs
 * are the open company's.
 */
class Statement extends Page
{
    use InvestorPortalPage;

    protected static ?string $portalRole = 'investor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.investor.pages.statement';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    /** The Project filter: "<company id>:<project id>", empty = all projects. */
    #[Url]
    public ?string $project = null;

    public static function getNavigationGroup(): ?string
    {
        return __('Overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('Statement');
    }

    public function getTitle(): string
    {
        return __('Statement');
    }

    /** Every company's lines in one list, newest first (18_IMS_ALL_COMPANIES_PLAN.md §4, phase E). */
    public function entries(): Collection
    {
        return app(InvestorPortfolio::class)->statement($this->account(), $this->date($this->from), $this->date($this->until), filled($this->project) ? $this->project : null);
    }

    /** @return array<string, string> */
    public function projectOptions(): array
    {
        return app(InvestorPortfolio::class)->projectOptions($this->account());
    }

    public function showsCompanies(): bool
    {
        return app(InvestorPortfolio::class)->spansCompanies($this->account());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label(__('Print / Save as PDF'))
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (): string => route('filament.investor.reports.statement', array_filter(['from' => $this->date($this->from)?->toDateString(), 'until' => $this->date($this->until)?->toDateString(), 'print' => 1])))
                ->openUrlInNewTab(),
        ];
    }

    protected function date(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
