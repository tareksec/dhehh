<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Resellers;
use App\Filament\Resources\Resellers\ResellerResource;
use App\Models\ResellerCommission;
use App\Services\ResellerSalesReportService;
use App\Support\MoneyFormatter;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Who sold what (owner request 2026-10-16): one row per reseller for a chosen
 * period, with orders, sales, the reseller's cost and profit, what is payable
 * and what has been paid out. The figures come from ResellerSalesReportService;
 * this page only picks the period and shows them. Read-only.
 */
class ResellerSalesReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $cluster = Resellers::class;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'reseller-sales-report';

    protected string $view = 'filament.pages.reseller-sales-report';

    public static function getNavigationLabel(): string
    {
        return __('Reseller sales report');
    }

    public function getTitle(): string
    {
        return __('Reseller sales report');
    }

    public static function canAccess(): bool
    {
        return (Auth::user()?->canPerformModelAbility('viewAny', ResellerCommission::class) ?? false)
            && ResellerResource::resellerModuleEnabledForCurrentContext();
    }

    /** @return array<string, string> */
    public static function periods(): array
    {
        return [
            'this_month' => __('This month'),
            'last_month' => __('Last month'),
            'last_7_days' => __('Last 7 days'),
            'last_30_days' => __('Last 30 days'),
            'this_year' => __('This year'),
            'all_time' => __('All time'),
        ];
    }

    /**
     * The date range being shown: the custom dates when either is filled,
     * otherwise the chosen period (this month by default).
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function range(): array
    {
        $filters = $this->tableFilters ?? [];
        $from = $filters['dates']['from'] ?? null;
        $until = $filters['dates']['until'] ?? null;

        if (filled($from) || filled($until)) {
            return [
                filled($from) ? now()->parse($from)->startOfDay() : now()->parse('2000-01-01'),
                filled($until) ? now()->parse($until)->endOfDay() : now()->endOfDay(),
            ];
        }

        $today = now()->endOfDay();

        return match ($filters['period']['value'] ?? 'this_month') {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last_7_days' => [now()->subDays(6)->startOfDay(), $today],
            'last_30_days' => [now()->subDays(29)->startOfDay(), $today],
            'this_year' => [now()->startOfYear(), $today],
            'all_time' => [now()->parse('2000-01-01'), $today],
            default => [now()->startOfMonth(), $today],
        };
    }

    /** @return Collection<int, array<string, mixed>> */
    public function rows(): Collection
    {
        [$from, $to] = $this->range();

        return app(ResellerSalesReportService::class)->rows($from, $to);
    }

    /** @return array<string, int|float> */
    public function totals(): array
    {
        return app(ResellerSalesReportService::class)->totals($this->rows());
    }

    public function money(float|int|string|null $amount): string
    {
        return MoneyFormatter::currency($amount);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $sortColumn, ?string $sortDirection): array {
                $rows = $this->rows();

                if (filled($sortColumn) && $rows->isNotEmpty() && array_key_exists($sortColumn, $rows->first())) {
                    $rows = $rows->sortBy($sortColumn, SORT_REGULAR, $sortDirection === 'desc');
                }

                return $rows->mapWithKeys(fn (array $row): array => ['reseller-'.$row['id'] => $row])->all();
            })
            ->columns([
                TextColumn::make('name')
                    ->label(__('Reseller'))
                    ->weight('medium')
                    ->sortable()
                    ->url(fn (array $record): string => ResellerResource::getUrl('view', ['record' => $record['id']])),
                TextColumn::make('orders')->label(__('Orders'))->numeric()->alignEnd()->sortable(),
                TextColumn::make('cancelled')->label(__('Cancelled'))->numeric()->alignEnd()->sortable()->toggleable(),
                TextColumn::make('returned')->label(__('Returned'))->numeric()->alignEnd()->sortable()->toggleable(),
                $this->moneyColumn('sales', __('Sales')),
                $this->moneyColumn('cost', __('Reseller cost'))->toggleable(),
                $this->moneyColumn('profit', __('Reseller profit')),
                $this->moneyColumn('payable', __('Payable'))->toggleable(),
                $this->moneyColumn('pending', __('Pending delivery'))->toggleable(),
                $this->moneyColumn('paid_out', __('Paid out'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('period')
                    ->label(__('Period'))
                    ->options(static::periods())
                    ->default('this_month')
                    ->selectablePlaceholder(false),
                Filter::make('dates')
                    ->label(__('Custom dates'))
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->columns(2),
            ])
            ->deferFilters(false)
            ->emptyStateHeading(__('No reseller activity in this period.'))
            ->emptyStateDescription(__('Try a longer period or different dates.'))
            ->emptyStateIcon(Heroicon::OutlinedChartBar)
            ->paginated(false)
            ->striped();
    }

    private function moneyColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->formatStateUsing(fn (float|int|string|null $state): string => $this->money($state))
            ->alignEnd()
            ->sortable();
    }
}
