<?php

namespace App\Filament\Resources\InvestmentProjects\RelationManagers;

use App\Models\ExchangeRate;
use App\Models\ProjectCostItem;
use App\Services\CompanyStorageService;
use App\Support\CompanyMedia;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class CostItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'costItems';

    public function isReadOnly(): bool
    {
        return $this->getOwnerRecord()->status === 'settled' || parent::isReadOnly();
    }

    public function form(Schema $schema): Schema
    {
        $foreign = fn (Get $get): bool => filled($get('currency')) && $get('currency') !== ExchangeRate::BASE;

        // The newest rate on file for the cost's day; staff may overwrite it.
        $fillRate = function (Get $get, Set $set): void {
            $currency = $get('currency');

            if (blank($currency) || $currency === ExchangeRate::BASE) {
                return;
            }

            $set('exchange_rate', ExchangeRate::rateOn((string) $currency, $get('cost_date') ?: now()->toDateString()));
        };

        // BDT shown to staff while they type; the model recomputes it on save.
        $fillAmount = function (Get $get, Set $set): void {
            $original = (float) $get('original_amount');
            $rate = (float) $get('exchange_rate');

            $set('amount', $original > 0 && $rate > 0 ? round($original * $rate, 2) : null);
        };

        return $schema->components([
            Select::make('category')->options(ProjectCostItem::CATEGORIES)->required(),
            TextInput::make('label')->required(),
            Select::make('currency')
                ->label(__('Currency'))
                ->options([ExchangeRate::BASE => __('BDT (Bangladeshi Taka)')] + collect(ExchangeRate::CURRENCIES)->map(fn (string $label): string => __($label))->all())
                ->default(ExchangeRate::BASE)
                ->formatStateUsing(fn (?string $state): string => $state ?: ExchangeRate::BASE)
                ->selectablePlaceholder(false)
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set) use ($fillRate, $fillAmount): void {
                    $fillRate($get, $set);
                    $fillAmount($get, $set);
                }),
            DatePicker::make('cost_date')
                ->label(__('Cost date'))
                ->default(now())
                ->visible($foreign)
                ->required($foreign)
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set) use ($fillRate, $fillAmount): void {
                    $fillRate($get, $set);
                    $fillAmount($get, $set);
                }),
            TextInput::make('original_amount')
                ->label(fn (Get $get): string => __('Amount in :currency', ['currency' => $get('currency')]))
                ->numeric()->minValue(0.01)
                ->visible($foreign)
                ->required($foreign)
                ->live(debounce: 500)
                ->afterStateUpdated($fillAmount),
            TextInput::make('exchange_rate')
                ->label(__('Rate (BDT for 1 unit)'))
                ->numeric()->minValue(0.000001)
                ->visible($foreign)
                ->required($foreign)
                ->live(debounce: 500)
                ->afterStateUpdated($fillAmount)
                ->helperText(function (Get $get): ?string {
                    if (blank($get('currency')) || $get('currency') === ExchangeRate::BASE) {
                        return null;
                    }

                    $onFile = ExchangeRate::rateOn((string) $get('currency'), $get('cost_date') ?: now()->toDateString());

                    return $onFile !== null
                        ? __('Rate on file for this date: :rate. You can change it.', ['rate' => rtrim(rtrim(number_format($onFile, 6), '0'), '.')])
                        : __('No rate on file for this date: type the rate you used, or add it under Exchange Rates.');
                }),
            TextInput::make('amount')
                ->label(fn (Get $get): string => $foreign($get) ? __('Amount in BDT (worked out)') : __('Amount'))
                ->numeric()->prefix('৳')->minValue(0)
                ->readOnly($foreign)
                ->required(fn (Get $get): bool => ! $foreign($get)),
            Select::make('purchase_id')->relationship('purchase', 'purchase_number')->searchable()->preload(),
            Textarea::make('remarks')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->withCount('documents'))->columns([
            TextColumn::make('category')
                ->badge()
                ->formatStateUsing(fn (string $state): string => ProjectCostItem::CATEGORIES[$state])
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('label')->searchable(),
            TextColumn::make('purchase.purchase_number')->label('Purchase')->placeholder('-'),
            TextColumn::make('original_amount')
                ->label(__('Original amount'))
                ->state(fn (ProjectCostItem $record): ?string => $record->isForeign()
                    ? $record->currency.' '.number_format((float) $record->original_amount, 2).' @ '.rtrim(rtrim(number_format((float) $record->exchange_rate, 6), '0'), '.')
                    : null)
                ->placeholder('-'),
            TextColumn::make('amount')->moneyWithoutTrailingZeroes('BDT')->summarize(Sum::make()->moneyWithoutTrailingZeroes('BDT')),
            TextColumn::make('proof')
                ->label('Proof')
                ->badge()
                ->state(fn (ProjectCostItem $record): string => filled($record->purchase_id)
                    ? 'Purchase linked'
                    : (($record->documents_count ?? 0) > 0 ? 'Receipt attached' : 'Missing'))
                ->color(fn (string $state): string => $state === 'Missing' ? 'danger' : 'success'),
            TextColumn::make('remarks')->limit(40)->placeholder('-'),
        ])->groups([
            Group::make('category')
                ->label('Cost Category')
                ->getTitleFromRecordUsing(fn (ProjectCostItem $record): string => ProjectCostItem::CATEGORIES[$record->category])
                ->collapsible(),
        ])->defaultGroup('category')
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data): array => [...$data, 'company_id' => $this->getOwnerRecord()->company_id])])
            ->recordActions([
                Action::make('attachReceipt')
                    ->label('Attach Receipt')
                    ->icon('heroicon-o-paper-clip')
                    ->modalHeading(fn (ProjectCostItem $record): string => "Receipt — {$record->label}")
                    ->schema([
                        TextInput::make('label')->maxLength(120)->placeholder('e.g. C&F invoice'),
                        FileUpload::make('file_path')
                            ->label('Receipt / Invoice')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(10240)
                            ->disk(fn (): string => app(CompanyStorageService::class)->privateDiskName())
                            ->directory(fn (): string => app(CompanyStorageService::class)->privateDirectory(CompanyMedia::require($this->getOwnerRecord()), 'investment-documents'))
                            ->visibility('private')
                            ->previewable(false)
                            ->required(),
                    ])
                    ->action(function (ProjectCostItem $record, array $data): void {
                        $record->documents()->create([
                            'company_id' => $record->company_id,
                            'category' => 'cost_receipt',
                            'label' => $data['label'] ?? null,
                            'file_path' => $data['file_path'],
                            'uploaded_by' => auth()->id(),
                        ]);
                    }),
                Action::make('downloadReceipt')
                    ->label('View Receipt')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (ProjectCostItem $record): bool => ($record->documents_count ?? 0) > 0)
                    ->url(fn (ProjectCostItem $record): ?string => ($doc = $record->documents()->latest('id')->first())
                        ? route('investment-documents.download', $doc)
                        : null)
                    ->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
