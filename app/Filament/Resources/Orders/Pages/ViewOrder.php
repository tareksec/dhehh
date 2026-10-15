<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\ChangeOrderWorkflowAction;
use App\Filament\Resources\Orders\Actions\CourierBookingFeedback;
use App\Filament\Resources\Orders\Actions\PutReturnedGoodsBackInStockAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\CustomerRiskProfile;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\StorefrontPayment;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\CourierService;
use App\Services\CustomerRiskService;
use App\Services\OrderSummaryCardService;
use App\Services\ResellerOwnCourierService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Nuport's per-order "Logs" tab narrates a "viewed by X" line
        // every time staff open the order; AuditLogService::record()
        // already accepts an arbitrary action string, so this needs no
        // schema change. Deduped per user per order for 5 minutes so a
        // Livewire refresh or accidental double-open doesn't spam the
        // activity feed built from these rows (OrderActivityFeedService).
        $cacheKey = 'order-viewed-log:'.$this->record->getKey().':'.(Auth::id() ?? 'guest');

        if (! Cache::has($cacheKey)) {
            Cache::put($cacheKey, true, now()->addMinutes(5));
            app(AuditLogService::class)->record('viewed', $this->record);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previousOrder')
                ->label('Previous')
                ->icon('heroicon-o-chevron-left')
                ->iconButton()
                ->color('gray')
                ->visible(fn (): bool => $this->previousOrderId() !== null)
                ->url(fn (): string => OrderResource::getUrl('view', ['record' => $this->previousOrderId()])),
            Action::make('nextOrder')
                ->label('Next')
                ->icon('heroicon-o-chevron-right')
                ->iconButton()
                ->color('gray')
                ->visible(fn (): bool => $this->nextOrderId() !== null)
                ->url(fn (): string => OrderResource::getUrl('view', ['record' => $this->nextOrderId()])),
            ChangeOrderWorkflowAction::make(),
            PutReturnedGoodsBackInStockAction::make(),
            ...$this->resellerOwnCourierActions(),
            Action::make('bookCourier')
                ->label('Book courier')
                ->icon('heroicon-o-truck')
                ->visible(fn (): bool => $this->canBookCourier())
                ->schema($this->courierBookingForm())
                ->fillForm(fn (): array => app(CourierService::class)->bookingFormDefaults($this->record, 'manual'))
                ->action(function (Action $action, array $data): void {
                    CourierBookingFeedback::book($action, fn (): CourierBooking => app(CourierService::class)->createManualBooking($this->record, $data));
                    $this->record->refresh();
                }),
            Action::make('bookSteadfast')
                ->label('Book Steadfast')
                ->icon('heroicon-o-truck')
                ->color('warning')
                ->visible(fn (): bool => $this->canBookCourier() && CourierProvider::query()
                    ->where('driver', CourierProvider::DRIVER_STEADFAST)
                    ->where('is_active', true)
                    ->exists())
                ->schema($this->steadfastBookingForm())
                ->fillForm(fn (): array => app(CourierService::class)->bookingFormDefaults($this->record, 'steadfast'))
                ->action(function (Action $action, array $data): void {
                    $provider = CourierProvider::query()->findOrFail($data['courier_provider_id']);
                    CourierBookingFeedback::book($action, fn (): CourierBooking => app(CourierService::class)->createSteadfastBooking($this->record, $provider, $data));
                    $this->record->refresh();
                }),
            Action::make('markDelivered')
                ->label(__('Mark delivered'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => filled($this->record->latestCourierBooking) && ! in_array($this->record->delivery_status, [
                    CourierBooking::STATUS_DELIVERED,
                    CourierBooking::STATUS_RETURNED,
                    CourierBooking::STATUS_CANCELLED,
                ], true))
                ->requiresConfirmation()
                ->modalDescription(__('Only when the customer has received the parcel. This moves the order to Delivered without asking the courier.'))
                ->action(function (Action $action): void {
                    CourierBookingFeedback::run($action, fn () => app(CourierService::class)->updateStatus($this->record->latestCourierBooking, CourierBooking::STATUS_DELIVERED, 'Marked delivered from order detail.'), __('Marked delivered'));
                    $this->record->refresh();
                }),
            Action::make('markReturned')
                ->label(__('Mark returned'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => filled($this->record->latestCourierBooking) && ! in_array($this->record->delivery_status, [
                    CourierBooking::STATUS_DELIVERED,
                    CourierBooking::STATUS_RETURNED,
                    CourierBooking::STATUS_CANCELLED,
                ], true))
                ->requiresConfirmation()
                ->action(function (Action $action): void {
                    CourierBookingFeedback::run($action, fn () => app(CourierService::class)->updateStatus($this->record->latestCourierBooking, CourierBooking::STATUS_RETURNED, 'Marked returned from order detail.'), __('Marked returned'));
                    $this->record->refresh();
                }),
            Action::make('orderSummaryCard')
                ->label(__('Order summary card'))
                ->icon('heroicon-o-share')
                ->color('gray')
                ->modalHeading(__('Order summary card'))
                ->modalWidth('lg')
                ->modalContent(fn () => view('filament.orders.summary-card', [
                    'card' => app(OrderSummaryCardService::class)->build($this->record),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close')),
            Action::make('print')
                ->url(fn () => route('orders.print', ['order' => $this->record, 'print' => 1]))
                ->openUrlInNewTab(),
            EditAction::make(),
        ];
    }

    /**
     * PaymentsRelationManager dispatches this after every add/edit/delete of
     * a payment row. The infolist reads straight off $this->record, so a
     * refresh here is enough to show the ledger-recalculated paid_amount/
     * due_amount right away instead of only on the next full page load.
     */
    #[On('order-payment-updated')]
    public function refreshPaymentTotals(): void
    {
        $this->record->refresh();
    }

    protected function courierBookingForm(bool $includeProvider = true): array
    {
        $this->record->loadMissing('customer');

        $schema = [
            Placeholder::make('risk_notice')
                ->label('Customer Risk Check')
                ->content(function (): string {
                    $profile = app(CustomerRiskService::class)->evaluateCustomer($this->record->customer, $this->record);

                    return "{$profile->risk_score}/100 — ".(CustomerRiskProfile::LEVELS[$profile->risk_level] ?? $profile->risk_level);
                }),
            TextInput::make('tracking_id')
                ->label('Tracking ID')
                ->helperText('Leave blank to auto-generate a manual tracking ID.')
                ->maxLength(255),
            // Prefilled by the action's ->fillForm() from
            // CourierService::bookingFormDefaults() — the single source of
            // truth for recipient + COD defaults.
            TextInput::make('recipient_name')
                ->required()
                ->maxLength(255),
            TextInput::make('recipient_phone')
                ->tel()
                ->maxLength(255),
            Textarea::make('recipient_address')
                ->rows(3),
            TextInput::make('cod_amount')
                ->numeric()
                ->prefix('৳')
                ->minValue(0),
            Textarea::make('note')
                ->rows(2),
        ];

        if (! $includeProvider) {
            return $schema;
        }

        return [
            Select::make('courier_provider_id')
                ->label('Custom Provider')
                ->options(fn (): array => CourierProvider::query()
                    ->where('driver', CourierProvider::DRIVER_MANUAL)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->placeholder('Default Custom')
                ->native(false),
            ...$schema,
        ];
    }

    protected function steadfastBookingForm(): array
    {
        return [
            Select::make('courier_provider_id')
                ->label('Steadfast Provider')
                ->options(fn (): array => CourierProvider::query()
                    ->where('driver', CourierProvider::DRIVER_STEADFAST)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->native(false),
            ...$this->courierBookingForm(includeProvider: false),
            TextInput::make('alternative_phone')
                ->tel()
                ->maxLength(255),
            // recipient_email + delivery_type are prefilled by the action's
            // ->fillForm() (CourierService::bookingFormDefaults()).
            TextInput::make('recipient_email')
                ->email()
                ->maxLength(255),
            TextInput::make('item_description')
                ->maxLength(255),
            TextInput::make('total_lot')
                ->numeric()
                ->minValue(1),
            Select::make('delivery_type')
                ->options([
                    0 => 'Home Delivery',
                    1 => 'Point Delivery / Hub Pickup',
                ])
                ->native(false),
        ];
    }

    /**
     * A reseller's own-courier order: check their advance payment, then mark
     * the parcel packed with their parcel ID (ResellerOwnCourierService).
     *
     * @return array<int, Action>
     */
    protected function resellerOwnCourierActions(): array
    {
        // The oldest payment still waiting: the first advance, then the
        // extra the reseller sent after raising the total (one at a time).
        $pendingPayment = fn (): ?StorefrontPayment => $this->record->reseller_own_courier
            ? $this->record->resellerAdvancePayments()
                ->where('status', StorefrontPayment::STATUS_PENDING)
                // An online payment is confirmed by the gateway, never by hand.
                ->whereNotIn('gateway', array_keys(ResellerOwnCourierService::ONLINE_GATEWAYS))
                ->first()
            : null;

        return [
            Action::make('verifyResellerPayment')
                ->label(__('Verify reseller payment'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $pendingPayment() !== null)
                ->modalDescription(function () use ($pendingPayment): ?string {
                    $payment = $pendingPayment();

                    return $payment ? __(':amount to :method from :sender, transaction :transaction.', [
                        'amount' => number_format((float) $payment->amount, 2),
                        'method' => $payment->paymentMethod?->name ?? __('Manual'),
                        'sender' => $payment->payment_method,
                        'transaction' => $payment->transaction_id,
                    ]) : null;
                })
                ->fillForm(fn (): array => ['amount_received' => (float) $pendingPayment()?->amount])
                ->schema([
                    // What really arrived; anything above the order's total goes
                    // to the reseller's deposit (owner rule, 2026-10-06).
                    TextInput::make('amount_received')
                        ->label(__('Amount received'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->helperText(__('Change it if a different amount arrived. Anything above the order total goes to the reseller\'s deposit for their next orders.')),
                    Select::make('account_id')
                        ->label(__('Received into'))
                        ->options(fn (): array => OrderPayment::accountOptions())
                        ->visible(fn (): bool => OrderPayment::accountOptions() !== [])
                        ->required(fn (): bool => OrderPayment::accountOptions() !== [])
                        ->native(false),
                ])
                ->action(function (array $data) use ($pendingPayment): void {
                    if ($payment = $pendingPayment()) {
                        app(ResellerOwnCourierService::class)->verifyPayment(
                            $payment,
                            isset($data['account_id']) ? (int) $data['account_id'] : null,
                            isset($data['amount_received']) ? (float) $data['amount_received'] : null,
                        );
                    }
                    $this->record->refresh();
                }),
            Action::make('rejectResellerPayment')
                ->label(__('Reject reseller payment'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $pendingPayment() !== null)
                ->requiresConfirmation()
                ->modalDescription(__('The reseller will see the payment as rejected and can send it again.'))
                ->action(function () use ($pendingPayment): void {
                    if ($payment = $pendingPayment()) {
                        app(ResellerOwnCourierService::class)->rejectPayment($payment);
                    }
                    $this->record->refresh();
                }),
            Action::make('markParcelPacked')
                ->label(__('Mark parcel packed'))
                ->icon('heroicon-o-archive-box')
                ->color('primary')
                ->visible(fn (): bool => app(ResellerOwnCourierService::class)->canMarkPacked($this->record))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __('Put parcel ID :parcel on the package before marking it packed.', ['parcel' => $this->record->reseller_parcel_id]))
                ->action(function (Action $action): void {
                    try {
                        app(ResellerOwnCourierService::class)->markPacked($this->record);
                    } catch (ValidationException $exception) {
                        // e.g. not enough stock to confirm the order: say why, never "nothing happened".
                        Notification::make()
                            ->title(__('Could not mark the parcel packed'))
                            ->body(collect($exception->errors())->flatten()->implode(' '))
                            ->danger()
                            ->send();
                        $action->halt();
                    }

                    Notification::make()->title(__('Parcel marked packed'))->success()->send();
                    $this->record->refresh();
                }),
        ];
    }

    protected function canBookCourier(): bool
    {
        // A live courier booking, not the Delivery status field, decides (see Order::courierBookingBlocker()).
        return app(CompanyContext::class)->hasCompany()
            && (int) app(CompanyContext::class)->id() === (int) $this->record->company_id
            && $this->record->courierBookingBlocker() === null;
    }

    /**
     * Nuport's `< >` arrows next to the order number, so staff can page
     * through orders without going back to the list. Id-based rather than
     * tied to whatever sort/filter the list table happened to have —
     * CompanyScope (BelongsToCompany) already keeps this scoped to the
     * current company like every other Order query.
     */
    protected function previousOrderId(): ?int
    {
        return Order::query()
            ->where('id', '<', $this->record->getKey())
            ->orderByDesc('id')
            ->value('id');
    }

    protected function nextOrderId(): ?int
    {
        return Order::query()
            ->where('id', '>', $this->record->getKey())
            ->orderBy('id')
            ->value('id');
    }
}
