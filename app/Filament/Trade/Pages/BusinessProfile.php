<?php

namespace App\Filament\Trade\Pages;

use App\Filament\Concerns\OptimizesUploadedImages;
use App\Models\Company;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Support\CompanyMedia;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The business profile the sale and import papers print
 * (docs/tasneem-samples/PLAN.md §3.4): the registered trade address and the
 * factory / letterhead address, TIN, both BINs (import papers and Delivery
 * Challan), IRC, bank accounts, the signatory and the letterhead image. The
 * same fields are on the ERP's company page; this one is for the owner, who
 * does not use /admin. Needs "trade.profile.manage".
 */
class BusinessProfile extends Page
{
    use OptimizesUploadedImages;

    /** The company columns this page edits. */
    public const FIELDS = [
        'registered_address', 'factory_address', 'phone', 'email',
        'tin_number', 'bin_number', 'challan_bin_number', 'irc_number',
        'signatory_name', 'signatory_title', 'bank_accounts', 'letterhead_path',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.trade.pages.business-profile';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.profile.manage') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Business profile');
    }

    public function getTitle(): string
    {
        return __('Business profile');
    }

    protected function company(): Company
    {
        return app(CompanyContext::class)->company();
    }

    /** companies.settings key of the text printed under a Delivery Challan (TradeChallanPrintController). */
    public const CHALLAN_TERMS_SETTING = 'trade_challan_terms';

    public function mount(): void
    {
        $this->form->fill([
            ...$this->company()->only(self::FIELDS),
            'challan_terms' => data_get($this->company()->settings, self::CHALLAN_TERMS_SETTING),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->model($this->company())
            ->components([
                Section::make(__('Addresses'))
                    ->schema([
                        Textarea::make('registered_address')
                            ->label(__('Registered trade address'))
                            ->helperText(__('As written on the trade licence and the import papers.'))
                            ->rows(3),
                        Textarea::make('factory_address')
                            ->label(__('Factory / letterhead address'))
                            ->helperText(__('Printed on the letterhead and the Delivery Challan.'))
                            ->rows(3),
                        TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(255),
                        TextInput::make('email')->label(__('E-mail'))->email()->maxLength(255),
                    ])
                    ->columns(['default' => 1, 'md' => 2]),
                Section::make(__('Registration numbers'))
                    ->schema([
                        TextInput::make('tin_number')->label(__('TIN Number'))->maxLength(255),
                        TextInput::make('irc_number')->label(__('IRC Number'))->maxLength(255),
                        TextInput::make('bin_number')
                            ->label(__('BIN for import papers'))
                            ->helperText(__('PI, Commercial Invoice, Packing List and the other LC papers.'))
                            ->maxLength(255),
                        TextInput::make('challan_bin_number')
                            ->label(__('BIN for the Delivery Challan'))
                            ->helperText(__('Leave empty when it is the same as the BIN for import papers.'))
                            ->maxLength(255),
                    ])
                    ->columns(['default' => 1, 'md' => 2]),
                Section::make(__('Signatory'))
                    ->schema([
                        TextInput::make('signatory_name')->label(__('Authorized Signatory Name'))->maxLength(255),
                        TextInput::make('signatory_title')
                            ->label(__('Signatory Title'))
                            ->placeholder(__('e.g. Proprietor, Managing Director'))
                            ->maxLength(255),
                    ])
                    ->columns(['default' => 1, 'md' => 2]),
                Section::make(__('Bank accounts'))
                    ->description(__('The banks your papers name: the LC opening bank, the account for customer payments.'))
                    ->schema([
                        static::bankAccountsRepeater(),
                    ]),
                Section::make(__('Letterhead'))
                    ->schema([
                        static::letterheadUpload(),
                    ]),
                Section::make(__('Delivery Challan'))
                    ->schema([
                        Textarea::make('challan_terms')
                            ->label(__('Text printed under the challan'))
                            ->helperText(__('For example your disclaimer and assurance lines, in Bangla and English, as on your pad.'))
                            ->rows(5)
                            ->maxLength(2000),
                    ]),
            ]);
    }

    public static function bankAccountsRepeater(): Repeater
    {
        return Repeater::make('bank_accounts')
            ->hiddenLabel()
            ->schema([
                TextInput::make('bank_name')->label(__('Bank name'))->required()->maxLength(255),
                TextInput::make('branch')->label(__('Branch'))->maxLength(255),
                TextInput::make('account_name')->label(__('Account name'))->maxLength(255),
                TextInput::make('account_number')->label(__('Account number'))->maxLength(255),
                TextInput::make('routing_number')->label(__('Routing number'))->maxLength(255),
                TextInput::make('swift_code')->label(__('SWIFT code'))->maxLength(255),
            ])
            ->columns(['default' => 1, 'md' => 2])
            ->addActionLabel(__('Add a bank account'))
            ->defaultItems(0)
            ->collapsible()
            ->itemLabel(fn (array $state): ?string => $state['bank_name'] ?? null);
    }

    public static function letterheadUpload(): FileUpload
    {
        return FileUpload::make('letterhead_path')
            ->label(__('Letterhead image'))
            ->helperText(__('A picture of the top of your pad. Printed at the top of the Delivery Challan and the papers.'))
            ->image()
            ->disk(fn (): string => CompanyMedia::publicDiskName())
            ->directory(fn (?Company $record): string => CompanyMedia::publicDirectory('company', $record ?? app(CompanyContext::class)->company()))
            ->fetchFileInformation(false)
            ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
            ->getOpenableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
            ->getDownloadableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
            ->disabled(fn (?Company $record): bool => ! $record?->exists || ! CompanyMedia::canResolve($record))
            // A letterhead prints across the whole page width, so not the compact (logo) size.
            ->saveUploadedFileUsing(static::optimizeImageUpload())
            ->openable();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $company = $this->company();
        $data = $this->form->getState();
        $before = $company->only(self::FIELDS);

        $settings = $company->settings ?? [];
        $settings[self::CHALLAN_TERMS_SETTING] = trim((string) ($data['challan_terms'] ?? ''));

        $company->forceFill([
            ...array_intersect_key($data, array_flip(self::FIELDS)),
            'bank_accounts' => array_values($data['bank_accounts'] ?? []),
            'settings' => $settings,
        ])->save();

        app(AuditLogService::class)->record('trade.profile_saved', $company, $before, $company->refresh()->only(self::FIELDS));

        Notification::make()->title(__('Business profile saved'))->success()->send();
    }
}
