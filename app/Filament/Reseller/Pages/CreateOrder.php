<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Concerns\ForApprovedResellers;
use App\Models\Product;
use App\Models\StorefrontPaymentMethod;
use App\Services\ExternalCourierFraudService;
use App\Services\ResellerOrderPlacementService;
use App\Services\ResellerOwnCourierService;
use App\Support\FraudCheckReport;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Reseller portal: an approved reseller places an order for their own
 * customer. The rules (own store's products only, own price never below the
 * base price, cash on delivery, the store's delivery charge) live in
 * ResellerOrderPlacementService, which mirrors the storefront checkout.
 *
 * Typing the customer's phone runs the courier fraud check and shows the
 * same card as the admin order form (filament.fraud-check.compact).
 *
 * Or the reseller sends it with their own courier: they give their parcel ID
 * and pay the full amount in advance, and the store packs the parcel with
 * that ID (ResellerOwnCourierService).
 */
class CreateOrder extends Page
{
    use ForApprovedResellers;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    /** Reached from the button on the Orders page, not from the menu. */
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.reseller.pages.create-order';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null courier fraud check for $fraudPhone */
    public ?array $fraudResult = null;

    public ?string $fraudPhone = null;

    public function getTitle(): string
    {
        return __('Create order');
    }

    public function mount(): void
    {
        $this->form->fill([
            'delivery_method' => 'store',
            'items' => [['product_id' => null, 'quantity' => 1, 'price' => null]],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $service = app(ResellerOrderPlacementService::class);
        $reseller = $this->reseller();
        $products = $service->catalog($reseller)->keyBy('id');
        $paymentMethods = app(ResellerOwnCourierService::class)->paymentMethods($reseller->company)->keyBy('id');
        $ownCourier = fn (Get $get): bool => $get('delivery_method') === 'own';

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Customer'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Customer name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label(__('Phone'))
                            ->tel()
                            ->required()
                            ->maxLength(40)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state) => $this->checkCourierFraud($state)),
                        Html::make(fn (): HtmlString => $this->fraudCheckCard())
                            ->columnSpanFull(),
                        Textarea::make('address')
                            ->label(__('Delivery address'))
                            ->required()
                            ->maxLength(1000)
                            ->rows(2)
                            ->live(onBlur: true)
                            ->columnSpanFull(),
                        Textarea::make('note')
                            ->label(__('Note (optional)'))
                            ->maxLength(1000)
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Delivery'))
                    ->schema([
                        Radio::make('delivery_method')
                            ->hiddenLabel()
                            ->options([
                                'store' => __('The store delivers (cash on delivery)'),
                                'own' => __('My own courier (I pay the full amount in advance)'),
                            ])
                            ->disableOptionWhen(fn (string $value): bool => $value === 'own' && $paymentMethods->isEmpty())
                            ->helperText($paymentMethods->isEmpty() ? __('Own courier orders are not available yet: the store has no payment method set up.') : null)
                            ->default('store')
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('courier_name')
                            ->label(__('Courier name'))
                            ->maxLength(100)
                            ->visible($ownCourier),
                        TextInput::make('parcel_id')
                            ->label(__('Parcel ID'))
                            ->helperText(__('The parcel or consignment ID from your courier. The store puts it on the package.'))
                            ->required($ownCourier)
                            ->maxLength(100)
                            ->visible($ownCourier),
                    ])
                    ->columns(2),

                Section::make(__('Advance payment'))
                    ->description(__('Send the full amount shown in the order summary, then enter the payment details. The store packs the parcel after checking the payment.'))
                    ->visible($ownCourier)
                    ->schema([
                        Select::make('payment_method_id')
                            ->label(__('Paid to'))
                            ->options(fn (): array => $paymentMethods->mapWithKeys(fn (StorefrontPaymentMethod $method): array => [
                                $method->getKey() => trim($method->name.($method->account_number ? ' — '.$method->account_number : '')),
                            ])->all())
                            ->required($ownCourier)
                            ->live()
                            ->helperText(fn (Get $get): ?string => $paymentMethods->get((int) $get('payment_method_id'))?->instructions)
                            ->columnSpanFull(),
                        TextInput::make('sender')
                            ->label(__('Paid from (number or account)'))
                            ->required($ownCourier)
                            ->maxLength(40),
                        TextInput::make('transaction_id')
                            ->label(__('Transaction ID'))
                            ->required($ownCourier)
                            ->maxLength(100),
                    ])
                    ->columns(2),

                Section::make(__('Products'))
                    ->description(fn (Get $get): string => $get('delivery_method') === 'own'
                        ? __('Only products in your store can be ordered. You pay the store price; there is no commission on these orders.')
                        : __('Only products in your store can be ordered. Payment is cash on delivery.'))
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->addActionLabel(__('Add product'))
                            ->minItems(1)
                            ->live()
                            ->schema([
                                Select::make('product_id')
                                    ->label(__('Product'))
                                    ->options(fn (): array => $products->mapWithKeys(fn (Product $product): array => [
                                        $product->getKey() => $product->name.' ('.__('Stock').': '.(int) $product->stock.')',
                                    ])->all())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?string $state) use ($products, $service, $reseller): void {
                                        $product = $products->get((int) $state);
                                        $set('price', $product ? $service->defaultPrice($reseller, $product) : null);
                                    })
                                    ->columnSpan(3),
                                TextInput::make('quantity')
                                    ->label(__('Quantity'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(1),
                                TextInput::make('price')
                                    ->label(__('Your price'))
                                    ->numeric()
                                    ->minValue(fn (Get $get): ?float => ($product = $products->get((int) $get('product_id'))) ? $service->basePrice($reseller, $product) : null)
                                    ->disabled(fn (Get $get): bool => ($product = $products->get((int) $get('product_id'))) === null || $service->basePrice($reseller, $product) === null)
                                    ->dehydrated()
                                    ->helperText(fn (Get $get): ?string => ($product = $products->get((int) $get('product_id'))) && ($base = $service->basePrice($reseller, $product)) !== null
                                        ? __('At least :amount.', ['amount' => MoneyFormatter::number($base)])
                                        : null)
                                    ->live(onBlur: true)
                                    ->visible(fn (Get $get): bool => $get('../../delivery_method') !== 'own')
                                    ->columnSpan(2),
                            ])
                            ->columns(6),
                    ]),
            ]);
    }

    /**
     * Live totals for the summary box, or null while the form is not complete
     * enough to price (a half-filled line must not break the page).
     *
     * @return array<string, mixed>|null
     */
    public function summary(): ?array
    {
        $lines = collect($this->data['items'] ?? [])
            ->filter(fn ($line): bool => is_array($line) && filled($line['product_id'] ?? null))
            ->all();

        if ($lines === []) {
            return null;
        }

        try {
            return app(ResellerOrderPlacementService::class)->quote($this->reseller(), $lines, (string) ($this->data['address'] ?? ''), $this->isOwnCourier());
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Runs when the phone field loses focus: the same courier fraud check
     * as the admin order form, on the store's courier accounts. Always from
     * the 24h cache when there is one — resellers get no cache-skipping
     * refresh (see FraudChecker).
     */
    public function checkCourierFraud(?string $phone): void
    {
        $phone = trim((string) $phone);

        if (! preg_match(FraudChecker::PHONE_PATTERN, $phone)) {
            $this->fraudResult = null;
            $this->fraudPhone = null;

            return;
        }

        $service = app(ExternalCourierFraudService::class);
        $this->fraudPhone = $service->normalizePhone($phone);
        $this->fraudResult = $service->checkByPhone($this->fraudPhone, (int) $this->reseller()->company_id);
    }

    protected function fraudCheckCard(): HtmlString
    {
        if ($this->fraudResult === null || $this->fraudPhone === null) {
            return new HtmlString('');
        }

        return new HtmlString(view('filament.fraud-check.compact', [
            'report' => new FraudCheckReport($this->fraudResult, $this->fraudPhone, (int) $this->reseller()->company_id),
            'fullReportUrl' => FraudChecker::getUrl(['phone' => $this->fraudPhone], panel: 'reseller'),
            'storeData' => false,
        ])->render());
    }

    public function isOwnCourier(): bool
    {
        return ($this->data['delivery_method'] ?? 'store') === 'own';
    }

    public function create(): void
    {
        $data = $this->form->getState();

        $order = app(ResellerOrderPlacementService::class)->place($this->reseller(), [
            'name' => trim($data['name']),
            'phone' => trim($data['phone']),
            'address' => trim($data['address']),
            'note' => $data['note'] ?? null,
        ], $data['items'], $this->isOwnCourier() ? [
            'courier_name' => $data['courier_name'] ?? null,
            'parcel_id' => (string) ($data['parcel_id'] ?? ''),
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'sender' => (string) ($data['sender'] ?? ''),
            'transaction_id' => (string) ($data['transaction_id'] ?? ''),
        ] : null);

        Notification::make()
            ->title($this->isOwnCourier()
                ? __('Order :number was created. The store will check your payment and pack the parcel.', ['number' => $order->order_number])
                : __('Order :number was created. The store will review it.', ['number' => $order->order_number]))
            ->success()
            ->send();

        $this->redirect(Orders::getUrl());
    }
}
