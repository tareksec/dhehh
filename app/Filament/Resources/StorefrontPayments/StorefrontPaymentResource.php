<?php

namespace App\Filament\Resources\StorefrontPayments;

use App\Filament\Clusters\Storefront;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\StorefrontPayments\Pages\ListStorefrontPayments;
use App\Models\StorefrontPayment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class StorefrontPaymentResource extends Resource
{
    protected static ?string $model = StorefrontPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $cluster = Storefront::class;

    protected static ?string $navigationLabel = 'Payments';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'invoice_id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Awaiting verified payment'),
                TextColumn::make('purpose')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        StorefrontPayment::PURPOSE_CHECKOUT_ADVANCE => 'Checkout advance',
                        StorefrontPayment::PURPOSE_NEW_CUSTOMER_DELIVERY => 'New customer delivery',
                        StorefrontPayment::PURPOSE_PREORDER_ADVANCE => 'Pre-order advance',
                        StorefrontPayment::PURPOSE_RESELLER_ADVANCE => __('Reseller advance (own courier)'),
                        default => 'Order payment',
                    }),
                TextColumn::make('gateway')
                    ->badge()
                    ->formatStateUsing(function (string $state, StorefrontPayment $record): string {
                        // 'manual_bkash'/'manual_nagad' are legacy literal
                        // gateway values from before payment methods became
                        // dashboard-managed (StorefrontPaymentMethod) — kept
                        // here so historical rows still display correctly.
                        // New manual payments use the generic 'manual'
                        // gateway with the channel's own name via the
                        // paymentMethod relation.
                        return match ($state) {
                            'manual' => $record->paymentMethod?->name ?? 'Manual',
                            'manual_bkash' => 'bKash (Manual)',
                            'manual_nagad' => 'Nagad (Manual)',
                            'zinipay' => 'ZiniPay',
                            'paystation' => 'PayStation',
                            default => ucfirst($state),
                        };
                    }),
                TextColumn::make('amount')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Method / sender'),
                TextColumn::make('transaction_id')
                    ->label('Transaction ID')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(StorefrontPayment::STATUSES),
                SelectFilter::make('gateway')->options([
                    'manual' => 'Manual',
                    'manual_bkash' => 'bKash (Manual, legacy)',
                    'manual_nagad' => 'Nagad (Manual, legacy)',
                    'zinipay' => 'ZiniPay',
                    'paystation' => 'PayStation',
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                // A reseller's advance is checked on the order itself, where
                // verifying also records it in the order's Payments History.
                Action::make('openResellerOrder')
                    ->label(__('Check on order'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->visible(fn (StorefrontPayment $record): bool => $record->purpose === StorefrontPayment::PURPOSE_RESELLER_ADVANCE && $record->order_id !== null)
                    ->url(fn (StorefrontPayment $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),
                Action::make('verify')
                    ->label('Verify')
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (StorefrontPayment $record): bool => $record->status === StorefrontPayment::STATUS_PENDING
                        && $record->purpose !== StorefrontPayment::PURPOSE_RESELLER_ADVANCE
                        && in_array($record->gateway, ['manual', 'manual_bkash', 'manual_nagad'], true))
                    ->requiresConfirmation()
                    ->action(function (StorefrontPayment $record): void {
                        $record->update(['status' => StorefrontPayment::STATUS_COMPLETED]);
                        Notification::make()->title('Payment verified.')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXMark)
                    ->visible(fn (StorefrontPayment $record): bool => $record->status === StorefrontPayment::STATUS_PENDING
                        && $record->purpose !== StorefrontPayment::PURPOSE_RESELLER_ADVANCE
                        && in_array($record->gateway, ['manual', 'manual_bkash', 'manual_nagad'], true))
                    ->requiresConfirmation()
                    ->action(function (StorefrontPayment $record): void {
                        $record->update(['status' => StorefrontPayment::STATUS_FAILED]);
                        Notification::make()->title('Payment rejected.')->warning()->send();
                    }),
            ]);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStorefrontPayments::route('/'),
        ];
    }
}
