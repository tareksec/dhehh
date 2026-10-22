<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Forms\Components\CustomerSourceSelect;
use App\Filament\Forms\Components\CustomerTypeSelect;
use App\Filament\Forms\Components\EmailInput;
use App\Filament\Forms\Components\PhoneInput;
use App\Filament\Pages\FraudChecker;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CompanyContext;
use App\Services\ExternalCourierFraudService;
use App\Services\ShippingFeeService;
use App\Support\FraudCheckReport;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class OrderForm
{
    /**
     * Every input that feeds Total amount / Due amount recalculates this many
     * milliseconds after typing stops. They used to recalculate only on blur
     * (`live(onBlur: true)`), but on a phone the keyboard is usually dismissed
     * without the field losing focus, so a typed Shipping Fee never reached
     * the Total amount (owner report, 2026-10-10).
     */
    public const TOTALS_DEBOUNCE_MS = 500;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Invoice Details')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('order_number')
                            ->label('Invoice Number')
                            ->default(fn (): string => Order::nextOrderNumber())
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Select::make('customer_id')
                            ->label('Customer')
                            ->relationship('customer', 'name', fn ($query) => $query->where('is_active', true))
                            ->searchable(['name', 'phone'])
                            ->getOptionLabelFromRecordUsing(fn (Customer $record): string => $record->phone
                                ? "{$record->name} ({$record->phone})"
                                : $record->name)
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Customer Name')
                                    ->required()
                                    ->maxLength(255),

                                PhoneInput::make(required: true),

                                CustomerTypeSelect::make(),

                                CustomerSourceSelect::make(),

                                EmailInput::make(),

                                Textarea::make('address')
                                    ->label('Address')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                return Customer::query()->create([
                                    'name' => $data['name'],
                                    'phone' => $data['phone'],
                                    'email' => $data['email'] ?? null,
                                    'address' => $data['address'] ?? null,
                                    'customer_type' => $data['customer_type'] ?? 'regular',
                                    'customer_source' => $data['customer_source'] ?? null,
                                    'is_active' => true,
                                ])->getKey();
                            })
                            ->live()
                            ->required()
                            ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                $customer = Customer::find($state);

                                if ($customer) {
                                    $set('customer_name', $customer->name);
                                }

                                self::setShippingFee($get, $set, $customer);
                                self::runExternalCourierFraudCheck($set, $customer, bypassCache: false);
                            }),

                        Hidden::make('customer_name'),

                        Flex::make([
                            Actions::make([
                                Action::make('checkExternalCourierFraud')
                                    ->label(__('Courier Fraud Check'))
                                    ->icon('heroicon-o-shield-exclamation')
                                    ->color('warning')
                                    ->action(function (Get $get, Set $set): void {
                                        $customer = Customer::find($get('customer_id'));

                                        if (! $customer?->phone) {
                                            Notification::make()
                                                ->title(__('Select a customer with a phone number first'))
                                                ->warning()
                                                ->send();

                                            return;
                                        }

                                        self::runExternalCourierFraudCheck($set, $customer, bypassCache: true);
                                    }),
                            ])->grow(false),

                            Html::make(function (Get $get): HtmlString {
                                $result = $get('external_fraud_result');
                                $customer = Customer::find($get('customer_id'));

                                if (! is_array($result) || ! $customer?->phone) {
                                    return new HtmlString('');
                                }

                                $phone = app(ExternalCourierFraudService::class)->normalizePhone($customer->phone);

                                return new HtmlString(view('filament.fraud-check.compact', [
                                    'report' => new FraudCheckReport($result, $phone, (int) (app(CompanyContext::class)->id() ?? $customer->company_id)),
                                    'fullReportUrl' => FraudChecker::canAccess() ? FraudChecker::getUrl(['phone' => $phone], panel: 'admin') : null,
                                    'storeData' => true,
                                ])->render());
                            }),
                        ])
                            // Stacks the button above the result (full width on
                            // its own line) below tablet width — a button plus
                            // a 5-column courier table never fit side by side
                            // on a phone; still sits inline next to the button
                            // from tablet width up ($fromBreakpoint's normal
                            // "from-md" contract: flex-col below md, flex-row
                            // from md up — see vendor/filament/schemas' flex.css).
                            ->from('md')
                            ->verticallyAlignCenter()
                            ->columnSpanFull(),

                        Hidden::make('external_fraud_result')
                            ->dehydrated(false),

                        DatePicker::make('order_date')
                            ->label('Sale Date')
                            ->default(now())
                            ->required(),

                        Select::make('status')
                            ->label('Order Status')
                            ->options(Order::STATUSES)
                            ->default('draft')
                            ->required()
                            ->live()
                            ->disabled(fn (?Order $record): bool => (bool) $record?->exists)
                            ->helperText(fn (?Order $record): string => $record?->exists
                                ? 'Use Change status to follow the controlled workflow.'
                                : 'Controls invoice, stock, accounts, and sales reporting.'),

                        Select::make('delivery_status')
                            ->label('Delivery Status')
                            ->options(Order::DELIVERY_STATUSES)
                            ->default('not_booked')
                            ->required()
                            ->disabled(fn (?Order $record): bool => (bool) $record?->exists)
                            ->helperText(fn (?Order $record): string => $record?->exists
                                ? 'Use Change status or courier actions to update delivery progress.'
                                : 'Controls courier progress shown on storefront tracking.'),

                        Select::make('courier_provider_id')
                            ->label('Courier')
                            // The order's own courier stays listed when it is switched off
                            // (such as the courier an imported Nuport parcel was made with),
                            // or saving the order would fail on "The selected courier is invalid".
                            ->options(fn (?Order $record): array => CourierProvider::query()
                                ->where('company_id', app(CompanyContext::class)->id())
                                ->where(fn ($providers) => $providers->where('is_active', true)
                                    ->when($record?->courier_provider_id, fn ($providers, $providerId) => $providers->orWhere('id', $providerId)))
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (CourierProvider $provider): array => [
                                    $provider->getKey() => $provider->name.($provider->is_default ? ' (Default)' : ''),
                                ])
                                ->all())
                            ->placeholder('None')
                            ->native(false)
                            ->helperText('Pre-selects which courier "Book courier" defaults to. Pre-filled from the default courier on new orders; change it anytime before booking.'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->persistCollapsed(),

                Section::make('Items')
                    ->columnSpanFull()
                    ->description(fn (?Order $record): ?string => $record?->reseller_own_courier
                        ? __('The reseller sends this order with their own courier and has paid for these items in advance, so only the reseller can add or change items, from the reseller portal.')
                        : null)
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            // Owner rule (2026-10-06): staff never add or change
                            // items on an own-courier order; a disabled repeater
                            // is not saved either.
                            ->disabled(fn (?Order $record): bool => (bool) $record?->reseller_own_courier)
                            ->table([
                                TableColumn::make('Product'),
                                TableColumn::make('Variation'),
                                TableColumn::make('Quantity'),
                                TableColumn::make('Unit Price'),
                                TableColumn::make('Subtotal'),
                            ])
                            ->schema([
                                Select::make('product_id')
                                    ->label('Product')
                                    // Active products only, except the one this line already
                                    // has: an old order's product may have been switched off
                                    // (a Nuport import creates its missing products inactive),
                                    // and its name must still show on the edit page.
                                    ->relationship('product', 'name', fn ($query, ?OrderItem $record) => $query->where(
                                        fn ($products) => $products
                                            ->where('is_active', true)
                                            ->when($record?->product_id, fn ($products, $productId) => $products->orWhere($products->getModel()->getQualifiedKeyName(), $productId)),
                                    ))
                                    ->searchable()
                                    ->live()
                                    ->required()
                                    // Admin-only tag: nothing the customer sees shows it.
                                    ->belowContent(Text::make(__('Upsell'))
                                        ->badge()
                                        ->color('success')
                                        ->visible(fn (?OrderItem $record): bool => (bool) $record?->is_upsell))
                                    ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                        $product = Product::find($state);

                                        if (! $product) {
                                            return;
                                        }

                                        $unitPrice = $product->selling_price;

                                        $set('product_variant_id', null);
                                        $set('variant_label', null);
                                        $set('unit_price', $unitPrice);
                                        $set('subtotal', (int) ($get('quantity') ?? 0) * $unitPrice);
                                        self::setOrderTotalsFromRepeater($get, $set);
                                    }),

                                Select::make('product_variant_id')
                                    ->label('Variation')
                                    ->options(function (Get $get): array {
                                        $product = Product::query()->with('activeVariants')->find($get('product_id'));

                                        if (! $product?->has_variants) {
                                            return [];
                                        }

                                        return $product->activeVariants
                                            ->mapWithKeys(fn (ProductVariant $variant): array => [
                                                $variant->getKey() => $variant->label().' (stock: '.(int) $variant->stock.')',
                                            ])
                                            ->all();
                                    })
                                    ->visible(fn (Get $get): bool => (bool) Product::query()->whereKey($get('product_id'))->value('has_variants'))
                                    ->required(fn (Get $get): bool => (bool) Product::query()->whereKey($get('product_id'))->value('has_variants'))
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                        $variant = ProductVariant::query()->find($state);

                                        if (! $variant) {
                                            $set('variant_label', null);

                                            return;
                                        }

                                        $set('variant_label', $variant->label());
                                        $set('unit_price', $variant->effectiveSalePrice());
                                        $set('subtotal', (int) ($get('quantity') ?? 0) * $variant->effectiveSalePrice());
                                        self::setOrderTotalsFromRepeater($get, $set);
                                    }),

                                Hidden::make('variant_label'),

                                TextInput::make('quantity')
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(debounce: self::TOTALS_DEBOUNCE_MS)
                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                        self::setItemSubtotal($get, $set);
                                        self::setOrderTotalsFromRepeater($get, $set);
                                    }),

                                TextInput::make('unit_price')
                                    ->numeric()
                                    ->prefix('৳')
                                    ->minValue(0)
                                    ->required()
                                    ->live(debounce: self::TOTALS_DEBOUNCE_MS)
                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                        self::setItemSubtotal($get, $set);
                                        self::setOrderTotalsFromRepeater($get, $set);
                                    }),

                                TextInput::make('subtotal')
                                    ->numeric()
                                    ->prefix('৳')
                                    ->readOnly(),
                            ])
                            ->columns(4)
                            ->defaultItems(1)
                            // An item added after the order was created is an
                            // upsell (owner request, 2026-10-05).
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, string $operation): array => $operation === 'edit'
                                ? [...$data, 'is_upsell' => true]
                                : $data)
                            ->addActionLabel('Add item')
                            ->reorderable(false)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setOrderTotals($get, $set))
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->persistCollapsed(),

                Section::make('Totals')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('subtotal')
                            ->numeric()
                            ->prefix('৳')
                            ->readOnly(),

                        TextInput::make('discount')
                            ->numeric()
                            ->prefix('৳')
                            ->default(0)
                            ->minValue(0)
                            ->required()
                            ->live(debounce: self::TOTALS_DEBOUNCE_MS)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setOrderTotals($get, $set)),

                        TextInput::make('vat')
                            ->numeric()
                            ->prefix('৳')
                            ->default(0)
                            ->minValue(0)
                            ->required()
                            ->live(debounce: self::TOTALS_DEBOUNCE_MS)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setOrderTotals($get, $set)),

                        Hidden::make('shipping_zone'),

                        TextInput::make('shipping_fee')
                            ->label('Shipping Fee')
                            ->numeric()
                            ->prefix('৳')
                            ->default(0)
                            ->minValue(0)
                            ->required()
                            ->live(debounce: self::TOTALS_DEBOUNCE_MS)
                            ->helperText(fn (Get $get): ?string => $get('shipping_zone')
                                ? 'Auto-detected zone: '.ucfirst($get('shipping_zone')).'. Adjust if needed.'
                                : 'Could not detect a zone from the customer address — set manually if needed.')
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setOrderTotals($get, $set)),

                        // Set when a reseller's own-courier order is placed
                        // (per-piece packaging charge); shown only on those.
                        TextInput::make('packaging_fee')
                            ->label(__('Packaging charge'))
                            ->numeric()
                            ->prefix('৳')
                            ->readOnly()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => (float) ($get('packaging_fee') ?? 0) > 0),

                        TextInput::make('total_amount')
                            ->numeric()
                            ->prefix('৳')
                            ->readOnly(),

                        TextInput::make('paid_amount')
                            ->numeric()
                            ->prefix('৳')
                            ->default(0)
                            ->minValue(0)
                            ->required()
                            ->live(debounce: self::TOTALS_DEBOUNCE_MS)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setOrderTotals($get, $set))
                            // Once the order exists, paid_amount is derived from the
                            // Payments History ledger (OrderPayment rows, recomputed by
                            // Order::recalculatePaidAmount()) shown on this page and on
                            // the order's view page — corrections go through that ledger
                            // (add/edit/delete a payment row) instead of typing a new
                            // total here. readOnly() alone only blocks typing though; the
                            // field's stale value (from whenever the page was loaded)
                            // would still be submitted and silently overwrite whatever
                            // the ledger had just recalculated, so dehydrated(false) on
                            // edit stops it being written back at all. Still a normal
                            // editable + saved field at creation time, which auto-seeds
                            // the first ledger row (see Order::booted()'s `created` hook).
                            ->readOnly(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation !== 'edit')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Managed from Payments History below — add, edit, or delete a payment there.'
                                : null),

                        // Which account the customer paid into (Cash, bKash,
                        // bank…). Not an order column: CreateOrder hands it to
                        // the first Payments History row, which posts the money
                        // into that account's ledger so finance updates itself.
                        Select::make('payment_account_id')
                            ->label(__('Payment Method'))
                            ->options(fn (): array => OrderPayment::accountOptions())
                            ->searchable()
                            ->native(false)
                            ->dehydrated(false)
                            ->visible(fn (string $operation): bool => $operation === 'create')
                            ->required(fn (Get $get): bool => (float) ($get('paid_amount') ?? 0) > 0 && OrderPayment::accountOptions() !== [])
                            ->helperText(__('The account the paid amount was received into. Its balance updates automatically.')),

                        TextInput::make('due_amount')
                            ->numeric()
                            ->prefix('৳')
                            ->readOnly()
                            // Always derived (from total_amount and the real, ledger-
                            // synced paid_amount) by OrderWorkflowService::sync() right
                            // after every save, on both create and edit -- never the
                            // client-computed value sitting in the form when submitted,
                            // which can be stale for the same reason paid_amount is
                            // above.
                            ->dehydrated(false),
                    ])
                    ->columns(3)
                    ->collapsible()
                    ->persistCollapsed(),

                Section::make('Note')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('note')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->persistCollapsed(),
            ]);
    }

    protected static function setItemSubtotal(Get $get, Set $set): void
    {
        $set('subtotal', (int) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0));
    }

    protected static function setOrderTotalsFromRepeater(Get $get, Set $set): void
    {
        self::setOrderTotals($get, $set, '../../');
    }

    protected static function setOrderTotals(Get $get, Set $set, string $prefix = ''): void
    {
        $items = $get($prefix.'items') ?? [];
        $subtotal = collect($items)
            ->sum(fn (array $item): float => (int) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0));
        $total = max(
            $subtotal
            - (float) ($get($prefix.'discount') ?? 0)
            + (float) ($get($prefix.'vat') ?? 0)
            + (float) ($get($prefix.'shipping_fee') ?? 0)
            + (float) ($get($prefix.'packaging_fee') ?? 0),
            0
        );
        $due = max($total - (float) ($get($prefix.'paid_amount') ?? 0), 0);

        $set($prefix.'subtotal', $subtotal);
        $set($prefix.'total_amount', $total);
        $set($prefix.'due_amount', $due);
    }

    protected static function setShippingFee(Get $get, Set $set, ?Customer $customer): void
    {
        $company = app(CompanyContext::class)->company();

        if (! $customer || ! $company) {
            return;
        }

        $result = app(ShippingFeeService::class)->feeFor($customer->address, $company);

        $set('shipping_zone', $result['zone']);
        $set('shipping_fee', $result['fee']);
        self::setOrderTotals($get, $set);
    }

    /**
     * Shared by both the manual "Courier Fraud Check" button and the
     * automatic check that runs when a customer is selected. The automatic
     * path uses the 24h cache (bypassCache: false) so switching between
     * customers while building an order never spams the courier portals.
     */
    protected static function runExternalCourierFraudCheck(Set $set, ?Customer $customer, bool $bypassCache): void
    {
        if (! $customer?->phone) {
            return;
        }

        $result = app(ExternalCourierFraudService::class)->checkByPhone(
            $customer->phone,
            app(CompanyContext::class)->id() ?? $customer->company_id,
            $customer->getKey(),
            bypassCache: $bypassCache,
        );

        $set('external_fraud_result', $result);
    }
}
