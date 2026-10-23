<?php

namespace App\Filament\Trade\Resources\GoodsIn;

use App\Filament\Trade\Resources\DeliveryChallans\DeliveryChallanResource;
use App\Filament\Trade\Resources\GoodsIn\Pages\CreateGoodsIn;
use App\Filament\Trade\Resources\GoodsIn\Pages\ListGoodsIn;
use App\Filament\Trade\TradeErrors;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Goods in" (মাল এলো) in the Trade Portal: the ERP's purchases in plain
 * words. A purchase waits until the goods arrive; "Goods arrived" marks it
 * received and the ERP adds the sellable quantity to stock
 * (PurchaseWorkflowService). New purchases are entered here in short form:
 * supplier, date, LC no. and the items with quantity and buying price.
 * Landed costs and the import papers stay on the ERP's purchase page.
 */
class GoodsInResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static ?string $slug = 'goods-in';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('purchase');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Goods in');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.purchases') ?? false;
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
        return $schema->columns(1)->components([
            Section::make(__('Purchase'))
                ->schema([
                    Select::make('supplier_id')
                        ->label(__('Supplier'))
                        ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required()
                        ->createOptionForm([TextInput::make('name')->label(__('Supplier name'))->required()->maxLength(255)])
                        ->createOptionUsing(fn (array $data): int => (int) Supplier::query()->create(['name' => $data['name']])->getKey()),
                    DatePicker::make('purchase_date')->label(__('Date'))->default(now())->maxDate(now())->required(),
                    TextInput::make('lc_number')->label(__('LC no.'))->maxLength(255),
                    Toggle::make('arrived')->label(__('The goods are already in the shop'))->helperText(__('On: the stock goes up now. Off: it goes up when you press "Goods arrived".'))->default(false),
                    Textarea::make('note')->label(__('Note'))->rows(2)->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 2]),
            Section::make(__('Items'))
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('product_id')->label(__('Item'))->options(fn (): array => DeliveryChallanResource::productOptions())->searchable()->required()->columnSpanFull(),
                            TextInput::make('quantity')->label(__('Quantity'))->numeric()->integer()->minValue(1)->default(1)->required(),
                            TextInput::make('unit_cost')->label(__('Buying price per unit'))->numeric()->minValue(0)->default(0)->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel(__('Add an item'))
                        ->reorderable(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('purchase_date', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'items.product'])->where('status', '!=', 'cancelled'))
            ->columns([
                TextColumn::make('supplier.name')->label(__('Supplier'))->searchable()->weight('bold')->wrap(),
                TextColumn::make('purchase_date')->label(__('Date'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
                TextColumn::make('lc_number')->label(__('LC no.'))->placeholder('—')->searchable(),
                TextColumn::make('goods')
                    ->label(__('Items'))
                    ->state(fn (Purchase $record): string => $record->items->map(fn ($item): string => ($item->product?->name ?? '—').' × '.(int) $item->quantity)->implode(', '))
                    ->wrap(),
                TextColumn::make('total_amount')->label(__('Total'))->formatStateUsing(fn ($state): string => ImsNumbers::money($state)),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'received' ? __('Arrived') : __('Waiting'))
                    ->color(fn (string $state): string => $state === 'received' ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(['draft' => __('Waiting'), 'received' => __('Arrived')]),
            ])
            ->recordActions([
                Action::make('arrived')
                    ->label(__('Goods arrived'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Purchase $record): bool => $record->status === 'draft')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Purchase $record): string => __('These go into stock: :items', ['items' => $record->items->map(fn ($item): string => ($item->product?->name ?? '—').' × '.(int) $item->quantity)->implode(', ')]))
                    ->action(function (Purchase $record, Action $action): void {
                        TradeErrors::attempt(fn () => $record->update(['status' => 'received']))
                            ? Notification::make()->title(__('Goods added to stock'))->success()->send()
                            : $action->halt();
                    }),
            ])
            ->emptyStateHeading(__('No purchase yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoodsIn::route('/'),
            'create' => CreateGoodsIn::route('/create'),
        ];
    }
}
