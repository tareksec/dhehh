<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Clusters\CompanyManagement;
use App\Filament\Concerns\OptimizesUploadedImages;
use App\Filament\Concerns\SelectableFromMediaHub;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Models\Company;
use App\Services\CompanyDeletionService;
use App\Support\CompanyMedia;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ColorEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyResource extends Resource
{
    use OptimizesUploadedImages;
    use SelectableFromMediaHub;

    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $cluster = CompanyManagement::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Company Profile')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('slug')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    TextInput::make('business_type')
                        ->maxLength(255),
                    TextInput::make('invoice_prefix')
                        ->required()
                        ->maxLength(20)
                        ->rule('regex:/^[A-Za-z0-9-]+$/')
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): string => Str::upper(trim((string) $state))),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                    Toggle::make('reseller_module_enabled')
                        ->label('Reseller module enabled')
                        ->helperText('Lets this company approve resellers, who then get their own curated mini-storefront (a URL under this company\'s domain). Super Admin only.')
                        ->default(false)
                        ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false)
                        ->dehydrated(fn (): bool => Auth::user()?->isSuperAdmin() ?? false),
                    Toggle::make('reseller_subdomains_enabled')
                        ->label('Reseller subdomains enabled')
                        ->helperText('Lets every approved reseller choose "name.<this company\'s domain>" for their store. Needs a wildcard DNS record (*.domain) and a wildcard SSL certificate on the server first -- see docs/deployment.md. Super Admin only.')
                        ->default(false)
                        ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false)
                        ->dehydrated(fn (): bool => Auth::user()?->isSuperAdmin() ?? false),
                    TextInput::make('reseller_portal_domain')
                        ->label('Reseller portal domain')
                        ->helperText('Leave blank to use "reseller." + this company\'s own domain. Set this only for a fully separate domain (e.g. a dedicated reseller-only website). Super Admin only.')
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => Company::normalizeDomain($state))
                        ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false)
                        ->dehydrated(fn (): bool => Auth::user()?->isSuperAdmin() ?? false),
                ])
                ->columns(2),

            Section::make('Branding and Contact')
                ->columnSpanFull()
                ->schema([
                    FileUpload::make('logo')
                        ->helperText('Save the company first, then upload its logo. Images are automatically compressed to WebP.')
                        ->image()
                        ->tap(static::browserCompactImagePrecompression())
                        ->disk(fn (): string => CompanyMedia::publicDiskName())
                        ->directory(function (?Company $record): string {
                            if (! $record?->exists) {
                                throw ValidationException::withMessages([
                                    'logo' => 'Save the company before uploading its logo.',
                                ]);
                            }

                            return CompanyMedia::publicDirectory('company', $record);
                        })
                        ->fetchFileInformation(false)
                        ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                        ->getOpenableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                        ->getDownloadableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                        ->disabled(fn (?Company $record): bool => ! $record?->exists || ! CompanyMedia::canResolve($record))
                        ->imageEditor()
                        ->saveUploadedFileUsing(static::optimizeCompactImageUpload())
                        ->hintAction(static::selectFromMediaHubAction())
                        ->downloadable()
                        ->openable(),
                    TextInput::make('phone')
                        ->tel()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->maxLength(255),
                    Textarea::make('address')
                        ->rows(3)
                        ->columnSpanFull(),
                    TextInput::make('google_maps_url')
                        ->label('Google Maps link')
                        ->url()
                        ->maxLength(500)
                        ->columnSpanFull()
                        ->helperText('Go to your location on Google Maps, tap "Share" → "Copy link", and paste it here. Leave empty to fall back to an auto-generated (less precise) search link built from the address above.'),
                    ColorPicker::make('dashboard_color')
                        ->label('Dashboard Color')
                        ->helperText('This company\'s admin panel color (sidebar, buttons, links). Separate from any storefront branding color.')
                        ->default('#F59E0B')
                        ->live(onBlur: true)
                        ->required(),
                ])
                ->columns(2),

            Section::make('Localization')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('currency')
                        ->default('BDT')
                        ->required()
                        ->maxLength(12)
                        ->suffix('৳')
                        ->helperText('Currency code, for example BDT (৳).'),
                    Select::make('timezone')
                        ->options([
                            'Asia/Dhaka' => 'Asia/Dhaka',
                            'UTC' => 'UTC',
                            'Asia/Dubai' => 'Asia/Dubai',
                            'Asia/Kolkata' => 'Asia/Kolkata',
                            'Asia/Shanghai' => 'Asia/Shanghai',
                            'Europe/London' => 'Europe/London',
                            'America/New_York' => 'America/New_York',
                        ])
                        ->default('Asia/Dhaka')
                        ->required()
                        ->searchable(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')
                    ->state(fn (Company $record): ?string => CompanyMedia::publicUrl($record->logo, $record))
                    ->height(40)
                    ->square()
                    ->toggleable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('business_type')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('domain')
                    ->label('Storefront Domain')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),
                IconColumn::make('domain_verified')
                    ->label('Domain Verified')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('invoice_prefix')
                    ->badge()
                    ->searchable(),
                ColorColumn::make('dashboard_color')
                    ->label('Color')
                    ->toggleable(),
                TextColumn::make('currency')
                    ->badge(),
                TextColumn::make('timezone')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('reseller_module_enabled')
                    ->label('Resellers')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                static::deleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::deleteBulkAction(),
                ]),
            ]);
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('This permanently deletes the company, its audit metadata, and its automatic system accounts. If business records still belong to it, deletion will be blocked so no operational data is lost.')
            ->using(fn (Company $record): bool => app(CompanyDeletionService::class)->delete($record))
            ->failureNotificationTitle('Company was not deleted')
            ->failureNotificationBody('This company still owns business records. Move or delete those records first, then try again.');
    }

    public static function deleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->using(function (DeleteBulkAction $action, $records): void {
                foreach ($records as $record) {
                    if (! app(CompanyDeletionService::class)->delete($record)) {
                        $action->reportBulkProcessingFailure(
                            'company-has-records',
                            'One or more companies still own business records and were kept.',
                        );
                    }
                }
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ImageEntry::make('logo')
                ->state(fn (Company $record): ?string => CompanyMedia::publicUrl($record->logo, $record)),
            TextEntry::make('name'),
            TextEntry::make('slug'),
            TextEntry::make('domain')
                ->label('Storefront Domain')
                ->placeholder('-'),
            IconEntry::make('domain_verified')
                ->label('Domain Verified')
                ->boolean(),
            TextEntry::make('business_type'),
            TextEntry::make('invoice_prefix')
                ->badge(),
            TextEntry::make('phone'),
            TextEntry::make('email'),
            TextEntry::make('address'),
            TextEntry::make('currency')
                ->badge(),
            TextEntry::make('timezone'),
            ColorEntry::make('dashboard_color')
                ->label('Dashboard Color'),
            IconEntry::make('is_active')
                ->boolean(),
        ]);
    }

    public static function canViewAny(): bool
    {
        return SchemaFacade::hasTable('companies') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canCreate(): bool
    {
        return SchemaFacade::hasTable('companies') && (Auth::user()?->isSuperAdmin() ?? false);
    }

    public static function canView($record): bool
    {
        $user = Auth::user();

        return SchemaFacade::hasTable('companies')
            && $record instanceof Company
            && (bool) ($user?->canManageSettings()
                && ($user->isSuperAdmin() || $user->canAccessCompany((int) $record->getKey())));
    }

    public static function canEdit($record): bool
    {
        return static::canView($record);
    }

    public static function canDelete($record): bool
    {
        return SchemaFacade::hasTable('companies') && (Auth::user()?->isSuperAdmin() ?? false);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereIn('companies.id', $user->companies()->select('companies.id'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'view' => ViewCompany::route('/{record}'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
