<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\ChangeOrderWorkflowAction;
use App\Filament\Resources\Orders\Actions\CourierBookingFeedback;
use App\Filament\Resources\Orders\Actions\EmailCustomerAction;
use App\Filament\Resources\Orders\Actions\PutReturnedGoodsBackInStockAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
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
use App\Services\ShortShipmentService;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
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
            EmailCustomerAction::make(),
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
            $this->courierAmountAction(),
            $this->shortShipmentAction(),
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
            Action::make('orderSummaryCardPdf')
                ->label(__('Order summary card (PDF)'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn () => route('orders.summary-card.pdf', ['order' => $this->record]))
                ->openUrlInNewTab(),
            Action::make('print')
                ->url(fn () => route('orders.print', ['order' => $this->record, 'print' => 1]))
                ->openUrlInNewTab(),
            EditAction::make(),
        ];
    }

    /**
     * The courier still has the amount it was booked with (items added, a payment came in, a discount was given).
     * For our own manual delivery the app changes it; for a courier company staff change it with the courier (their
     * APIs have no call for it) and confirm here, so the booking and the activity show what the courier really has.
     */
    protected function courierAmountAction(): Action
    {
        $mismatch = fn (): ?array => app(CourierService::class)->codMismatch($this->record);

        return Action::make('courierAmountUpdated')
            ->label(fn (): string => ($mismatch()['automatic'] ?? false) ? __('Update courier amount') : __('Courier amount updated'))
            ->icon('heroicon-o-banknotes')
            ->color('warning')
            ->visible(fn (): bool => $mismatch() !== null && (Auth::user()?->hasPermission('sales.update') ?? false))
            ->modalHeading(__('Change the amount the courier collects'))
            ->modalDescription(function () use ($mismatch): string {
                $found = $mismatch();

                return $found === null ? '' : OrderInfolist::codMismatchMessage($this->record)
                    .' '.($found['automatic']
                        ? __('This delivery is our own, so the amount is changed here.')
                        : __('Press the button only after the courier has the new amount: the courier companies take the amount when the parcel is booked and the app cannot change it for them.'));
            })
            ->schema([
                Textarea::make('note')
                    ->label(__('Note (who at the courier agreed)'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel(__('Save the new amount'))
            ->action(function (array $data) use ($mismatch): void {
                $found = $mismatch();

                if ($found === null) {
                    Notification::make()->info()->title(__('The courier already has the right amount.'))->send();

                    return;
                }

                app(CourierService::class)->recordCodChange(
                    $found['booking'],
                    $found['needed'],
                    filled($data['note'] ?? null) ? 'COD changed with the courier: '.trim((string) $data['note']) : null,
                );
                $this->record->refresh();

                Notification::make()->success()->title(__('Courier amount saved'))->send();
            });
    }

    /**
     * Fewer pieces went out than were ordered and the customer confirmed it (video call / unboxing video): the order
     * is cut down to the pieces sent and the new amount for the courier is worked out and shown (ShortShipmentService).
     */
    protected function shortShipmentAction(): Action
    {
        $service = fn (): ShortShipmentService => app(ShortShipmentService::class);

        return Action::make('shortShipment')
            ->label(__('Fewer pieces sent'))
            ->icon('heroicon-o-scale')
            ->color('gray')
            ->visible(fn (): bool => (Auth::user()?->hasPermission('sales.update') ?? false) && $service()->allowedFor($this->record))
            ->modalHeading(__('Cut the order down to the pieces sent'))
            ->modalDescription(__('Use this when fewer pieces went out than were ordered and the customer confirmed it on a video call or from the unboxing video. Enter the pieces that were really sent. The order, the customer\'s due and the stock follow, and the new amount for the courier to collect is worked out below.'))
            ->schema(function () use ($service): array {
                $this->record->loadMissing('items.product');
                $fields = [];

                foreach ($this->record->items as $item) {
                    $fields[] = TextInput::make('sent.'.$item->getKey())
                        ->label(trim(($item->product?->name ?? __('Item')).($item->variant_label ? ' ('.$item->variant_label.')' : '')))
                        ->helperText(__('Ordered :quantity at :price each', [
                            'quantity' => $item->quantity,
                            'price' => MoneyFormatter::currency((float) $item->unit_price, $this->record->company?->currency ?: 'BDT'),
                        ]))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue((int) $item->quantity)
                        ->default((int) $item->quantity)
                        ->required()
                        ->live(onBlur: true);
                }

                return [
                    ...$fields,
                    Placeholder::make('short_result')
                        ->label(__('Amount for the courier to collect'))
                        ->content(function (Get $get) use ($service): HtmlString {
                            $preview = $service()->preview($this->record, (array) ($get('sent') ?? []));
                            $currency = $this->record->company?->currency ?: 'BDT';
                            $money = fn (float $amount): string => e(MoneyFormatter::currency($amount, $currency));

                            if ($preview['missing_pieces'] === 0) {
                                return new HtmlString('<span>'.e(__('Every piece is marked as sent, so nothing changes.')).'</span>');
                            }

                            return new HtmlString(
                                '<div><strong>'.$money($preview['new_cod']).'</strong> '
                                .e(__('(now :current, minus :missing for :pieces piece(s) left out)', [
                                    'current' => MoneyFormatter::currency($preview['current_cod'], $currency),
                                    'missing' => MoneyFormatter::currency($preview['missing_value'], $currency),
                                    'pieces' => $preview['missing_pieces'],
                                ])).'</div>'
                            );
                        }),
                    Textarea::make('note')
                        ->label(__('How the customer confirmed'))
                        ->placeholder(__('For example: video call on 1 Nov, the customer showed what arrived'))
                        ->required()
                        ->rows(2)
                        ->maxLength(500),
                ];
            })
            ->modalSubmitActionLabel(__('Cut the order down'))
            ->action(function (array $data) use ($service): void {
                try {
                    $applied = $service()->apply($this->record, (array) ($data['sent'] ?? []), (string) ($data['note'] ?? ''));
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(collect($exception->errors())->flatten()->implode(' '))->send();

                    return;
                }

                $this->record->refresh();
                $currency = $this->record->company?->currency ?: 'BDT';
                $booking = $this->record->latestCourierBooking;
                $live = $booking && in_array($booking->status, CourierBooking::ACTIVE_STATUSES, true);

                Notification::make()
                    ->success()
                    ->title(__('The order now matches the pieces sent'))
                    ->body($live
                        ? __('Tell the courier (tracking :tracking) to collect :amount, then press "Courier amount updated".', [
                            'tracking' => $booking->tracking_id ?: '—',
                            'amount' => MoneyFormatter::currency($applied['new_cod'], $currency),
                        ])
                        : __('The amount to collect is now :amount.', ['amount' => MoneyFormatter::currency($applied['new_cod'], $currency)]))
                    ->persistent($live)
                    ->send();
            });
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
                ->minValue(0)
                ->helperText(fn (): string => app(CourierService::class)->codHelperText($this->record)),
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
