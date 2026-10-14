<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Concerns\ForApprovedResellers;
use App\Models\ResellerCommission;
use App\Support\MoneyFormatter;
use BackedEnum;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reseller portal Phase 5 (13_RESELLER_PORTAL_PLAN.md §3 row 4): the
 * reseller's full commission history -- one row per order -- with status and
 * date filters, plus monthly totals. Read-only: commission rows are only ever
 * written by ResellerCommissionService when the order changes.
 */
class Earnings extends Page implements HasTable
{
    use ForApprovedResellers;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.reseller.pages.earnings';

    public function getTitle(): string
    {
        return __('Earnings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Earnings');
    }

    /**
     * Last 12 months that have any (non-cancelled) commission, newest first.
     * A cancelled/returned order earns nothing, so it isn't counted here.
     *
     * @return list<array{month: string, orders: int, pending: string, payable: string}>
     */
    public function monthlyTotals(): array
    {
        $currency = $this->currency();
        $pending = ResellerCommission::STATUS_PENDING;
        $payable = ResellerCommission::STATUS_PAYABLE;

        return ResellerCommission::query()
            ->where('customer_id', $this->reseller()->getKey())
            ->where('status', '!=', ResellerCommission::STATUS_CANCELLED)
            ->selectRaw(ResellerCommission::monthExpression().' as period, count(*) as orders, '
                ."sum(case when status = '{$pending}' then amount else 0 end) as pending_total, "
                ."sum(case when status = '{$payable}' then amount else 0 end) as payable_total")
            ->groupBy('period')
            ->orderByDesc('period')
            ->limit(12)
            ->get()
            ->map(fn (ResellerCommission $row): array => [
                // "!" resets the day to 1, so "2026-02" never overflows into March.
                'month' => Carbon::createFromFormat('!Y-m', $row->getAttribute('period'))->translatedFormat('F Y'),
                'orders' => (int) $row->getAttribute('orders'),
                'pending' => MoneyFormatter::currency((float) $row->getAttribute('pending_total'), $currency),
                'payable' => MoneyFormatter::currency((float) $row->getAttribute('payable_total'), $currency),
            ])
            ->all();
    }

    public function table(Table $table): Table
    {
        $currency = $this->currency();

        return $table
            ->query(fn (): Builder => ResellerCommission::query()
                ->where('customer_id', $this->reseller()->getKey())
                ->with('order'))
            ->columns([
                TextColumn::make('order.order_number')
                    ->label(__('Order'))
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label(__('Date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('sales_amount')
                    ->label(__('Sale amount'))
                    ->state(fn (ResellerCommission $record): string => MoneyFormatter::currency($record->sales_amount, $currency)),

                TextColumn::make('base_amount')
                    ->label(__('Your cost'))
                    ->state(fn (ResellerCommission $record): string => MoneyFormatter::currency($record->base_amount, $currency))
                    ->toggleable(),

                TextColumn::make('amount')
                    ->label(__('Profit'))
                    ->state(fn (ResellerCommission $record): string => MoneyFormatter::currency($record->amount, $currency))
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __(ResellerCommission::STATUSES[$state] ?? $state))
                    ->color(fn (string $state): string => match ($state) {
                        ResellerCommission::STATUS_PAYABLE => 'success',
                        ResellerCommission::STATUS_CANCELLED => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('payable_at')
                    ->label(__('Payable since'))
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(collect(ResellerCommission::STATUSES)->map(fn (string $label): string => __($label))->all()),

                Filter::make('created_at')
                    ->label(__('Date range'))
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->searchPlaceholder(__('Search order number...'))
            ->defaultSort('created_at', 'desc');
    }
}
