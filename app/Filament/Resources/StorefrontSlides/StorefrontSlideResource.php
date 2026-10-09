<?php

namespace App\Filament\Resources\StorefrontSlides;

use App\Filament\Clusters\Storefront;
use App\Filament\Concerns\OptimizesUploadedImages;
use App\Filament\Concerns\SelectableFromMediaHub;
use App\Filament\Resources\StorefrontSlides\Pages\CreateStorefrontSlide;
use App\Filament\Resources\StorefrontSlides\Pages\EditStorefrontSlide;
use App\Filament\Resources\StorefrontSlides\Pages\ListStorefrontSlides;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Models\StorefrontSlide;
use App\Services\CompanyContext;
use App\Support\CompanyMedia;
use App\Support\StorefrontThemeRegistry;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StorefrontSlideResource extends Resource
{
    use OptimizesUploadedImages;
    use SelectableFromMediaHub;

    protected static ?string $model = StorefrontSlide::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $cluster = Storefront::class;

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Hero Slides';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Slide')
                ->columnSpanFull()
                ->schema([
                    Select::make('company_id')
                        ->relationship('company', 'name', modifyQueryUsing: fn ($query) => CompanyMedia::constrainCompanyQuery($query))
                        ->rule(CompanyMedia::companyAccessRule())
                        ->required(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->visible(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->helperText('Select the company that will own this slide.')
                        ->searchable()
                        ->preload()
                        ->live(),
                    Select::make('storefront_setting_id')
                        ->label(__('Show on'))
                        ->options(fn (Get $get, ?StorefrontSlide $record): array => StorefrontSetting::siteChoicesFor(
                            (int) ($get('company_id') ?? $record?->company_id ?? app(CompanyContext::class)->id()) ?: null,
                        ))
                        ->rule(fn (Get $get, ?StorefrontSlide $record) => Rule::exists('storefront_settings', 'id')
                            ->where('company_id', (int) ($get('company_id') ?? $record?->company_id ?? app(CompanyContext::class)->id())))
                        ->placeholder(__('All sites'))
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            // A banner is prepared for one site's theme and template, so follow the chosen site.
                            $site = $state ? StorefrontSetting::withoutGlobalScopes()->find($state) : null;

                            if ($site) {
                                $set('theme', $site->storefrontTheme());
                                $set('template', $site->homepageTemplate());
                            }
                        })
                        ->helperText(__('Leave on "All sites" to show it on both the Blade storefront and the Next.js site, or pick one site to keep it off the other.')),
                    Select::make('theme')
                        ->label('Theme')
                        ->options(StorefrontThemeRegistry::themeOptions())
                        ->default(fn (): string => static::activeTheme())
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set('template', array_key_first(StorefrontThemeRegistry::templateOptions($state))))
                        ->helperText('The banner is only shown when this theme is active for the company. Match it to whichever theme you are preparing artwork for.'),
                    Select::make('template')
                        ->label('Homepage template')
                        ->options(fn (Get $get): array => StorefrontThemeRegistry::templateOptions($get('theme')))
                        ->default(fn (): string => static::activeTemplate())
                        ->required()
                        ->live(),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                    FileUpload::make('image')
                        ->label('Image (desktop)')
                        ->helperText(fn (Get $get): string => static::bannerHelperText($get('theme'), 'desktop'))
                        ->image()
                        ->maxSize(2048)
                        ->tap(static::browserImagePrecompression())
                        ->disk(fn (): string => CompanyMedia::publicDiskName())
                        ->directory(fn (Get $get, ?StorefrontSlide $record): string => CompanyMedia::publicDirectory('storefront/slides', $record, $get('company_id')))
                        ->fetchFileInformation(false)
                        ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                        ->disabled(fn (Get $get, ?StorefrontSlide $record): bool => ! CompanyMedia::canResolve($record, $get('company_id')))
                        ->imageEditor()
                        ->imageEditorAspectRatios(fn (Get $get): array => [static::bannerAspectRatio($get('theme'), 'desktop')])
                        ->imageResizeTargetWidth(fn (Get $get): string => (string) StorefrontThemeRegistry::bannerSpec($get('theme'))['desktop']['width'])
                        ->imageResizeTargetHeight(fn (Get $get): string => (string) StorefrontThemeRegistry::bannerSpec($get('theme'))['desktop']['height'])
                        ->saveUploadedFileUsing(static::optimizeImageUpload())
                        ->hintAction(static::selectFromMediaHubAction())
                        ->required(),
                    FileUpload::make('image_mobile')
                        ->label('Image (mobile)')
                        ->helperText(fn (Get $get): string => static::bannerHelperText($get('theme'), 'mobile'))
                        ->visible(fn (Get $get): bool => StorefrontThemeRegistry::bannerSpec($get('theme'))['mobile'] !== null)
                        ->image()
                        ->maxSize(2048)
                        ->tap(static::browserImagePrecompression())
                        ->disk(fn (): string => CompanyMedia::publicDiskName())
                        ->directory(fn (Get $get, ?StorefrontSlide $record): string => CompanyMedia::publicDirectory('storefront/slides', $record, $get('company_id')))
                        ->fetchFileInformation(false)
                        ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                        ->disabled(fn (Get $get, ?StorefrontSlide $record): bool => ! CompanyMedia::canResolve($record, $get('company_id')))
                        ->imageEditor()
                        ->imageEditorAspectRatios(fn (Get $get): array => [static::bannerAspectRatio($get('theme'), 'mobile')])
                        ->saveUploadedFileUsing(static::optimizeImageUpload())
                        ->hintAction(static::selectFromMediaHubAction()),
                    Toggle::make('fit_to_frame')
                        ->label('Fit to frame (never crop the image)')
                        ->helperText('On: the whole banner image is always shown, with a small strip of the banner background above/below if it is not the exact ratio — nothing is ever cut off. Off (default): the image fills the banner edge to edge and is cropped to fit.')
                        ->default(false),
                    TextInput::make('eyebrow')
                        ->label(__('Small label above the heading'))
                        ->maxLength(60)
                        ->helperText(__('Optional text shown over the banner image (Next.js site). Leave all four text fields empty for a clean image banner.')),
                    TextInput::make('heading')
                        ->label(__('Heading'))
                        ->maxLength(255),
                    TextInput::make('subheading')
                        ->label(__('Sub-heading'))
                        ->maxLength(255),
                    TextInput::make('cta_label')
                        ->label(__('Button label'))
                        ->maxLength(255)
                        ->helperText(__('The button opens the banner link below (or the linked product).')),
                    TextInput::make('cta_url')
                        ->label('Banner link (optional)')
                        ->url()
                        ->maxLength(255)
                        ->helperText('Clicking the clean banner image opens this URL. Full URL or a relative path such as /products.'),
                    Select::make('product_id')
                        ->label('Link to product (optional)')
                        ->helperText('Clicking the slide image sends visitors to this product\'s page. The CTA URL above wins if both are set.')
                        ->searchable()
                        ->options(function (Get $get, ?StorefrontSlide $record): array {
                            $companyId = $get('company_id') ?? $record?->company_id;

                            return $companyId
                                ? Product::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('name')->limit(100)->pluck('name', 'id')->all()
                                : [];
                        })
                        ->getSearchResultsUsing(function (string $search, Get $get, ?StorefrontSlide $record) {
                            $companyId = $get('company_id') ?? $record?->company_id;

                            return $companyId
                                ? Product::withoutGlobalScopes()->where('company_id', $companyId)->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id')
                                : [];
                        })
                        ->getOptionLabelUsing(fn ($value): ?string => Product::withoutGlobalScopes()->find($value)?->name),
                    TextInput::make('sort_order')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),
                    DateTimePicker::make('starts_at')
                        ->helperText('Optional. Slide is hidden before this time.'),
                    DateTimePicker::make('ends_at')
                        ->helperText('Optional. Slide is hidden after this time.'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Image')
                    ->state(fn (StorefrontSlide $record): ?string => CompanyMedia::publicUrl($record->image, $record)),
                TextColumn::make('destination')
                    ->label('Opens')
                    ->state(fn (StorefrontSlide $record): string => $record->cta_url
                        ?: ($record->product_id ? "Linked product #{$record->product_id}" : 'Image only'))
                    ->limit(40),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('show_on')
                    ->label(__('Show on'))
                    ->state(fn (StorefrontSlide $record): string => $record->storefrontSetting?->siteTypeLabel() ?? __('All sites'))
                    ->badge()
                    ->color(fn (StorefrontSlide $record): string => match (true) {
                        $record->storefront_setting_id === null => 'gray',
                        (bool) $record->storefrontSetting?->isNextjs() => 'success',
                        default => 'info',
                    }),
                TextColumn::make('theme')
                    ->label('Theme')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? 'Any theme (legacy)'
                        : StorefrontThemeRegistry::themeOptions()[StorefrontThemeRegistry::normalizeTheme($state)])
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ends_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** The company selected in the header's active theme, or the built-in default when none is set yet. */
    protected static function activeTheme(): string
    {
        $companyId = app(CompanyContext::class)->id();

        $setting = $companyId
            ? StorefrontSetting::bladeFor($companyId)
            : null;

        return $setting?->storefrontTheme() ?? StorefrontThemeRegistry::BUILT_IN;
    }

    protected static function activeTemplate(): string
    {
        return StorefrontThemeRegistry::normalizeTemplate(static::activeTheme(), null);
    }

    protected static function bannerHelperText(?string $theme, string $slot): string
    {
        $spec = StorefrontThemeRegistry::bannerSpec($theme)[$slot] ?? null;

        return $spec['note'] ?? 'Not used by this theme.';
    }

    protected static function bannerAspectRatio(?string $theme, string $slot): string
    {
        $spec = StorefrontThemeRegistry::bannerSpec($theme)[$slot] ?? null;

        if ($spec === null) {
            return '1:1';
        }

        [$width, $height] = [$spec['width'], $spec['height']];
        [$a, $b] = [$width, $height];

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        $gcd = max(1, $a);

        return ($width / $gcd).':'.($height / $gcd);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStorefrontSlides::route('/'),
            'create' => CreateStorefrontSlide::route('/create'),
            'edit' => EditStorefrontSlide::route('/{record}/edit'),
        ];
    }
}
