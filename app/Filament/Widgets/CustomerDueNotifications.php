<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerDueAlertService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class CustomerDueNotifications extends TableWidget
{
    use GuardsWidgetRendering;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return (Auth::user()?->hasPermission('sales.view') || Auth::user()?->hasPermission('accounts.view')) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Customer Due Notifications')
            ->description(fn (): string => app(CustomerDueAlertService::class)->message())
            ->query(fn (): Builder => app(CustomerDueAlertService::class)
                ->query()
                ->orderByDesc('current_balance')
                ->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->label('Phone')
                    ->placeholder('-')
                    ->searchable(),

                TextColumn::make('customer_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => Customer::typeLabel($state))
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('current_balance')
                    ->label('Due Amount')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->badge()
                    ->color('warning')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('viewCustomer')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Customer $record): string => CustomerResource::getUrl('view', ['record' => $record])),

                Action::make('editCustomer')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Customer $record): string => CustomerResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => Auth::user()?->canPerformModelAbility('update', Customer::class) ?? false),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
