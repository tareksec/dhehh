<?php

namespace App\Filament\Resources\ResellerDeposits;

use App\Filament\Clusters\Resellers;
use App\Filament\Resources\ResellerDeposits\Pages\ListResellerDeposits;
use App\Filament\Resources\Resellers\ResellerResource;
use App\Models\ResellerDepositEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Every movement of every reseller's deposit (owner decision 2026-10-12):
 * what they sent above an order's total, what orders used, what a cancelled
 * order returned, and the adjustments staff make by hand (with the reason
 * and who did it). Rows are never edited or deleted -- a correction is a
 * new adjustment -- and the page's Add / Deduct buttons are the only way
 * staff change a deposit (ResellerDepositService).
 */
class ResellerDepositResource extends Resource
{
    protected static ?string $model = ResellerDepositEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $cluster = Resellers::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'reseller-deposits';

    public static function getNavigationLabel(): string
    {
        return __('Deposits');
    }

    public static function getModelLabel(): string
    {
        return __('Deposit entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Deposit entries');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('reseller.business_name')
                    ->label(__('Reseller'))
                    ->formatStateUsing(fn ($state, ResellerDepositEntry $record): ?string => $state ?: $record->reseller?->name)
                    ->description(fn (ResellerDepositEntry $record): ?string => $record->reseller?->phone)
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => __(ResellerDepositEntry::TYPES[$state] ?? (string) $state))
                    ->color(fn (?string $state): string => match ($state) {
                        ResellerDepositEntry::TYPE_USED => 'warning',
                        ResellerDepositEntry::TYPE_ADJUSTMENT => 'info',
                        default => 'success',
                    }),
                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->numeric(2)
                    ->color(fn (ResellerDepositEntry $record): string => (float) $record->amount < 0 ? 'danger' : 'success')
                    ->sortable(),
                TextColumn::make('order.order_number')->label(__('Order'))->placeholder('-')->searchable(),
                TextColumn::make('note')->label(__('Reason / note'))->placeholder('-')->wrap()->searchable(),
                TextColumn::make('createdBy.name')->label(__('Done by'))->placeholder(__('Automatic')),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label(__('Reseller'))
                    ->relationship('reseller', 'name')
                    ->searchable(),
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options(fn (): array => collect(ResellerDepositEntry::TYPES)->map(fn (string $label): string => __($label))->all()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResellerDeposits::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (Auth::user()?->canPerformModelAbility('viewAny', ResellerDepositEntry::class) ?? false)
            && ResellerResource::resellerModuleEnabledForCurrentContext();
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
