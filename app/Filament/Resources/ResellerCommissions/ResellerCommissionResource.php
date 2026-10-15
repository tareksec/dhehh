<?php

namespace App\Filament\Resources\ResellerCommissions;

use App\Filament\Clusters\Resellers;
use App\Filament\Resources\ResellerCommissions\Pages\ListResellerCommissions;
use App\Filament\Resources\Resellers\ResellerResource;
use App\Models\ResellerCommission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only ledger of reseller commissions. Rows are created and updated
 * automatically from orders by ResellerCommissionService — staff never
 * edit them by hand, they change the order instead.
 */
class ResellerCommissionResource extends Resource
{
    protected static ?string $model = ResellerCommission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $cluster = Resellers::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'reseller-commissions';

    public static function getNavigationLabel(): string
    {
        return __('Reseller profits');
    }

    public static function getModelLabel(): string
    {
        return __('Reseller profit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Reseller profits');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.order_number')->label(__('Order'))->searchable(),
                TextColumn::make('reseller.business_name')
                    ->label(__('Reseller'))
                    ->formatStateUsing(fn ($state, ResellerCommission $record): ?string => $state ?: $record->reseller?->name)
                    ->searchable(),
                TextColumn::make('sales_amount')->label(__('Sales'))->numeric(2)->sortable(),
                TextColumn::make('base_amount')->label(__('Reseller cost'))->numeric(2)->toggleable(),
                TextColumn::make('amount')->label(__('Reseller profit'))->numeric(2)->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => __(ResellerCommission::STATUSES[$state] ?? (string) $state))
                    ->color(fn (?string $state): string => match ($state) {
                        ResellerCommission::STATUS_PAYABLE => 'success',
                        ResellerCommission::STATUS_PENDING => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('payable_at')->label(__('Payable since'))->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('created_at')->label(__('Created'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(fn (): array => collect(ResellerCommission::STATUSES)->map(fn (string $label): string => __($label))->all()),
                SelectFilter::make('customer_id')
                    ->label(__('Reseller'))
                    ->relationship('reseller', 'name')
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResellerCommissions::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (Auth::user()?->canPerformModelAbility('viewAny', ResellerCommission::class) ?? false)
            && ResellerResource::resellerModuleEnabledForCurrentContext();
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
