<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Concerns\ForApprovedResellers;
use App\Models\Product;
use App\Services\ResellerOrderPlacementService;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Reseller portal: an approved reseller places an order for their own
 * customer. The rules (own store's products only, own price never below the
 * base price, cash on delivery, the store's delivery charge) live in
 * ResellerOrderPlacementService, which mirrors the storefront checkout.
 */
class CreateOrder extends Page
{
    use ForApprovedResellers;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.reseller.pages.create-order';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function getTitle(): string
    {
        return __('Create order');
    }

    public static function getNavigationLabel(): string
    {
        return __('Create order');
    }

    public function mount(): void
    {
        $this->form->fill(['items' => [['product_id' => null, 'quantity' => 1, 'price' => null]]]);
    }

    public function form(Schema $schema): Schema
    {
        $service = app(ResellerOrderPlacementService::class);
        $reseller = $this->reseller();
        $products = $service->catalog($reseller)->keyBy('id');

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
                            ->maxLength(40),
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

                Section::make(__('Products'))
                    ->description(__('Only products in your store can be ordered. Payment is cash on delivery.'))
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
            return app(ResellerOrderPlacementService::class)->quote($this->reseller(), $lines, (string) ($this->data['address'] ?? ''));
        } catch (ValidationException) {
            return null;
        }
    }

    public function create(): void
    {
        $data = $this->form->getState();

        $order = app(ResellerOrderPlacementService::class)->place($this->reseller(), [
            'name' => trim($data['name']),
            'phone' => trim($data['phone']),
            'address' => trim($data['address']),
            'note' => $data['note'] ?? null,
        ], $data['items']);

        Notification::make()
            ->title(__('Order :number was created. The store will review it.', ['number' => $order->order_number]))
            ->success()
            ->send();

        $this->redirect(Orders::getUrl());
    }
}
