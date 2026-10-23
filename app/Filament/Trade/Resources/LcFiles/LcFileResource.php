<?php

namespace App\Filament\Trade\Resources\LcFiles;

use App\Filament\Trade\Resources\LcFiles\Pages\CreateLcFile;
use App\Filament\Trade\Resources\LcFiles\Pages\EditLcFile;
use App\Filament\Trade\Resources\LcFiles\Pages\LandedCost;
use App\Filament\Trade\Resources\LcFiles\Pages\ListLcFiles;
use App\Filament\Trade\Resources\LcFiles\Pages\ViewLcFile;
use App\Models\LcFile;
use App\Models\Purchase;
use App\Services\CompanyContext;
use App\Services\LcFileService;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * LC files in the Trade Portal (docs/tasneem-samples/PLAN.md §3.2, phase 4):
 * the list of imports with their stage, one form for everything the import
 * papers need (saved by LcFileService to the purchase, the supplier and the
 * LC file), and a page per file with its stage and the papers to print.
 * Needs "trade.lc".
 */
class LcFileResource extends Resource
{
    protected static ?string $model = LcFile::class;

    protected static ?string $slug = 'lc-files';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAsiaAustralia;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('LC file');
    }

    public static function getPluralModelLabel(): string
    {
        return __('LC files');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.lc') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit($record): bool
    {
        return static::canAccess();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public static function stageOptions(): array
    {
        return collect(LcFile::STAGES)->map(fn (string $label): string => __($label))->all();
    }

    /** @return array<string, string> */
    public static function docStatusOptions(): array
    {
        return collect(LcFile::DOC_STATUSES)->map(fn (string $label): string => __($label))->all();
    }

    public static function form(Schema $schema): Schema
    {
        $company = app(CompanyContext::class)->company();
        $banks = collect($company?->bank_accounts ?? [])->filter(fn ($bank): bool => is_array($bank) && filled($bank['bank_name'] ?? null))->values();

        return $schema->columns(1)->components([
            Section::make(__('Purchase and PI'))
                ->schema([
                    Select::make('purchase_id')
                        ->label(__('Purchase (from Goods in)'))
                        ->helperText(__('Enter the PI as a purchase in Goods in first: supplier, items and quantity.'))
                        ->options(fn (): array => Purchase::query()->with('supplier')->whereDoesntHave('lcFile')->where('status', '!=', 'cancelled')->latest('id')->get()
                            ->mapWithKeys(fn (Purchase $purchase): array => [$purchase->getKey() => trim(($purchase->supplier?->name ?? '—').' · '.ImsNumbers::date($purchase->purchase_date).' · '.$purchase->purchase_number)])
                            ->all())
                        ->searchable()
                        ->required()
                        ->live()
                        // The supplier's saved details fill the form, so saving a second LC
                        // for the same supplier does not blank them (LcFileService writes the form back).
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $supplier = $state ? Purchase::query()->with('supplier')->find($state)?->supplier : null;

                            foreach (LcFileService::SUPPLIER_FIELDS as $field => $column) {
                                $set($field, $supplier?->{$column});
                            }
                        })
                        ->visibleOn('create')
                        ->columnSpanFull(),
                    TextInput::make('pi_number')->label(__('PI no.'))->maxLength(255),
                    DatePicker::make('pi_date')->label(__('PI date')),
                    TextInput::make('lc_value_usd')->label(__('LC value (USD)'))->numeric()->minValue(0)->helperText(__('FOB + freight, as on the PI (CFR).')),
                    TextInput::make('delivery_terms')->label(__('Delivery terms'))->placeholder('CFR CHATTOGRAM BY SEA')->maxLength(255),
                    Textarea::make('goods_description')->label(__('Description of goods'))->helperText(__('As the papers write it, with brand and model.'))->rows(3)->columnSpanFull(),
                    Toggle::make('goods_used')->label(__('The goods are used machines'))->helperText(__('Adds "THE GOODS ARE USED MACHINE" to the Beneficiary\'s Certificate.')),
                    TextInput::make('country_of_origin')->label(__('Country of origin'))->placeholder('CHINA')->maxLength(255),
                ])
                ->columns(['default' => 1, 'md' => 2]),

            Section::make(__('Supplier'))
                ->description(__('Printed at the top of the papers the supplier issues (certificate, packing list, shipment advice).'))
                ->schema([
                    TextInput::make('supplier_company_name')->label(__('Supplier company name'))->maxLength(255),
                    TextInput::make('supplier_country')->label(__('Country'))->maxLength(255),
                    Textarea::make('supplier_address')->label(__('Address'))->rows(2)->columnSpanFull(),
                    TextInput::make('supplier_phone')->label(__('Phone'))->maxLength(255),
                    TextInput::make('supplier_email')->label(__('E-mail'))->email()->maxLength(255),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->collapsed(),

            Section::make(__('LC and bank'))
                ->schema([
                    TextInput::make('lc_number')->label(__('LC no.'))->maxLength(255),
                    DatePicker::make('lc_date')->label(__('LC date')),
                    Textarea::make('lc_bank')
                        ->label(__('LC opening bank (as on the letter)'))
                        ->placeholder("NCC Islamic Banking Gulshan Branch,\nDhaka")
                        ->rows(2)
                        ->columnSpanFull(),
                    Select::make('bank_account_pick')
                        ->label(__('Fill the account from your Business profile'))
                        ->options($banks->mapWithKeys(fn (array $bank, int $index): array => [$index => trim(($bank['bank_name'] ?? '').' '.($bank['branch'] ?? '').' · '.($bank['account_number'] ?? ''))])->all())
                        ->dehydrated(false)
                        ->live()
                        ->visible($banks->isNotEmpty())
                        ->afterStateUpdated(function ($state, Set $set) use ($banks): void {
                            $bank = $banks->get((int) $state);

                            if ($bank) {
                                $set('lc_account_name', $bank['account_name'] ?? null);
                                $set('lc_account_number', $bank['account_number'] ?? null);
                            }
                        })
                        ->columnSpanFull(),
                    TextInput::make('lc_account_name')->label(__('Account name'))->maxLength(255),
                    TextInput::make('lc_account_number')->label(__('Account number'))->maxLength(255),
                    TextInput::make('margin_percent')->label(__('Margin %'))->numeric()->minValue(0)->maxValue(100),
                    TextInput::make('margin_amount_bdt')->label(__('Margin paid (Taka)'))->numeric()->minValue(0),
                    DatePicker::make('opening_letter_date')->label(__('Opening letter date')),
                    TextInput::make('bank_branch')->label(__("Bank's trade office"))->placeholder('NCC BANK BHABAN BRANCH(NITS), DHAKA BD BANGLADESH')->maxLength(255),
                    TextInput::make('bank_bin')->label(__("Bank's BIN"))->placeholder('000483356-0202')->maxLength(255),
                ])
                ->columns(['default' => 1, 'md' => 2]),

            Section::make(__('Insurance and inspection'))
                ->schema([
                    TextInput::make('insurer_name')->label(__('Insurance company'))->maxLength(255),
                    Textarea::make('insurer_address')->label(__('Insurance company address'))->rows(2),
                    TextInput::make('cover_note_number')->label(__('Marine cover note no.'))->maxLength(255),
                    DatePicker::make('cover_note_date')->label(__('Cover note date')),
                    TextInput::make('psi_agency')->label(__('Inspection agency (PSI)'))->placeholder('Intertek')->maxLength(255),
                    DatePicker::make('psi_date')->label(__('Inspection date')),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->collapsed(),

            Section::make(__('Shipment'))
                ->description(__('Weights are for this shipment only.'))
                ->schema([
                    TextInput::make('port_of_loading')->label(__('Port of loading'))->placeholder('FROM ANY SEAPORT OF CHINA')->maxLength(255),
                    TextInput::make('port_of_shipment')->label(__('Port of shipment (actual)'))->placeholder('NINGBO, CHINA')->maxLength(255),
                    TextInput::make('port_of_discharge')->label(__('Port of discharge'))->placeholder('CHATTOGRAM SEAPORT, BANGLADESH')->maxLength(255),
                    TextInput::make('place_of_delivery')->label(__('Place of delivery'))->maxLength(255),
                    TextInput::make('carrier')->label(__('Carrier'))->placeholder('MAERSK')->maxLength(255),
                    TextInput::make('vessel_name')->label(__('Vessel name'))->maxLength(255),
                    DatePicker::make('etd')->label(__('ETD (leaves)')),
                    DatePicker::make('eta')->label(__('ETA (arrives)')),
                    TextInput::make('bl_number')->label(__('Bill of lading no.'))->maxLength(255),
                    DatePicker::make('bl_date')->label(__('Bill of lading date')),
                    TextInput::make('container_number')->label(__('Container no.'))->maxLength(255),
                    TextInput::make('seal_number')->label(__('Seal no.'))->maxLength(255),
                    TextInput::make('total_packages')->label(__('Total packages'))->placeholder('8 SETS WITH ALL STANDARD ACCESSORIES')->maxLength(255)->columnSpanFull(),
                    TextInput::make('net_weight_kg')->label(__('Net weight (KGS)'))->numeric()->minValue(0),
                    TextInput::make('gross_weight_kg')->label(__('Gross weight (KGS)'))->numeric()->minValue(0),
                    TextInput::make('cbm')->label(__('CBM'))->numeric()->minValue(0),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->collapsed(),

            Section::make(__('Shipping papers'))
                ->schema([
                    TextInput::make('ci_number')->label(__('Commercial Invoice no.'))->maxLength(255),
                    DatePicker::make('ci_date')->label(__('Commercial Invoice date')),
                    DatePicker::make('certificate_date')->label(__('Date of the certificate and packing list')),
                    DatePicker::make('shipment_advice_date')->label(__('Shipment advice date')),
                    DatePicker::make('drl_date')->label(__('DRL date')),
                    Textarea::make('note')->label(__('Note'))->rows(2)->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->collapsed(),

            Section::make(__('Where it stands'))
                ->schema([
                    Select::make('stage')->label(__('Stage'))->options(static::stageOptions())->default(array_key_first(LcFile::STAGES))->required(),
                    Select::make('doc_status')->label(__('Documents at the bank'))->options(static::docStatusOptions())->default(array_key_first(LcFile::DOC_STATUSES))->required(),
                ])
                ->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['purchase.supplier', 'purchase.items']))
            ->columns([
                TextColumn::make('purchase.lc_number')->label(__('LC no.'))->placeholder(__('Not opened yet'))->searchable()->weight('bold'),
                TextColumn::make('purchase.supplier.name')->label(__('Supplier'))->searchable()->wrap(),
                TextColumn::make('value')->label(__('LC value (USD)'))->state(fn (LcFile $record): string => '$ '.number_format($record->lcValue(), 2)),
                TextColumn::make('stage')->label(__('Stage'))->badge()->formatStateUsing(fn (?string $state): string => LcFile::stageLabel($state))
                    ->color(fn (?string $state): string => $state === 'cleared' ? 'success' : 'info'),
                TextColumn::make('doc_status')->label(__('Documents at the bank'))->badge()->formatStateUsing(fn (?string $state): string => LcFile::docStatusLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'released' => 'success',
                        'discrepancy_pending' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('stage')->label(__('Stage'))->options(static::stageOptions()),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No LC file yet'))
            ->emptyStateDescription(__('Enter the PI in Goods in, then open its LC file here.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLcFiles::route('/'),
            'create' => CreateLcFile::route('/create'),
            'view' => ViewLcFile::route('/{record}'),
            'edit' => EditLcFile::route('/{record}/edit'),
            'landed-cost' => LandedCost::route('/{record}/landed-cost'),
        ];
    }
}
