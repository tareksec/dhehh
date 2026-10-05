<?php

namespace App\Filament\Reseller\Pages;

use App\Models\Customer;
use App\Models\Order;
use App\Models\ResellerCommission;
use App\Models\StorefrontPaymentMethod;
use App\Services\ResellerOwnCourierService;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
 * Reseller portal Phase 3 (13_RESELLER_PORTAL_PLAN.md §3 row 2): every order
 * credited to this reseller (Customer::resellerOrders()), read-only. The
 * owner confirmed full buyer info (name, phone, address) is shown here --
 * not masked -- so this page reads straight off Order::customer, same as
 * the admin's own order list.
 */
class Orders extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected string $view = 'filament.reseller.pages.orders';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('Orders');
    }

    public static function canAccess(array $parameters = []): bool
    {
        $reseller = Filament::auth()->user();

        return $reseller instanceof Customer && $reseller->isApprovedReseller();
    }

    public function getTitle(): string
    {
        return __('Orders');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createOrder')
                ->label(__('Create order'))
                ->icon(Heroicon::OutlinedPlusCircle)
                ->url(CreateOrder::getUrl()),
        ];
    }

    public function reseller(): Customer
    {
        /** @var Customer $customer */
        $customer = Filament::auth()->user();

        return $customer;
    }

    public function table(Table $table): Table
    {
        $currency = $this->reseller()->company?->currency ?: 'BDT';

        return $table
            ->query(fn (): Builder => $this->reseller()->resellerOrders()->getQuery()->with(['customer', 'resellerCommission', 'resellerAdvancePayment']))
            ->columns([
                TextColumn::make('order_number')
                    ->label(__('Order'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('order_date')
                    ->label(__('Date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label(__('Customer')),

                TextColumn::make('customer.phone')
                    ->label(__('Phone')),

                TextColumn::make('customer.address')
                    ->label(__('Address'))
                    ->limit(30)
                    ->toggleable(),

                TextColumn::make('items_count')
                    ->label(__('Items'))
                    ->counts('items'),

                TextColumn::make('total_amount')
                    ->label(__('Sale amount'))
                    ->state(fn (Order $record): string => MoneyFormatter::currency($record->total_amount, $currency)),

                TextColumn::make('workflow_stage')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (Order $record): string => $record->workflowStage())
                    ->formatStateUsing(fn (string $state): string => Order::WORKFLOW_STAGES[$state] ?? Order::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Order::STAGE_DELIVERED, Order::STAGE_COMPLETED => 'success',
                        Order::STAGE_SHIPPING, Order::STAGE_PROCESSING => 'warning',
                        Order::STAGE_CANCELLED, Order::STAGE_RETURNED, Order::STAGE_REFUNDED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('reseller_parcel_id')
                    ->label(__('Parcel ID'))
                    ->placeholder('—')
                    ->searchable()
                    ->description(fn (Order $record): ?string => $record->reseller_own_courier
                        ? ($record->reseller_parcel_packed_at ? __('Packed') : ResellerOwnCourierService::paymentStateLabels()[app(ResellerOwnCourierService::class)->paymentState($record)])
                        : null),

                TextColumn::make('commission')
                    ->label(__('Commission'))
                    ->state(function (Order $record) use ($currency): string {
                        $commission = $record->resellerCommission;

                        if (! $commission) {
                            return $record->reseller_own_courier ? __('None (own courier)') : '—';
                        }

                        return MoneyFormatter::currency($commission->amount, $currency)
                            .' ('.(ResellerCommission::STATUSES[$commission->status] ?? $commission->status).')';
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(Order::STATUSES),

                Filter::make('order_date')
                    ->label(__('Date range'))
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('order_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('order_date', '<=', $date))),
            ])
            ->recordActions([
                Action::make('resendPayment')
                    ->label(__('Send payment again'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('warning')
                    ->visible(fn (Order $record): bool => $record->reseller_own_courier
                        && app(ResellerOwnCourierService::class)->paymentState($record) === 'rejected'
                        && $record->status !== Order::STATUS_CANCELLED)
                    ->modalDescription(fn (Order $record): string => __('Your last payment was not accepted. Send :amount and enter the new payment details.', [
                        'amount' => MoneyFormatter::currency($record->total_amount, $currency),
                    ]))
                    ->schema(fn (): array => [
                        Select::make('payment_method_id')
                            ->label(__('Paid to'))
                            ->options(app(ResellerOwnCourierService::class)->paymentMethods($this->reseller()->company)
                                ->mapWithKeys(fn (StorefrontPaymentMethod $method): array => [
                                    $method->getKey() => trim($method->name.($method->account_number ? ' — '.$method->account_number : '')),
                                ])->all())
                            ->required(),
                        TextInput::make('sender')
                            ->label(__('Paid from (number or account)'))
                            ->required()
                            ->maxLength(40),
                        TextInput::make('transaction_id')
                            ->label(__('Transaction ID'))
                            ->required()
                            ->maxLength(100),
                    ])
                    ->action(function (Order $record, array $data): void {
                        abort_unless((int) $record->reseller_customer_id === (int) $this->reseller()->getKey(), 403);

                        app(ResellerOwnCourierService::class)->submitPayment($record, $data, 'mountedActions.0.data.');

                        Notification::make()->title(__('Payment sent. The store will check it.'))->success()->send();
                    }),

                Action::make('view')
                    ->label(__('View'))
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (Order $record): string => $record->order_number)
                    ->modalContent(fn (Order $record) => view('filament.reseller.pages.partials.order-detail', [
                        'order' => $record->load(['items.product', 'statusTransitions' => fn ($q) => $q->orderBy('created_at'), 'resellerCommission', 'resellerAdvancePayment.paymentMethod']),
                        'currency' => $currency,
                    ]))
                    ->modalWidth('2xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
            ])
            ->searchPlaceholder(__('Search order number...'))
            ->defaultSort('order_date', 'desc');
    }
}
