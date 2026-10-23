<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Resources\Orders\Actions\ChangeOrderWorkflowAction;
use App\Filament\Resources\Orders\Actions\CourierBookingFeedback;
use App\Filament\Resources\Orders\OrderResource;
use App\Livewire\OrderTrashTable;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\CustomerRiskProfile;
use App\Models\CustomerRiskReview;
use App\Models\Order;
use App\Services\CompanyContext;
use App\Services\CourierService;
use App\Services\CustomerRiskService;
use App\Services\OrderStatusWorkflowService;
use App\Support\PhoneLinks;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Livewire as SchemaLivewire;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer', 'latestFraudCheck', 'latestRiskReview', 'latestCourierBooking']))
            ->columns([
                TextColumn::make('order_number')
                    ->label('Invoice Number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),

                // Call and WhatsApp icons under the number: tapping them opens
                // the phone's dialer / WhatsApp chat directly. disabledClick()
                // stops the row's "open order" link wrapping the icons.
                TextColumn::make('customer.phone')
                    ->label('Phone')
                    ->placeholder('-')
                    ->searchable()
                    ->copyable()
                    ->description(fn (Order $record): ?HtmlString => self::phoneContactLinks($record->customer?->phone))
                    ->disabledClick()
                    ->toggleable(),

                TextColumn::make('latestCourierBooking.tracking_id')
                    ->label('Tracking ID')
                    ->placeholder('-')
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('reseller_parcel_id')
                    ->label(__('Reseller parcel ID'))
                    ->placeholder('-')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('order_date')
                    ->label('Sale Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Order::SOURCES[$state ?? Order::SOURCE_ADMIN] ?? str($state)->headline()->toString())
                    ->color(fn (?string $state): string => match ($state) {
                        Order::SOURCE_STOREFRONT => 'warning',
                        Order::SOURCE_OFFER => 'success',
                        Order::SOURCE_WOOCOMMERCE => 'info',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('sales_channel')
                    ->label(__('Sales channel'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => Order::salesChannelLabel($state))
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_amount')
                    ->label('Total Amount')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->sortable(),

                TextColumn::make('paid_amount')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('due_amount')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Order::STATUSES[$state] ?? str($state)->headline()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        Order::STATUS_CONFIRMED, Order::STATUS_COMPLETED => 'success',
                        Order::STATUS_PROCESSING => 'warning',
                        Order::STATUS_CANCELLED, Order::STATUS_RETURNED => 'danger',
                        Order::STATUS_REFUNDED => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('delivery_status')
                    ->label('Delivery')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Order::DELIVERY_STATUSES[$state ?? CourierBooking::STATUS_NOT_BOOKED] ?? str($state)->headline()->toString())
                    ->color(fn (?string $state): string => match ($state) {
                        CourierBooking::STATUS_DELIVERED => 'success',
                        CourierBooking::STATUS_RETURNED, CourierBooking::STATUS_CANCELLED, CourierBooking::STATUS_FAILED => 'danger',
                        CourierBooking::STATUS_BOOKED, CourierBooking::STATUS_PICKED_UP, CourierBooking::STATUS_IN_TRANSIT => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('latestFraudCheck.risk_level')
                    ->label('Risk')
                    ->badge()
                    ->placeholder('Not checked')
                    ->formatStateUsing(fn (?string $state): string => CustomerRiskProfile::LEVELS[$state ?? ''] ?? 'Not checked')
                    ->color(fn (?string $state): string => match ($state) {
                        'low' => 'success', 'medium' => 'warning', 'high', 'blacklisted' => 'danger', default => 'gray',
                    }),

                TextColumn::make('latestRiskReview.status')
                    ->label('Risk Review')
                    ->badge()
                    ->placeholder('Not required')
                    ->formatStateUsing(fn (?string $state): string => CustomerRiskReview::STATUSES[$state ?? ''] ?? 'Not required')
                    ->color(fn (?string $state): string => match ($state) {
                        CustomerRiskReview::STATUS_APPROVED => 'success',
                        CustomerRiskReview::STATUS_REJECTED => 'danger',
                        CustomerRiskReview::STATUS_PENDING => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Order::STATUSES),

                SelectFilter::make('delivery_status')
                    ->label('Delivery')
                    ->options(Order::DELIVERY_STATUSES),

                SelectFilter::make('source')
                    ->options(Order::SOURCES),

                SelectFilter::make('sales_channel')
                    ->label(__('Sales channel'))
                    ->options(fn (): array => collect(Order::SALES_CHANNELS)
                        ->mapWithKeys(fn (string $label, string $channel): array => [$channel => Order::salesChannelLabel($channel)])
                        ->all()),

                TernaryFilter::make('reseller_own_courier')
                    ->label(__('Reseller\'s own courier')),

                // Orders with an item added after they were created (see
                // OrderItem::$is_upsell) — admin-only, like the Upsell tag.
                TernaryFilter::make('has_upsell')
                    ->label(__('Upsell items'))
                    ->trueLabel(__('With upsell items'))
                    ->falseLabel(__('Without upsell items'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('items', fn (Builder $items): Builder => $items->where('is_upsell', true)),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('items', fn (Builder $items): Builder => $items->where('is_upsell', true)),
                    ),

                SelectFilter::make('customer_id')
                    ->label('Customer')
                    ->relationship('customer', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ChangeOrderWorkflowAction::make(),
                Action::make('bookCourier')
                    ->label('Book courier')
                    ->icon('heroicon-o-truck')
                    ->visible(fn (Order $record): bool => self::canBookCourier($record))
                    ->schema(fn (Order $record): array => self::unifiedBookingForm($record))
                    ->fillForm(fn (Order $record): array => app(CourierService::class)->bookingFormDefaults($record))
                    ->action(function (Action $action, Order $record, array $data): void {
                        $provider = CourierProvider::query()->findOrFail($data['courier_provider_id']);
                        $payload = self::bookingPayloadFor($provider, $data);

                        CourierBookingFeedback::book($action, fn (): CourierBooking => match ($provider->driver) {
                            CourierProvider::DRIVER_STEADFAST => app(CourierService::class)->createSteadfastBooking($record, $provider, $payload),
                            CourierProvider::DRIVER_PATHAO => app(CourierService::class)->createPathaoBooking($record, $provider, $payload),
                            CourierProvider::DRIVER_REDX => app(CourierService::class)->createRedxBooking($record, $provider, $payload),
                            CourierProvider::DRIVER_ECOURIER => app(CourierService::class)->createECourierBooking($record, $provider, $payload),
                            default => app(CourierService::class)->createManualBooking($record, $payload),
                        });
                    }),
                // "Mark …": a bare green "Delivered" button next to a freshly
                // booked order read like its status (owner report 2026-10-06).
                Action::make('markDelivered')
                    ->label(__('Mark delivered'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Order $record): bool => filled($record->latestCourierBooking) && ! in_array($record->delivery_status, [
                        CourierBooking::STATUS_DELIVERED,
                        CourierBooking::STATUS_RETURNED,
                        CourierBooking::STATUS_CANCELLED,
                    ], true))
                    ->requiresConfirmation()
                    ->modalDescription(__('Only when the customer has received the parcel. This moves the order to Delivered without asking the courier.'))
                    ->action(function (Action $action, Order $record): void {
                        CourierBookingFeedback::run($action, fn () => app(CourierService::class)->updateStatus($record->latestCourierBooking, CourierBooking::STATUS_DELIVERED, 'Marked delivered from order list.'), __('Marked delivered'));
                    }),
                Action::make('markReturned')
                    ->label(__('Mark returned'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (Order $record): bool => filled($record->latestCourierBooking) && ! in_array($record->delivery_status, [
                        CourierBooking::STATUS_DELIVERED,
                        CourierBooking::STATUS_RETURNED,
                        CourierBooking::STATUS_CANCELLED,
                    ], true))
                    ->requiresConfirmation()
                    ->action(function (Action $action, Order $record): void {
                        CourierBookingFeedback::run($action, fn () => app(CourierService::class)->updateStatus($record->latestCourierBooking, CourierBooking::STATUS_RETURNED, 'Marked returned from order list.'), __('Marked returned'));
                    }),
                Action::make('print')
                    ->url(fn (Order $record): string => route('orders.print', ['order' => $record, 'print' => 1]))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('changeWorkflowStatus')
                        ->label('Change status')
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->visible(fn (): bool => Auth::user()?->hasPermission('sales.update') ?? false)
                        ->schema([
                            Select::make('stage')
                                ->label('Next stage')
                                ->options(Order::WORKFLOW_STAGES)
                                ->required()
                                ->live()
                                ->native(false),
                            Textarea::make('reason')
                                ->label('Reason / note')
                                ->rows(3)
                                ->maxLength(1000)
                                ->required(fn (Get $get): bool => in_array($get('stage'), Order::REASON_REQUIRED_STAGES, true))
                                ->helperText('Orders that cannot legally reach the selected stage will be skipped.'),
                        ])
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data): void {
                            $updated = 0;
                            $skipped = [];

                            foreach ($records as $record) {
                                try {
                                    app(OrderStatusWorkflowService::class)->transition(
                                        $record,
                                        $data['stage'],
                                        $data['reason'] ?? null,
                                        'bulk',
                                    );
                                    $updated++;
                                } catch (ValidationException $exception) {
                                    // Each skipped order with its own reason (a transition that is not
                                    // allowed, or not enough stock), never a bare count.
                                    $skipped[] = $record->order_number.': '.collect($exception->errors())->flatten()->implode(' ');
                                }
                            }

                            $notification = Notification::make()
                                ->title("{$updated} order(s) updated")
                                ->body($skipped !== [] ? __(':count order(s) were skipped:', ['count' => count($skipped)]).' '.implode(' | ', $skipped) : null);

                            if ($updated === 0) {
                                $notification->danger();
                            } elseif ($skipped !== []) {
                                $notification->warning();
                            } else {
                                $notification->success();
                            }

                            $notification->send();
                        }),
                    BulkAction::make('bookCourierBulk')
                        ->label('Book courier')
                        ->icon('heroicon-o-truck')
                        ->color('primary')
                        ->visible(fn (): bool => Auth::user()?->hasPermission('sales.update') ?? false)
                        ->schema(fn (): array => self::bulkBookingForm())
                        ->requiresConfirmation()
                        ->modalDescription(__('Books every selected, not-yet-booked order through the chosen courier. Orders that cannot be booked are skipped, each with the reason.'))
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records, array $data) => self::bookCourierBulk($records, $data)),
                    BulkAction::make('printInvoicesBulk')
                        ->label('Print invoices')
                        ->icon('heroicon-o-printer')
                        ->color('gray')
                        // Deliberately NOT ->url(): a bulk action's trigger
                        // link is rendered once and doesn't re-evaluate as
                        // checkboxes are (de)selected client-side, so a
                        // ->url() closure here would keep opening the print
                        // view with whatever selection existed at the last
                        // full render — often empty. ->action() instead
                        // always sees the live selection (same as every
                        // other bulk action here) and opens the new tab
                        // itself via a JS effect once the URL is computed.
                        // No ->schema()/->requiresConfirmation(), so this is
                        // still one click, matching the row-level "print"
                        // action's directness.
                        ->action(fn (Collection $records, $livewire) => self::printInvoicesBulk($records, $livewire)),
                    DeleteBulkAction::make()
                        ->label('Move to trash')
                        ->icon('heroicon-o-trash')
                        // A returned or refunded order is never trashed (OrderResource::canDelete()); it is skipped and reported.
                        ->authorizeIndividualRecords(fn (Order $record): bool => OrderResource::canDelete($record))
                        ->modalHeading('Move selected orders to trash?')
                        ->modalDescription('The selected orders will leave the active order list and remain available in Trash until they are permanently deleted. Returned and refunded orders cannot be moved to trash.')
                        ->modalSubmitActionLabel('Move to trash')
                        ->successNotificationTitle('Selected orders moved to trash'),
                ]),
                Action::make('trash')
                    ->label('Trash')
                    ->icon('heroicon-o-trash')
                    ->color('gray')
                    ->badge(fn (): ?string => ($count = self::trashedOrdersQuery()->count()) > 0 ? (string) $count : null)
                    ->authorize(fn (): bool => self::canManageTrash())
                    ->visible(fn (): bool => self::canManageTrash())
                    ->modalHeading('Trashed orders')
                    ->modalIcon('heroicon-o-trash')
                    ->modalDescription(fn (): string => self::trashModalDescription())
                    ->modalWidth(Width::FiveExtraLarge)
                    ->schema([
                        SchemaLivewire::make(OrderTrashTable::class)
                            ->key('orderTrashTable'),
                    ])
                    ->requiresConfirmation()
                    ->modalSubmitAction(fn (Action $action): Action => $action
                        ->label('Delete permanently')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->disabled(fn (): bool => self::trashedOrdersQuery()->doesntExist()))
                    ->modalCancelActionLabel('Close')
                    ->action(fn (): int => self::permanentlyDeleteTrashedOrders()),
            ]);
    }

    protected static function trashModalDescription(): string
    {
        $count = self::trashedOrdersQuery()->count();

        if ($count === 0) {
            return 'Orders moved to trash will appear here.';
        }

        return "Permanently deleting all {$count} trashed order(s) cannot be undone.";
    }

    protected static function permanentlyDeleteTrashedOrders(): int
    {
        $count = self::trashedOrdersQuery()->count();

        if ($count === 0) {
            Notification::make()
                ->title('Trash is already empty')
                ->info()
                ->send();

            return 0;
        }

        DB::transaction(function (): void {
            self::trashedOrdersQuery()->eachById(
                fn (Order $order): bool => $order->forceDelete(),
            );
        });

        Notification::make()
            ->title("{$count} order(s) permanently deleted")
            ->success()
            ->send();

        return $count;
    }

    protected static function trashedOrdersQuery(): Builder
    {
        // `All Companies` deliberately removes CompanyScope. A destructive
        // "delete all" must instead fail closed until one company is active.
        return Order::onlyTrashed()
            ->where('company_id', app(CompanyContext::class)->id() ?? 0);
    }

    protected static function canManageTrash(): bool
    {
        return (Auth::user()?->canDeleteSensitiveRecords() ?? false)
            && app(CompanyContext::class)->hasCompany();
    }

    /**
     * Bulk-safe couriers only — Steadfast and Manual need no extra input
     * beyond what Order/Customer already supply (see
     * SteadfastCourierClient's steadfastPayload()); Pathao/RedX/E-Courier
     * require structured per-order fields (city/zone/area IDs, delivery
     * area names, ...) that don't exist on the Order model and can't be
     * safely guessed for a batch of different recipients, so they're left
     * out of this list entirely rather than offered and then silently
     * skipped — "Book courier" on an individual order is where those
     * belong.
     */
    protected const BULK_SAFE_DRIVERS = [CourierProvider::DRIVER_STEADFAST, CourierProvider::DRIVER_MANUAL];

    /**
     * @return array<int, Component>
     */
    protected static function bulkBookingForm(): array
    {
        $companyId = app(CompanyContext::class)->id();

        return [
            Select::make('courier_provider_id')
                ->label('Courier')
                ->options(fn (): array => self::providerOptions($companyId, self::BULK_SAFE_DRIVERS))
                ->default(function () use ($companyId): ?int {
                    $default = CourierProvider::defaultForCompany($companyId);

                    return $default && in_array($default->driver, self::BULK_SAFE_DRIVERS, true) ? $default->getKey() : null;
                })
                ->required()
                ->native(false)
                ->helperText('Applies to every selected order. Pre-filled from the default courier — change it if this batch needs a different one. Pathao/RedX/E-Courier need per-order details and aren\'t offered here; use "Book courier" on an individual order for those.'),
        ];
    }

    /**
     * @param  array<int, string>  $drivers  restrict options to these driver keys, or null for all
     */
    protected static function providerOptions(?int $companyId, ?array $drivers = null): array
    {
        return CourierProvider::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($drivers, fn ($query) => $query->whereIn('driver', $drivers))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (CourierProvider $provider): array => [
                $provider->getKey() => $provider->name.' ('.(CourierProvider::DRIVERS[$provider->driver] ?? $provider->driver).')'.($provider->is_default ? ' — Default' : ''),
            ])
            ->all();
    }

    /**
     * Books every eligible order in $records through the one courier chosen
     * in the bulk popup ($data['courier_provider_id']) — restricted to
     * BULK_SAFE_DRIVERS by bulkBookingForm(), so every booking here is
     * either Steadfast or Manual.
     */
    protected static function bookCourierBulk(Collection $records, array $data): void
    {
        $provider = CourierProvider::query()->findOrFail($data['courier_provider_id']);

        $booked = 0;
        $skipped = [];
        $failures = [];

        foreach ($records as $record) {
            /** @var Order $record */
            // Each skipped order says why — it used to say "already booked"
            // for every reason, including orders that had never been booked.
            if (($blocker = self::courierBookingBlocker($record)) !== null) {
                $skipped[] = $record->order_number.': '.$blocker;

                continue;
            }

            try {
                match ($provider->driver) {
                    CourierProvider::DRIVER_STEADFAST => app(CourierService::class)->createSteadfastBooking($record, $provider),
                    default => app(CourierService::class)->createManualBooking($record, ['courier_provider_id' => $provider->getKey()]),
                };
                $booked++;
            } catch (ValidationException $exception) {
                // Nothing reaches the Status Logs when a booking is refused,
                // so say which order failed and why.
                $failures[] = CourierBookingFeedback::failureLine($exception, (string) $record->order_number);
            }
        }

        $notes = array_filter([
            $skipped !== [] ? __(':count skipped', ['count' => count($skipped)]) : null,
            $failures !== [] ? __(':count failed', ['count' => count($failures)]) : null,
        ]);

        $notification = Notification::make()
            ->title(__(':count order(s) booked', ['count' => $booked]))
            ->body($notes === [] ? null : new HtmlString(collect([implode('; ', $notes).'.', ...$skipped, ...$failures])->map(fn (string $line): string => e($line))->implode('<br>')));

        if ($skipped !== [] || $failures !== []) {
            $notification->persistent();
        }

        if ($booked === 0) {
            $notification->danger();
        } elseif ($notes !== []) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    /**
     * Opens every selected order's invoice in one new tab (see
     * orders.print.bulk / orders/print-bulk.blade.php), which auto-triggers
     * the browser/OS print dialog once loaded — same one-click "print
     * preview" behaviour as the row-level "print" action, just for a whole
     * batch at once.
     *
     * $livewire->js() rather than ->url() on the action itself: a bulk
     * action's trigger link is rendered once and doesn't re-evaluate as
     * checkboxes are (de)selected client-side, so a static ->url() closure
     * would keep opening the print view with whatever selection existed at
     * the table's last full render — often empty. Routing through
     * ->action() means $records always reflects the live selection (same
     * as every other bulk action here), and the URL is only computed —
     * then opened — once we actually have it.
     */
    protected static function printInvoicesBulk(Collection $records, mixed $livewire): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $url = route('orders.print.bulk', [
            'orders' => $records->pluck('id')->implode(','),
        ]);

        $livewire->js('window.open('.json_encode($url).', "_blank")');
    }

    /**
     * Single unified booking form for the per-order "Book courier" action.
     * One `courier_provider_id` Select (default: the order's own assigned
     * courier, falling back to the company's default) drives which
     * driver-specific field group is visible via `->live()` + `Get` — the
     * common fields cover Manual bookings outright, and the reason this
     * isn't also how the bulk action works is that the bulk popup can't
     * collect different per-order details for a batch of different
     * recipients (see BULK_SAFE_DRIVERS).
     *
     * Field names are prefixed per driver (`sf_`/`ph_`/`rx_`/`ec_`) because
     * three field names genuinely collide across drivers with different
     * meanings — `recipient_city` (Pathao: numeric city ID; E-Courier:
     * plain city name), `recipient_area` (Pathao: numeric area ID;
     * E-Courier: plain area name), and `delivery_type` (Steadfast:
     * home/point; Pathao: normal/on-demand) — two Filament components can't
     * safely share one state path in the same schema. `bookingPayloadFor()`
     * strips the prefix back off before calling the matching
     * `CourierService::create*Booking()`, whose signatures are unchanged.
     *
     * @return array<int, Component>
     */
    protected static function unifiedBookingForm(Order $record): array
    {
        $record->loadMissing('customer', 'courierProvider');
        $companyId = app(CompanyContext::class)->id();

        $providers = CourierProvider::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $driverOf = fn (Get $get): ?string => $providers->get((int) $get('courier_provider_id'))?->driver;

        return [
            // Every field with a default below is prefilled by the action's
            // ->fillForm() (CourierService::bookingFormDefaults()) — the one
            // place recipient / COD / driver defaults are defined. Filament
            // skips component ->default() once a ->fillForm() payload is set,
            // so new defaultable fields must be added there too.
            Select::make('courier_provider_id')
                ->label('Courier')
                ->options(fn (): array => self::providerOptions($companyId))
                ->required()
                ->live()
                ->native(false)
                ->helperText('Defaults to this order\'s assigned courier (or the company default) — change it here if this order needs a different one.'),

            Placeholder::make('risk_notice')
                ->label('Customer Risk Check')
                ->content(function () use ($record): string {
                    $profile = app(CustomerRiskService::class)->evaluateCustomer($record->customer, $record);

                    return "{$profile->risk_score}/100 — ".(CustomerRiskProfile::LEVELS[$profile->risk_level] ?? $profile->risk_level);
                }),
            TextInput::make('tracking_id')
                ->label('Tracking ID')
                ->helperText('Leave blank to auto-generate a manual tracking ID.')
                ->maxLength(255),
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
                ->helperText(fn (): string => app(CourierService::class)->codHelperText($record)),
            Textarea::make('note')
                ->rows(2),

            // Steadfast-only
            TextInput::make('sf_alternative_phone')
                ->label('Alternative Phone')
                ->tel()
                ->maxLength(255)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_STEADFAST),
            TextInput::make('sf_recipient_email')
                ->label('Recipient Email')
                ->email()
                ->maxLength(255)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_STEADFAST),
            TextInput::make('sf_item_description')
                ->label('Item Description')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_STEADFAST),
            TextInput::make('sf_total_lot')
                ->label('Total Lot')
                ->numeric()
                ->minValue(1)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_STEADFAST),
            Select::make('sf_delivery_type')
                ->label('Delivery Type')
                ->options([0 => 'Home Delivery', 1 => 'Point Delivery / Hub Pickup'])
                ->native(false)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_STEADFAST),

            // Pathao-only
            TextInput::make('ph_store_id')
                ->label('Store ID')
                ->numeric()
                ->helperText('Leave blank to use the provider default store.')
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_PATHAO),
            TextInput::make('ph_recipient_city')
                ->label('City ID')
                ->numeric()
                ->helperText('Pathao city/zone/area IDs from the merchant panel or API city list.')
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_PATHAO),
            TextInput::make('ph_recipient_zone')
                ->label('Zone ID')
                ->numeric()
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_PATHAO),
            TextInput::make('ph_recipient_area')
                ->label('Area ID')
                ->numeric()
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_PATHAO),
            Select::make('ph_delivery_type')
                ->label('Delivery Type')
                ->options([48 => 'Normal Delivery', 12 => 'On-Demand Delivery'])
                ->native(false)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_PATHAO),
            TextInput::make('ph_item_weight')
                ->label('Weight (kg)')
                ->numeric()
                ->minValue(0.1)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_PATHAO),

            // RedX-only
            TextInput::make('rx_delivery_area')
                ->label('Delivery Area Name')
                ->helperText('RedX area name, e.g. from the RedX areas list.')
                ->required(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_REDX)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_REDX),
            TextInput::make('rx_delivery_area_id')
                ->label('Delivery Area ID')
                ->numeric()
                ->required(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_REDX)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_REDX),
            TextInput::make('rx_parcel_weight')
                ->label('Weight (grams)')
                ->numeric()
                ->minValue(1)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_REDX),

            // E-Courier-only
            TextInput::make('ec_recipient_city')
                ->label('City')
                ->required(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_ECOURIER)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_ECOURIER),
            TextInput::make('ec_recipient_thana')
                ->label('Thana')
                ->required(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_ECOURIER)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_ECOURIER),
            TextInput::make('ec_recipient_zip')
                ->label('Post Code')
                ->required(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_ECOURIER)
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_ECOURIER),
            TextInput::make('ec_recipient_area')
                ->label('Area')
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_ECOURIER),
            TextInput::make('ec_package_code')
                ->label('Package Code')
                ->helperText('Leave blank to use the provider default package.')
                ->visible(fn (Get $get): bool => $driverOf($get) === CourierProvider::DRIVER_ECOURIER),
        ];
    }

    /**
     * Strips the unifiedBookingForm() driver prefix back off and rebuilds
     * the flat `$data` shape each `CourierService::create*Booking()` method
     * already expects, so none of those methods needed to change.
     */
    protected static function bookingPayloadFor(CourierProvider $provider, array $data): array
    {
        $common = [
            'courier_provider_id' => $provider->getKey(),
            'tracking_id' => $data['tracking_id'] ?? null,
            'recipient_name' => $data['recipient_name'] ?? null,
            'recipient_phone' => $data['recipient_phone'] ?? null,
            'recipient_address' => $data['recipient_address'] ?? null,
            'cod_amount' => $data['cod_amount'] ?? null,
            'note' => $data['note'] ?? null,
        ];

        return match ($provider->driver) {
            CourierProvider::DRIVER_STEADFAST => [
                ...$common,
                'alternative_phone' => $data['sf_alternative_phone'] ?? null,
                'recipient_email' => $data['sf_recipient_email'] ?? null,
                'item_description' => $data['sf_item_description'] ?? null,
                'total_lot' => $data['sf_total_lot'] ?? null,
                'delivery_type' => $data['sf_delivery_type'] ?? 0,
            ],
            CourierProvider::DRIVER_PATHAO => [
                ...$common,
                'store_id' => $data['ph_store_id'] ?? null,
                'recipient_city' => $data['ph_recipient_city'] ?? null,
                'recipient_zone' => $data['ph_recipient_zone'] ?? null,
                'recipient_area' => $data['ph_recipient_area'] ?? null,
                'delivery_type' => $data['ph_delivery_type'] ?? 48,
                'item_weight' => $data['ph_item_weight'] ?? 0.5,
            ],
            CourierProvider::DRIVER_REDX => [
                ...$common,
                'delivery_area' => $data['rx_delivery_area'] ?? null,
                'delivery_area_id' => $data['rx_delivery_area_id'] ?? null,
                'parcel_weight' => $data['rx_parcel_weight'] ?? 500,
            ],
            CourierProvider::DRIVER_ECOURIER => [
                ...$common,
                'recipient_city' => $data['ec_recipient_city'] ?? null,
                'recipient_thana' => $data['ec_recipient_thana'] ?? null,
                'recipient_zip' => $data['ec_recipient_zip'] ?? null,
                'recipient_area' => $data['ec_recipient_area'] ?? null,
                'package_code' => $data['ec_package_code'] ?? null,
            ],
            default => $common, // Manual
        };
    }

    protected static function phoneContactLinks(?string $phone): ?HtmlString
    {
        $telUrl = PhoneLinks::telUrl($phone);

        if ($telUrl === null) {
            return null;
        }

        return new HtmlString(view('filament.tables.columns.phone-contact-links', [
            'telUrl' => $telUrl,
            'whatsappUrl' => PhoneLinks::whatsappUrl($phone),
        ])->render());
    }

    protected static function canBookCourier(Order $record): bool
    {
        return self::courierBookingBlocker($record) === null;
    }

    /** Why this order cannot be booked from here, or null when it can (see Order::courierBookingBlocker()). */
    protected static function courierBookingBlocker(Order $record): ?string
    {
        $context = app(CompanyContext::class);

        // Couriers belong to a company: book from inside the order's own company.
        if (! $context->hasCompany() || (int) $context->id() !== (int) $record->company_id) {
            return __('Switch to the order\'s company to book its courier.');
        }

        return $record->courierBookingBlocker();
    }
}
