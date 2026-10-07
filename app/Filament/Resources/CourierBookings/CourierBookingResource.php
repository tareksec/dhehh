<?php

namespace App\Filament\Resources\CourierBookings;

use App\Filament\Clusters\Courier;
use App\Filament\Resources\CourierBookings\Pages\ListCourierBookings;
use App\Filament\Resources\CourierBookings\Pages\ViewCourierBooking;
use App\Filament\Resources\Orders\Actions\CourierBookingFeedback;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Services\CourierManager;
use App\Services\CourierService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Validation\ValidationException;

class CourierBookingResource extends Resource
{
    protected static ?string $model = CourierBooking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $cluster = Courier::class;

    protected static ?string $navigationLabel = 'Bookings';

    protected static ?int $navigationSort = 2;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'tracking_id';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['order.customer', 'provider']))
            ->columns([
                TextColumn::make('tracking_id')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider_reference')
                    ->label('Consignment ID')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('order.order_number')
                    ->label('Invoice')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('recipient_name')
                    ->searchable(),
                TextColumn::make('provider.name')
                    ->label('Provider'),
                TextColumn::make('cod_amount')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->sortable(),
                TextColumn::make('delivery_fee_charged')
                    ->label('Delivery Fee')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('delivery_cost')
                    ->label('Courier Cost')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cod_charge_amount')
                    ->label('COD Charge')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('margin')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->placeholder('-')
                    ->color(fn (?string $state): ?string => $state === null ? null : ((float) $state >= 0 ? 'success' : 'danger'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CourierBooking::STATUSES[$state ?? ''] ?? str($state)->headline()->toString())
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('booked_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(CourierBooking::STATUSES)
                    ->multiple(),
                SelectFilter::make('courier_provider_id')
                    ->label('Provider')
                    ->relationship('provider', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::statusAction(),
                self::syncSteadfastAction(),
                self::requestReturnAction(),
                self::trackAction(),
                self::labelAction(),
                self::cancelAction(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Booking')
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('tracking_id'),
                    TextEntry::make('provider_reference')->label('Consignment ID'),
                    TextEntry::make('provider.name')->label('Provider'),
                    TextEntry::make('order.order_number')->label('Invoice'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => CourierBooking::STATUSES[$state ?? ''] ?? str($state)->headline()->toString()),
                    TextEntry::make('recipient_name'),
                    TextEntry::make('recipient_phone'),
                    TextEntry::make('recipient_address'),
                    TextEntry::make('cod_amount')->moneyWithoutTrailingZeroes('BDT'),
                ])
                ->columns(2),

            Section::make('Delivery Economics')
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('delivery_fee_charged')->label('Delivery Fee')->moneyWithoutTrailingZeroes('BDT')->placeholder('Not configured'),
                    TextEntry::make('delivery_cost')->label('Courier Cost')->moneyWithoutTrailingZeroes('BDT')->placeholder('Not configured'),
                    TextEntry::make('cod_charge_amount')->label('COD Charge')->moneyWithoutTrailingZeroes('BDT')->placeholder('Not configured'),
                    TextEntry::make('margin')
                        ->moneyWithoutTrailingZeroes('BDT')
                        ->placeholder('Not configured')
                        ->color(fn (?string $state): ?string => $state === null ? null : ((float) $state >= 0 ? 'success' : 'danger')),
                ])
                ->columns(4)
                ->collapsible(),

            Section::make('Status Logs')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('statusLogs')
                        ->label('')
                        ->schema([
                            TextEntry::make('created_at')->dateTime(),
                            TextEntry::make('from_status')
                                ->placeholder('New')
                                ->formatStateUsing(fn (?string $state): string => CourierBooking::STATUSES[$state ?? ''] ?? str($state)->headline()->toString()),
                            TextEntry::make('to_status')
                                ->badge()
                                ->formatStateUsing(fn (?string $state): string => CourierBooking::STATUSES[$state ?? ''] ?? str($state)->headline()->toString()),
                            TextEntry::make('note')->placeholder('-'),
                        ])
                        ->columns(4)
                        ->contained(false),
                ]),
        ]);
    }

    public static function canViewAny(): bool
    {
        return SchemaFacade::hasTable('courier_bookings') && (Auth::user()?->hasPermission('sales.view') ?? false);
    }

    public static function canView(Model $record): bool
    {
        return SchemaFacade::hasTable('courier_bookings') && (Auth::user()?->hasPermission('sales.view') ?? false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourierBookings::route('/'),
            'view' => ViewCourierBooking::route('/{record}'),
        ];
    }

    public static function statusAction(): Action
    {
        return Action::make('updateStatus')
            ->label('Update status')
            ->icon('heroicon-o-arrow-path')
            ->schema([
                Select::make('status')
                    ->options(CourierBooking::STATUSES)
                    ->required()
                    ->native(false),
                Textarea::make('note')
                    ->rows(3),
            ])
            ->action(function (Action $action, CourierBooking $record, array $data): void {
                CourierBookingFeedback::run($action, fn () => app(CourierService::class)->updateStatus($record, $data['status'], $data['note'] ?? null), __('Courier status updated'));
            });
    }

    public static function syncSteadfastAction(): Action
    {
        return Action::make('syncSteadfast')
            ->label('Sync courier status')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (CourierBooking $record): bool => in_array($record->provider?->driver, CourierProvider::API_DRIVERS, true))
            ->action(function (Action $action, CourierBooking $record): void {
                CourierBookingFeedback::run($action, fn () => app(CourierManager::class)->sync($record), __('Courier status synced'));
            });
    }

    public static function requestReturnAction(): Action
    {
        return Action::make('requestReturn')
            ->label('Request return')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->schema([
                Textarea::make('reason')
                    ->rows(3)
                    ->helperText('Optional — why this parcel is being returned.'),
            ])
            ->visible(fn (CourierBooking $record): bool => $record->provider?->driver === CourierProvider::DRIVER_STEADFAST
                && ! in_array($record->status, [CourierBooking::STATUS_RETURNED, CourierBooking::STATUS_CANCELLED], true))
            ->action(function (CourierBooking $record, array $data): void {
                try {
                    app(CourierService::class)->requestReturn($record, $data);

                    Notification::make()
                        ->title('Return requested')
                        ->success()
                        ->send();
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Return request failed')
                        ->body($exception instanceof ValidationException
                            ? collect($exception->errors())->flatten()->implode(' ')
                            : $exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancelBooking')
            ->label('Cancel booking')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            // No courier offers a cancel call here: this only marks the booking cancelled in the ERP.
            ->modalDescription(__('This marks the booking cancelled in this app only — it does not cancel the parcel at the courier. Cancel it in the courier\'s own panel too.'))
            ->visible(fn (CourierBooking $record): bool => ! in_array($record->status, [CourierBooking::STATUS_DELIVERED, CourierBooking::STATUS_RETURNED, CourierBooking::STATUS_CANCELLED], true))
            ->action(function (Action $action, CourierBooking $record): void {
                CourierBookingFeedback::run($action, fn () => app(CourierManager::class)->cancel($record), __('Booking marked cancelled'));
            });
    }

    public static function trackAction(): Action
    {
        return Action::make('track')
            ->icon('heroicon-o-map-pin')
            ->url(fn (CourierBooking $record): ?string => app(CourierManager::class)->adapter($record->provider)->trackingUrl($record))
            ->openUrlInNewTab()
            ->visible(fn (CourierBooking $record): bool => filled(app(CourierManager::class)->adapter($record->provider)->trackingUrl($record)));
    }

    public static function labelAction(): Action
    {
        return Action::make('printLabel')
            ->label('Print label')
            ->icon('heroicon-o-printer')
            ->url(fn (CourierBooking $record): ?string => app(CourierManager::class)->adapter($record->provider)->labelUrl($record))
            ->openUrlInNewTab()
            ->visible(fn (CourierBooking $record): bool => filled(app(CourierManager::class)->adapter($record->provider)->labelUrl($record)));
    }

    protected static function statusColor(?string $status): string
    {
        return match ($status) {
            CourierBooking::STATUS_DELIVERED => 'success',
            CourierBooking::STATUS_RETURNED, CourierBooking::STATUS_CANCELLED, CourierBooking::STATUS_FAILED => 'danger',
            CourierBooking::STATUS_BOOKED, CourierBooking::STATUS_PICKED_UP, CourierBooking::STATUS_IN_TRANSIT => 'warning',
            default => 'gray',
        };
    }
}
