<?php

namespace App\Filament\Reseller\Pages;

use App\Models\Customer;
use App\Models\Order;
use App\Models\ResellerCommission;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

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

    /** Set by the dashboard's order pipeline (/portal/orders?stage=shipping). */
    #[Url]
    public ?string $stage = null;

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

    /** The stage filter from the URL, or null when it is missing or not a real stage. */
    public function activeStage(): ?string
    {
        return array_key_exists((string) $this->stage, Order::WORKFLOW_STAGES) ? $this->stage : null;
    }

    public function activeStageLabel(): ?string
    {
        $stage = $this->activeStage();

        return $stage === null ? null : __(Order::WORKFLOW_STAGES[$stage]);
    }

    public function clearStage(): void
    {
        $this->stage = null;
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        $currency = $this->reseller()->company?->currency ?: 'BDT';

        return $table
            ->query(fn (): Builder => $this->reseller()->resellerOrders()->getQuery()
                ->with(['customer', 'resellerCommission', 'resellerAdvancePayments'])
                ->when($this->activeStage(), fn (Builder $query, string $stage): Builder => $query->inWorkflowStage($stage)))
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
                    // Own courier: the reseller's own sale (their retail prices), not what they pay the store.
                    ->state(fn (Order $record): string => MoneyFormatter::currency($record->buyerTotal(), $currency)),

                TextColumn::make('workflow_stage')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (Order $record): string => $record->workflowStage())
                    ->formatStateUsing(fn (string $state): string => __(Order::WORKFLOW_STAGES[$state] ?? Order::STATUSES[$state] ?? $state))
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
                    ->label(__('Profit'))
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
                // Own courier: until the store verifies the advance (owner rule, 2026-10-06).
                Action::make('edit')
                    ->label(__('Change'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (Order $record): bool => app(ResellerOwnCourierService::class)->canEdit($record))
                    ->url(fn (Order $record): string => EditOrder::getUrl(['record' => $record->getKey()])),

                // An online payment that was started and not finished: back to the gateway's page.
                Action::make('payOnline')
                    ->label(__('Pay now'))
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->color('success')
                    ->visible(fn (Order $record): bool => $record->reseller_own_courier
                        && $record->status !== Order::STATUS_CANCELLED
                        && (app(ResellerOwnCourierService::class)->pendingOnlinePayment($record)?->payload['payment_url'] ?? null) !== null)
                    ->url(fn (Order $record): ?string => app(ResellerOwnCourierService::class)->pendingOnlinePayment($record)?->payload['payment_url'] ?? null),

                Action::make('resendPayment')
                    ->label(__('Send payment again'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('warning')
                    ->visible(fn (Order $record): bool => $record->reseller_own_courier
                        && in_array(app(ResellerOwnCourierService::class)->paymentState($record), ['rejected', 'short', 'paying'], true)
                        && $record->status !== Order::STATUS_CANCELLED)
                    ->modalDescription(fn (Order $record): string => __('Send :amount and enter the payment details, or pay online.', [
                        'amount' => MoneyFormatter::currency(app(ResellerOwnCourierService::class)->outstandingAmount($record), $currency),
                    ]))
                    ->schema(fn (Order $record): array => [
                        Select::make('payment_method_id')
                            ->label(__('Paid to'))
                            ->options(app(ResellerOwnCourierService::class)->paymentOptions($this->reseller()->company))
                            ->required()
                            ->live(),
                        TextInput::make('sender')
                            ->label(__('Paid from (number or account)'))
                            ->required()
                            ->visible(fn (Get $get): bool => $get('payment_method_id') !== ResellerOwnCourierService::ONLINE)
                            ->maxLength(40),
                        TextInput::make('transaction_id')
                            ->label(__('Transaction ID'))
                            ->required()
                            ->visible(fn (Get $get): bool => $get('payment_method_id') !== ResellerOwnCourierService::ONLINE)
                            ->maxLength(100),
                        CreateOrder::amountSentField(fn (): float => app(ResellerOwnCourierService::class)->outstandingAmount($record), $currency)
                            ->visible(fn (Get $get): bool => $get('payment_method_id') !== ResellerOwnCourierService::ONLINE),
                    ])
                    ->action(function (Order $record, array $data, Action $action): void {
                        abort_unless((int) $record->reseller_customer_id === (int) $this->reseller()->getKey(), 403);

                        $payment = app(ResellerOwnCourierService::class)->submitPayment($record, $data, 'mountedActions.0.data.');

                        // Paying online: on to the gateway's page.
                        if ($payment->payload['payment_url'] ?? null) {
                            $action->redirect($payment->payload['payment_url']);

                            return;
                        }

                        Notification::make()->title(__('Payment sent. The store will check it.'))->success()->send();
                    }),

                Action::make('view')
                    ->label(__('View'))
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (Order $record): string => $record->order_number)
                    ->modalContent(fn (Order $record) => view('filament.reseller.pages.partials.order-detail', [
                        'order' => $record->load(['items.product', 'statusTransitions' => fn ($q) => $q->orderBy('created_at'), 'resellerCommission', 'resellerAdvancePayments.paymentMethod']),
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
