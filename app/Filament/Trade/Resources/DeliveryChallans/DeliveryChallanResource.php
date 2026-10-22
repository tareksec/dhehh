<?php

namespace App\Filament\Trade\Resources\DeliveryChallans;

use App\Filament\Trade\Resources\DeliveryChallans\Pages\CreateDeliveryChallan;
use App\Filament\Trade\Resources\DeliveryChallans\Pages\ListDeliveryChallans;
use App\Filament\Trade\Resources\DeliveryChallans\Pages\ViewDeliveryChallan;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Services\CompanyContext;
use App\Services\DeliveryChallanService;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Delivery Challans in the Trade Portal (docs/tasneem-samples/PLAN.md §4): the
 * list, writing a new one (the sale) and one challan's page with its money,
 * print, "receive money" and cancel. The work is done by DeliveryChallanService.
 */
class DeliveryChallanResource extends Resource
{
    protected static ?string $model = DeliveryChallan::class;

    protected static ?string $slug = 'challans';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('Delivery Challan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sales (challans)');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.sales') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $company = app(CompanyContext::class)->company();

        return $schema->columns(1)->components([
            Section::make(__('Challan and buyer'))
                ->schema([
                    TextInput::make('challan_number')
                        ->label(__('Challan SL no.'))
                        ->default(fn (): string => app(DeliveryChallanService::class)->nextNumber())
                        ->helperText(__('The next number of your pad. Change it if your pad is at another number.'))
                        ->required()
                        ->maxLength(30),
                    DatePicker::make('challan_date')->label(__('Date'))->default(now())->maxDate(now())->required(),
                    Select::make('customer_id')
                        ->label(__('Buyer from your list'))
                        ->helperText(__('Leave empty for a new buyer and write the name below.'))
                        ->options(fn (): array => Customer::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $customer = $state ? Customer::query()->find($state) : null;

                            if ($customer) {
                                $set('buyer_name', $customer->name);
                                $set('buyer_phone', $customer->phone);
                                $set('buyer_address', $customer->address);
                            }
                        })
                        ->columnSpanFull(),
                    TextInput::make('buyer_name')->label(__('Buyer / Party name'))->required()->maxLength(255),
                    TextInput::make('buyer_phone')->label(__('Phone'))->tel()->maxLength(30),
                    Textarea::make('buyer_address')->label(__('Address'))->rows(2)->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 2]),

            Section::make(__('Goods'))
                ->description(__('One line for each thing written on the challan. Choose the item from stock so the stock goes down. Put a price only on the lines that are sold for money; things given with a machine need no price.'))
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('product_id')
                                ->label(__('Item from stock'))
                                ->options(fn (): array => static::productOptions())
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                    $product = $state ? Product::query()->find($state) : null;

                                    if ($product) {
                                        if (blank($get('description'))) {
                                            $set('description', $product->name);
                                        }

                                        if (blank($get('unit'))) {
                                            $set('unit', $product->unit);
                                        }
                                    }
                                })
                                ->columnSpanFull(),
                            Textarea::make('description')->label(__('Description of goods'))->rows(2)->required()->columnSpanFull(),
                            TextInput::make('quantity')->label(__('Quantity'))->numeric()->integer()->minValue(0)->default(1)->live(onBlur: true),
                            TextInput::make('unit')->label(__('Unit'))->placeholder(__('set, pcs, ctn'))->maxLength(30),
                            TextInput::make('unit_price')->label(__('Price per unit'))->numeric()->minValue(0)->live(onBlur: true),
                            TextInput::make('remarks')->label(__('Remarks'))->maxLength(255),
                        ])
                        ->columns(['default' => 2, 'md' => 4])
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel(__('Add a line'))
                        ->reorderable(false)
                        ->live(),
                    Text::make(fn (Get $get): string => __('Total price: :total', ['total' => ImsNumbers::money(static::totalOf($get('items') ?? []))]))
                        ->weight('bold'),
                ]),

            Section::make(__('Money'))
                ->schema([
                    TextInput::make('advance_amount')->label(__('Advance'))->numeric()->minValue(0)->default(0)->live(onBlur: true),
                    DatePicker::make('advance_date')->label(__('Advance date'))->maxDate(now()),
                    TextInput::make('advance_note')->label(__('Advance note'))->placeholder(__('e.g. given to whom'))->maxLength(255)->columnSpanFull(),
                    TextInput::make('cash_amount')->label(__('Cash paid'))->numeric()->minValue(0)->default(0)->live(onBlur: true),
                    DatePicker::make('cash_date')->label(__('Cash paid date'))->maxDate(now()),
                    Select::make('payment_account_id')
                        ->label(__('Where the money went'))
                        ->options(fn (): array => OrderPayment::accountOptions())
                        ->helperText(__('Cash box or bank account, so the money shows in the cash book. Needed when anything was paid.'))
                        ->required(fn (Get $get): bool => (float) $get('advance_amount') + (float) $get('cash_amount') > 0)
                        ->columnSpanFull(),
                    Text::make(fn (Get $get): string => __('Due: :due', ['due' => ImsNumbers::money(max(static::totalOf($get('items') ?? []) - (float) $get('advance_amount') - (float) $get('cash_amount'), 0))]))
                        ->weight('bold')
                        ->columnSpanFull(),
                    TextInput::make('cheque_number')->label(__('Cheque no.'))->maxLength(255),
                    TextInput::make('cheque_bank')->label(__('Cheque bank'))->maxLength(255),
                    Textarea::make('due_terms')->label(__('When the due will be paid'))->placeholder(__('e.g. within the next 2 months'))->rows(2)->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 2]),

            Section::make(__('Signed by'))
                ->schema([
                    TextInput::make('authorised_name')->label(__('Name'))->default(fn (): ?string => $company?->signatory_name ?: auth()->user()?->name)->maxLength(255),
                    TextInput::make('authorised_designation')->label(__('Designation'))->default(fn (): ?string => $company?->signatory_title)->maxLength(255),
                    TextInput::make('authorised_phone')->label(__('Phone'))->default(fn (): ?string => $company?->phone)->maxLength(30),
                    Textarea::make('note')->label(__('Note'))->rows(2)->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->collapsed(),
        ]);
    }

    /** @param  array<int|string, array<string, mixed>>  $items */
    public static function totalOf(array $items): float
    {
        return round(array_sum(array_map(fn (array $item): float => (int) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0), $items)), 2);
    }

    /** @return array<int, string> items without variations, with how many are left */
    public static function productOptions(): array
    {
        return Product::query()
            ->where('has_variants', false)
            ->where('status', '!=', Product::STATUS_COMING_SOON)
            ->orderBy('name')
            ->get(['id', 'name', 'stock', 'unit'])
            ->mapWithKeys(fn (Product $product): array => [$product->getKey() => __(':name (:stock :unit left)', [
                'name' => $product->name,
                'stock' => (int) $product->stock,
                'unit' => $product->unit ?: 'pcs',
            ])])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('order'))
            ->columns([
                TextColumn::make('challan_number')->label(__('SL no.'))->searchable()->weight('bold'),
                TextColumn::make('buyer_name')->label(__('Buyer'))->searchable()->wrap(),
                TextColumn::make('challan_date')->label(__('Date'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
                TextColumn::make('total')->label(__('Total'))->formatStateUsing(fn ($state): string => ImsNumbers::money($state)),
                TextColumn::make('due')
                    ->label(__('Due now'))
                    ->state(fn (DeliveryChallan $record): float => $record->dueNow())
                    ->formatStateUsing(fn ($state): string => ImsNumbers::money($state))
                    ->color(fn ($state): string => (float) $state > 0 ? 'warning' : 'success'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === DeliveryChallan::STATUS_CANCELLED ? __('Cancelled') : __('Given'))
                    ->color(fn (string $state): string => $state === DeliveryChallan::STATUS_CANCELLED ? 'gray' : 'success'),
            ])
            ->filters([
                Filter::make('with_due')
                    ->label(__('Only with due'))
                    ->query(fn (Builder $query): Builder => $query->where('status', DeliveryChallan::STATUS_CONFIRMED)->whereHas('order', fn (Builder $order) => $order->where('due_amount', '>', 0))),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No challan yet'))
            ->emptyStateDescription(__('Write your first Delivery Challan with the button above.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryChallans::route('/'),
            'create' => CreateDeliveryChallan::route('/create'),
            'view' => ViewDeliveryChallan::route('/{record}'),
        ];
    }
}
