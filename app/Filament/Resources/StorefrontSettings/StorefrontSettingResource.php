<?php

namespace App\Filament\Resources\StorefrontSettings;

use App\Filament\Clusters\Storefront;
use App\Filament\Concerns\OptimizesUploadedImages;
use App\Filament\Concerns\SelectableFromMediaHub;
use App\Filament\Resources\StorefrontSettings\Pages\CreateStorefrontSetting;
use App\Filament\Resources\StorefrontSettings\Pages\EditStorefrontSetting;
use App\Filament\Resources\StorefrontSettings\Pages\ListStorefrontSettings;
use App\Models\Category;
use App\Models\Company;
use App\Models\ConversationChannel;
use App\Models\Product;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Models\StorefrontSlide;
use App\Services\CompanyContext;
use App\Support\CompanyMedia;
use App\Support\StorefrontThemeRegistry;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Validation\Rule;

class StorefrontSettingResource extends Resource
{
    use OptimizesUploadedImages;
    use SelectableFromMediaHub;

    protected static ?string $model = StorefrontSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $cluster = Storefront::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Site Theme';

    protected static ?string $recordTitleAttribute = 'company.name';

    public static function form(Schema $schema): Schema
    {
        $components = [
            Section::make('Storefront Publishing')
                ->columnSpanFull()
                ->description('Connect a company to its public storefront and control whether it is visible.')
                ->schema([
                    Select::make('company_id')
                        ->relationship('company', 'name', modifyQueryUsing: fn ($query) => CompanyMedia::constrainCompanyQuery($query))
                        ->rule(CompanyMedia::companyAccessRule())
                        ->required(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->visible(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->helperText('Select the company that will own this storefront.')
                        ->searchable()
                        ->preload()
                        ->unique(table: 'storefront_settings', column: 'company_id', ignoreRecord: true)
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?int $state): void {
                            $company = $state ? Company::withoutGlobalScopes()->find($state) : null;

                            $set('company_domain', $company?->domain);
                            $set('company_domain_verified', (bool) $company?->domain_verified);
                        }),
                    Toggle::make('is_published')
                        ->label('Published')
                        ->default(false)
                        ->helperText('Unpublished storefronts return a 404 on public domain routes.'),
                    Toggle::make('customer_accounts_enabled')
                        ->label('Enable customer login & registration')
                        ->default(true)
                        ->helperText('Turn off to hide login/register and fall back to guest phone-number order lookup only.'),
                    TextInput::make('whatsapp_number')
                        ->tel()
                        ->maxLength(40)
                        ->helperText('Optional. Used for quick customer contact buttons.'),
                    TextInput::make('phone_number')
                        ->label('Call support number')
                        ->tel()
                        ->maxLength(40)
                        ->helperText('Optional. Shows a "Call" button next to WhatsApp for customers who prefer to call.'),
                    TextInput::make('contact_email')
                        ->label('Support email')
                        ->email()
                        ->maxLength(255)
                        ->helperText('Shown on the Contact page. Leave blank to use the company\'s business email.'),
                    TextInput::make('contact_hours')
                        ->label('Support hours')
                        ->maxLength(120)
                        ->placeholder('Saturday-Friday, 10:00 AM to 10:00 PM')
                        ->helperText('Shown on the Contact page under the Call Us card.'),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make('Site Theme')
                ->columnSpanFull()
                ->description('Choose the storefront design and its homepage layout. Built-in preserves the current storefront, Marketplace Pro serves broad B2B commerce, and Noor Solar Energy adds a dedicated solar-sales experience.')
                ->schema([
                    Select::make('storefront_theme')
                        ->label('Active theme')
                        ->options(StorefrontThemeRegistry::themeOptions())
                        ->default(StorefrontThemeRegistry::BUILT_IN)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            $set('homepage_template', array_key_first(StorefrontThemeRegistry::templateOptions($state)));

                            if ($state === StorefrontThemeRegistry::MARKETPLACE_PRO) {
                                $set('theme_palette_preset', 'marketplace_pro');
                                foreach (StorefrontSetting::themePalettePresetFields('marketplace_pro') as $field => $color) {
                                    $set($field, $color);
                                }
                            }

                            if ($state === StorefrontThemeRegistry::NOOR_SOLAR) {
                                $set('theme_palette_preset', 'noor_solar');
                                foreach (StorefrontSetting::themePalettePresetFields('noor_solar') as $field => $color) {
                                    $set($field, $color);
                                }

                                $set('typography_preset', 'noor_solar');
                                foreach (StorefrontSetting::typographyPresetFields('noor_solar') as $field => $value) {
                                    $set($field, $value);
                                }
                            }
                        })
                        ->helperText(fn (Get $get): string => StorefrontThemeRegistry::themeDescription($get('storefront_theme'))),
                    Select::make('homepage_template')
                        ->label('Homepage template')
                        ->options(fn (Get $get): array => StorefrontThemeRegistry::templateOptions($get('storefront_theme')))
                        ->default(StorefrontThemeRegistry::BUILT_IN_DEFAULT)
                        ->required()
                        ->live()
                        ->helperText('Templates change homepage composition while keeping catalog, product, cart, checkout, and account functionality intact.'),
                ])
                ->columns(2),

            Section::make('Marketplace Pro Features')
                ->columnSpanFull()
                ->description('Enable only the B2B features needed by this storefront. Template-specific controls appear automatically.')
                ->visible(fn (Get $get): bool => $get('storefront_theme') === StorefrontThemeRegistry::MARKETPLACE_PRO)
                ->schema([
                    Toggle::make('marketplace_quote_enabled')
                        ->label('Quote actions')
                        ->default(true),
                    Toggle::make('marketplace_business_accounts_enabled')
                        ->label('Business account callouts')
                        ->default(true),
                    Toggle::make('marketplace_business_strip_enabled')
                        ->label('Wholesale buyers banner')
                        ->default(false)
                        ->helperText('The business account banner (heading/text from Marketplace Pro Content) with the Open business account button, shown near the bottom of the hero homepage.')
                        ->visible(fn (Get $get): bool => $get('homepage_template') === StorefrontThemeRegistry::MARKETPLACE_HERO),
                    Toggle::make('marketplace_trust_strip_enabled')
                        ->label('Trust and service strip')
                        ->default(true),
                    Toggle::make('marketplace_deals_enabled')
                        ->label('Deals section')
                        ->default(true),
                    Toggle::make('marketplace_categories_enabled')
                        ->label('Category section')
                        ->default(true),
                    Toggle::make('marketplace_bulk_pricing_enabled')
                        ->label('Bulk pricing panel')
                        ->default(true)
                        ->visible(fn (Get $get): bool => $get('homepage_template') === StorefrontThemeRegistry::MARKETPLACE_CAMPAIGN),
                    Toggle::make('marketplace_sidebar_enabled')
                        ->label('Category sidebar')
                        ->default(true)
                        ->visible(fn (Get $get): bool => $get('homepage_template') === StorefrontThemeRegistry::MARKETPLACE_COMPACT),
                    Select::make('marketplace_product_limit')
                        ->label('Homepage product density')
                        ->options([5 => '5 products', 8 => '8 products', 10 => '10 products', 12 => '12 products', 15 => '15 products', 20 => '20 products'])
                        ->default(10)
                        ->required(),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Marketplace Pro Content')
                ->columnSpanFull()
                ->description('Theme-specific campaign copy. The hero shows your Hero Slides banner; the campaign text below is used only when no hero slide exists. Empty fields are hidden on the storefront — nothing is filled in automatically.')
                ->visible(fn (Get $get): bool => $get('storefront_theme') === StorefrontThemeRegistry::MARKETPLACE_PRO)
                ->schema([
                    TextInput::make('marketplace_campaign_badge')
                        ->label('Campaign badge')
                        ->maxLength(60)
                        ->placeholder('WHOLESALE OFFERS'),
                    TextInput::make('marketplace_campaign_heading')
                        ->label('Campaign heading')
                        ->maxLength(140)
                        ->placeholder('Up to 25% off on bulk orders')
                        ->columnSpanFull(),
                    Textarea::make('marketplace_campaign_subheading')
                        ->label('Campaign description')
                        ->rows(2)
                        ->maxLength(280)
                        ->placeholder('Factory-direct pricing and dependable nationwide support for every business order.')
                        ->columnSpanFull(),
                    TextInput::make('marketplace_campaign_cta_label')
                        ->label('Campaign action label')
                        ->maxLength(40)
                        ->placeholder('Shop wholesale'),
                    Toggle::make('marketplace_utility_bar_enabled')
                        ->label('Show top utility bar message')
                        ->default(false)
                        ->live(),
                    TextInput::make('marketplace_utility_bar_text')
                        ->label('Utility bar message')
                        ->maxLength(160)
                        ->visible(fn (Get $get): bool => (bool) $get('marketplace_utility_bar_enabled'))
                        ->required(fn (Get $get): bool => (bool) $get('marketplace_utility_bar_enabled')),
                    TextInput::make('marketplace_helpline')
                        ->label('Helpline number (header & checkout)')
                        ->tel()
                        ->maxLength(40)
                        ->placeholder('01XXXXXXXXX'),
                    Repeater::make('marketplace_trust_items')
                        ->label('Trust strip items')
                        ->helperText('Up to 6 short reassurance items shown under the hero. Leave empty to reuse the Trust Strip messages.')
                        ->schema([
                            Select::make('icon')
                                ->options(StorefrontSetting::MARKETPLACE_TRUST_ICONS)
                                ->default('shield')
                                ->required(),
                            TextInput::make('title')
                                ->required()
                                ->maxLength(60),
                            TextInput::make('subtitle')
                                ->maxLength(80),
                        ])
                        ->columns(3)
                        ->maxItems(6)
                        ->reorderable()
                        ->defaultItems(0)
                        ->columnSpanFull(),
                    Repeater::make('marketplace_bulk_pricing_rows')
                        ->label('Bulk pricing panel rows (Campaign-driven template)')
                        ->helperText('Each row is shown as label → value, e.g. your real volume pricing terms. The panel is hidden when empty.')
                        ->schema([
                            TextInput::make('label')->required()->maxLength(60),
                            TextInput::make('value')->required()->maxLength(60),
                        ])
                        ->columns(2)
                        ->maxItems(8)
                        ->reorderable()
                        ->defaultItems(0)
                        ->columnSpanFull(),
                    TextInput::make('marketplace_business_heading')
                        ->label('Business account callout heading')
                        ->maxLength(140)
                        ->placeholder('Open a business account'),
                    Textarea::make('marketplace_business_text')
                        ->label('Business account callout text')
                        ->rows(2)
                        ->maxLength(280)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make('Theme Color Palette')
                ->columnSpanFull()
                ->description('Configure shared brand colors first, then review the light and dark surfaces side by side. Each field maps to the same role across every storefront page.')
                ->schema([
                    Grid::make(['default' => 1, 'lg' => 2])
                        ->schema([
                            Section::make('Brand & Interaction Colors')
                                ->description('Shared in both modes: actions, active navigation, focus, promotional highlights, and offer surfaces.')
                                ->schema([
                                    Select::make('theme_palette_preset')
                                        ->label('Palette preset')
                                        ->options(StorefrontSetting::themePalettePresetOptions())
                                        ->default('custom')
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                                            foreach (StorefrontSetting::themePalettePresetFields($state) as $field => $color) {
                                                $set($field, $color);
                                            }
                                        })
                                        ->helperText('Start with an accessible professional palette, then fine-tune individual roles.'),
                                    Select::make('theme_mode')
                                        ->label('Default visitor mode')
                                        ->options(StorefrontSetting::THEME_MODES)
                                        ->default('system')
                                        ->required()
                                        ->helperText('Visitors can still switch mode; their choice is remembered.'),
                                    ColorPicker::make('theme_color')
                                        ->label('Primary — actions & active states')
                                        ->default('#0F766E')
                                        ->live(onBlur: true)
                                        ->required(),
                                    Select::make('theme_foreground_mode')
                                        ->label('Text placed on primary')
                                        ->options(StorefrontSetting::THEME_FOREGROUND_MODES)
                                        ->default('auto')
                                        ->required()
                                        ->helperText('Auto calculates black or white for accessible contrast.'),
                                    ColorPicker::make('theme_secondary_color')
                                        ->label('Secondary — supporting surfaces')
                                        ->default('#0F172A')
                                        ->live(onBlur: true)
                                        ->required(),
                                    ColorPicker::make('theme_accent_color')
                                        ->label('Accent — focus & promotion')
                                        ->default('#F59E0B')
                                        ->live(onBlur: true)
                                        ->required(),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                            Section::make('Light Mode')
                                ->description('Colors used when the storefront is in light mode. Keep primary text high contrast and muted text readable.')
                                ->schema([
                                    ColorPicker::make('theme_background_color')
                                        ->label('Page canvas')
                                        ->helperText('Behind every section and card.')
                                        ->default('#FFFFFF')
                                        ->live(onBlur: true)
                                        ->required(),
                                    ColorPicker::make('theme_surface_color')
                                        ->label('Cards & controls')
                                        ->helperText('Cards, dropdowns, headers, and form surfaces.')
                                        ->default('#FFFFFF')
                                        ->live(onBlur: true)
                                        ->required(),
                                    ColorPicker::make('theme_text_color')
                                        ->label('Primary text')
                                        ->helperText('Headings, prices, labels, and important copy.')
                                        ->default('#111827')
                                        ->live(onBlur: true)
                                        ->rule(fn (Get $get): Closure => self::contrastRule($get, 'theme_surface_color', 4.5, 'light cards and controls'))
                                        ->required(),
                                    ColorPicker::make('theme_muted_text_color')
                                        ->label('Secondary text')
                                        ->helperText('Descriptions, metadata, hints, and inactive navigation.')
                                        ->default('#6B7280')
                                        ->live(onBlur: true)
                                        ->rule(fn (Get $get): Closure => self::contrastRule($get, 'theme_surface_color', 3, 'light cards and controls'))
                                        ->required(),
                                    ColorPicker::make('theme_border_color')
                                        ->label('Borders & dividers')
                                        ->helperText('Card outlines, fields, separators, and table rules.')
                                        ->default('#E5E7EB')
                                        ->live(onBlur: true)
                                        ->required(),
                                ])
                                ->columns(2),
                            Section::make('Dark Mode')
                                ->description('Independent dark-mode surfaces. Avoid pure black cards so hierarchy and borders remain visible.')
                                ->schema([
                                    ColorPicker::make('theme_dark_background_color')
                                        ->label('Page canvas')
                                        ->helperText('The darkest storefront background layer.')
                                        ->default('#030712')
                                        ->live(onBlur: true)
                                        ->required(),
                                    ColorPicker::make('theme_dark_surface_color')
                                        ->label('Cards & controls')
                                        ->helperText('Raised cards, dropdowns, fields, and navigation surfaces.')
                                        ->default('#111827')
                                        ->live(onBlur: true)
                                        ->required(),
                                    ColorPicker::make('theme_dark_text_color')
                                        ->label('Primary text')
                                        ->helperText('Headings, prices, labels, and important copy.')
                                        ->default('#F3F4F6')
                                        ->live(onBlur: true)
                                        ->rule(fn (Get $get): Closure => self::contrastRule($get, 'theme_dark_surface_color', 4.5, 'dark cards and controls'))
                                        ->required(),
                                    ColorPicker::make('theme_dark_muted_text_color')
                                        ->label('Secondary text')
                                        ->helperText('Descriptions, metadata, hints, and inactive navigation.')
                                        ->default('#9CA3AF')
                                        ->live(onBlur: true)
                                        ->rule(fn (Get $get): Closure => self::contrastRule($get, 'theme_dark_surface_color', 3, 'dark cards and controls'))
                                        ->required(),
                                    ColorPicker::make('theme_dark_border_color')
                                        ->label('Borders & dividers')
                                        ->helperText('Visible separation without high-contrast glare.')
                                        ->default('#374151')
                                        ->live(onBlur: true)
                                        ->required(),
                                ])
                                ->columns(2),
                        ]),
                ])
                ->collapsible(),

            Section::make('Storefront Typography')
                ->columnSpanFull()
                ->description('Configure a readable type hierarchy. Presets use approved font stacks, 16px accessible defaults, balanced wrapping, and reliable fallbacks.')
                ->schema([
                    Grid::make(['default' => 1, 'lg' => 2])
                        ->schema([
                            Section::make('Font Pairing')
                                ->description('Choose a preset or pair heading and body fonts independently. Google Fonts load with display swap.')
                                ->schema([
                                    Select::make('typography_preset')
                                        ->label('Typography preset')
                                        ->options(StorefrontSetting::typographyPresetOptions())
                                        ->default('system')
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                                            foreach (StorefrontSetting::typographyPresetFields($state) as $field => $value) {
                                                $set($field, $value);
                                            }
                                        })
                                        ->helperText('Includes system, modern, commerce, Bangla-optimized, and editorial pairings.'),
                                    Select::make('typography_heading_font')
                                        ->label('Heading font')
                                        ->options(StorefrontSetting::fontOptions())
                                        ->default('system')
                                        ->required(),
                                    Select::make('typography_body_font')
                                        ->label('Body font')
                                        ->options(StorefrontSetting::fontOptions())
                                        ->default('system')
                                        ->required(),
                                ])
                                ->columns(2),
                            Section::make('Reading & Hierarchy')
                                ->description('Controls readability, visual hierarchy, line length, and spacing without editing templates.')
                                ->schema([
                                    Select::make('typography_base_size')
                                        ->label('Base font size')
                                        ->options([
                                            14 => '14 px — compact',
                                            15 => '15 px — dense',
                                            16 => '16 px — recommended',
                                            17 => '17 px — large',
                                            18 => '18 px — extra large',
                                        ])
                                        ->default(16)
                                        ->required(),
                                    Select::make('typography_line_height')
                                        ->label('Reading line height')
                                        ->options(StorefrontSetting::TYPOGRAPHY_LINE_HEIGHTS)
                                        ->default('normal')
                                        ->required(),
                                    Select::make('typography_content_width')
                                        ->label('Long-form line length')
                                        ->options(StorefrontSetting::TYPOGRAPHY_CONTENT_WIDTHS)
                                        ->default('comfortable')
                                        ->required(),
                                    Select::make('typography_heading_weight')
                                        ->label('Heading weight')
                                        ->options([500 => 'Medium 500', 600 => 'Semibold 600', 700 => 'Bold 700', 800 => 'Extra bold 800'])
                                        ->default(700)
                                        ->required(),
                                    Select::make('typography_heading_tracking')
                                        ->label('Heading letter spacing')
                                        ->options(StorefrontSetting::TYPOGRAPHY_HEADING_TRACKING)
                                        ->default('tight')
                                        ->required(),
                                    Select::make('typography_scale')
                                        ->label('Heading scale')
                                        ->options(StorefrontSetting::TYPOGRAPHY_SCALES)
                                        ->default('balanced')
                                        ->required(),
                                    Select::make('typography_body_weight')
                                        ->label('Body weight')
                                        ->options([400 => 'Regular 400', 500 => 'Medium 500', 600 => 'Semibold 600'])
                                        ->default(400)
                                        ->required(),
                                    Select::make('typography_body_tracking')
                                        ->label('Body letter spacing')
                                        ->options(StorefrontSetting::TYPOGRAPHY_BODY_TRACKING)
                                        ->default('normal')
                                        ->required(),
                                ])
                                ->columns(2),
                        ]),
                ])
                ->collapsible(),

            Section::make('Modern Appearance')
                ->columnSpanFull()
                ->description('Tune storefront width, responsive gutters, corner shape, card depth, and motion. Reduced-motion preferences always override animation settings.')
                ->schema([
                    Select::make('appearance_preset')
                        ->label('Appearance preset')
                        ->options(StorefrontSetting::appearancePresetOptions())
                        ->default('modern')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            foreach (StorefrontSetting::appearancePresetFields($state) as $field => $value) {
                                $set($field, $value);
                            }
                        })
                        ->helperText('Apply a coherent modern, minimal, premium, or dense marketplace foundation.'),
                    Select::make('appearance_container_width')
                        ->label('Content width')
                        ->options(StorefrontSetting::APPEARANCE_CONTAINER_WIDTHS)
                        ->default('wide')
                        ->required(),
                    Select::make('appearance_page_gutter')
                        ->label('Responsive page gutter')
                        ->options(StorefrontSetting::APPEARANCE_PAGE_GUTTERS)
                        ->default('comfortable')
                        ->required(),
                    Select::make('appearance_corner_radius')
                        ->label('Corner style')
                        ->options(StorefrontSetting::APPEARANCE_CORNER_RADII)
                        ->default('soft')
                        ->required(),
                    Select::make('appearance_shadow')
                        ->label('Card depth')
                        ->options(StorefrontSetting::APPEARANCE_SHADOWS)
                        ->default('subtle')
                        ->required(),
                    Select::make('appearance_card_hover')
                        ->label('Card hover feedback')
                        ->options(StorefrontSetting::APPEARANCE_CARD_HOVERS)
                        ->default('subtle')
                        ->required(),
                    Select::make('appearance_motion')
                        ->label('Motion speed')
                        ->options(StorefrontSetting::APPEARANCE_MOTION)
                        ->default('standard')
                        ->required(),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Homepage Content')
                ->columnSpanFull()
                ->description('Override the default homepage hero copy. Leave blank to use the automatic default.')
                ->schema([
                    TextInput::make('hero_heading')
                        ->label('Hero heading')
                        ->maxLength(120)
                        ->placeholder('Shop the latest from {company name}.')
                        ->columnSpanFull(),
                    Textarea::make('hero_subheading')
                        ->label('Hero subheading')
                        ->rows(2)
                        ->maxLength(240)
                        ->placeholder('Browse current products, order directly, and track purchases from one clean storefront.')
                        ->columnSpanFull(),
                    TextInput::make('hero_cta_label')
                        ->label('Hero call-to-action label')
                        ->maxLength(40)
                        ->placeholder('Start shopping'),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => in_array(
                    StorefrontThemeRegistry::normalizeTheme($get('storefront_theme')),
                    [StorefrontThemeRegistry::BUILT_IN, StorefrontThemeRegistry::NOOR_SOLAR],
                    true,
                ))
                ->collapsible(),

            Section::make('Trust Strip')
                ->columnSpanFull()
                ->description('Short reassurance lines shown as an icon row on the homepage. Leave a field blank to hide that item.')
                ->schema([
                    TextInput::make('trust_strip_delivery')
                        ->label('Delivery message')
                        ->maxLength(80)
                        ->placeholder('Fast delivery nationwide'),
                    TextInput::make('trust_strip_return')
                        ->label('Return/warranty message')
                        ->maxLength(80)
                        ->placeholder('Easy returns within 7 days'),
                    TextInput::make('trust_strip_payment')
                        ->label('Payment message')
                        ->maxLength(80)
                        ->placeholder('Cash on delivery available'),
                ])
                ->columns(3)
                ->visible(fn (Get $get): bool => ($get('storefront_theme') ?: StorefrontThemeRegistry::BUILT_IN) === StorefrontThemeRegistry::BUILT_IN)
                ->collapsible(),

            Section::make('Offer Countdown')
                ->columnSpanFull()
                ->description('An optional sitewide flash-sale banner with a countdown, shown on the homepage until it ends. Leave the title blank to hide it.')
                ->schema([
                    TextInput::make('offer_title')
                        ->label('Offer title')
                        ->maxLength(120)
                        ->placeholder('Flash Sale'),
                    TextInput::make('offer_discount_percent')
                        ->label('Discount %')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100),
                    DateTimePicker::make('offer_ends_at')
                        ->label('Ends at')
                        ->helperText('The banner disappears automatically once this time passes.'),
                ])
                ->columns(3)
                ->visible(fn (Get $get): bool => ($get('storefront_theme') ?: StorefrontThemeRegistry::BUILT_IN) === StorefrontThemeRegistry::BUILT_IN)
                ->collapsible(),

            Section::make('Domain and Launch Readiness')
                ->columnSpanFull()
                ->description('These fields power the Storefront Settings list readiness columns.')
                ->schema([
                    TextInput::make('company_domain')
                        ->label('Storefront Domain')
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => Company::normalizeDomain($state))
                        ->rule(function (Get $get, ?StorefrontSetting $record) {
                            $companyId = $get('company_id') ?: $record?->company_id;

                            return Rule::unique('companies', 'domain')
                                ->ignore($companyId)
                                ->whereNotNull('domain');
                        })
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (StorefrontSetting::nextjsDomainTaken((string) $value)) {
                                $fail(__('This domain is already used by a Next.js site.'));
                            }
                        })
                        ->helperText('Example: zamzamgadgetbd.com. Do not include https:// or paths.')
                        ->afterStateUpdated(fn (Set $set): mixed => $set('company_domain_verified', false))
                        ->afterStateHydrated(function (TextInput $component, ?StorefrontSetting $record): void {
                            $component->state($record?->company?->domain);
                        }),
                    Toggle::make('company_domain_verified')
                        ->label('Domain verified')
                        ->helperText('Changing the domain resets this status. Save the new domain, verify DNS/server routing, then turn this on in a second save.')
                        ->afterStateHydrated(function (Toggle $component, ?StorefrontSetting $record): void {
                            $component->state((bool) $record?->company?->domain_verified);
                        }),
                    TextInput::make('launch_readiness_display')
                        ->label('Launch Readiness')
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (TextInput $component, ?StorefrontSetting $record): void {
                            $component->state($record ? self::readinessSummary($record) : 'Save first to calculate');
                        }),
                    Textarea::make('missing_setup_display')
                        ->label('Missing Setup')
                        ->rows(3)
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Textarea $component, ?StorefrontSetting $record): void {
                            $component->state($record ? self::missingSetup($record) : 'Save first to calculate');
                        }),
                    TextInput::make('visible_products_display')
                        ->label('Visible Products')
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (TextInput $component, ?StorefrontSetting $record): void {
                            $component->state($record ? (string) self::productCount($record) : '0');
                        }),
                    TextInput::make('published_pages_display')
                        ->label('Published Pages')
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (TextInput $component, ?StorefrontSetting $record): void {
                            $component->state($record ? (string) self::pageCount($record) : '0');
                        }),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make('Branding')
                ->columnSpanFull()
                ->schema([
                    FileUpload::make('logo')
                        ->label('Logo (light mode)')
                        ->helperText('Full horizontal logo, transparent PNG/SVG preferred. Recommended: 400x100px (4:1 ratio); shown at 40px height. When uploaded, it replaces the site name text in the storefront header. Raster images are automatically compressed to WebP; SVGs are kept as-is.')
                        ->image()
                        ->maxSize(1024)
                        ->tap(static::browserCompactImagePrecompression())
                        ->disk(fn (): string => CompanyMedia::publicDiskName())
                        ->directory(fn (Get $get, ?StorefrontSetting $record): string => CompanyMedia::publicDirectory('storefront/logos', $record, $get('company_id')))
                        ->fetchFileInformation(false)
                        ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                        ->getOpenableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                        ->getDownloadableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                        ->disabled(fn (Get $get, ?StorefrontSetting $record): bool => ! CompanyMedia::canResolve($record, $get('company_id')))
                        ->imageEditor()
                        ->saveUploadedFileUsing(static::optimizeCompactImageUpload())
                        ->hintAction(static::selectFromMediaHubAction())
                        ->downloadable()
                        ->openable(),
                    FileUpload::make('logo_dark')
                        ->label('Logo (dark mode)')
                        ->helperText('Optional. Light-colored logo version for dark mode visitors. Same recommended size: 400x100px. If empty, the light-mode logo is used in both modes. Raster images are automatically compressed to WebP; SVGs are kept as-is.')
                        ->image()
                        ->maxSize(1024)
                        ->tap(static::browserCompactImagePrecompression())
                        ->saveUploadedFileUsing(static::optimizeCompactImageUpload())
                        ->disk(fn (): string => CompanyMedia::publicDiskName())
                        ->directory(fn (Get $get, ?StorefrontSetting $record): string => CompanyMedia::publicDirectory('storefront/logos', $record, $get('company_id')))
                        ->fetchFileInformation(false)
                        ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                        ->getOpenableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                        ->getDownloadableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                        ->disabled(fn (Get $get, ?StorefrontSetting $record): bool => ! CompanyMedia::canResolve($record, $get('company_id')))
                        ->imageEditor()
                        ->hintAction(static::selectFromMediaHubAction())
                        ->downloadable()
                        ->openable(),
                    Text::make('Homepage banners are managed in Storefront → Hero Slides (images, product links, scheduling, and mobile variants all live there).')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make('Checkout & Delivery')
                ->columnSpanFull()
                ->description('Controls shown on the one-page storefront checkout.')
                ->schema([
                    Text::make('The main checkout page\'s payment methods (Cash on Delivery, bKash, Nagad, and any other manual or online channel) are managed in Storefront → Payment Methods — add, reorder, enable/disable, or edit instructions for each one there. The fields below are still used only by the single-product Offer checkout pages.')
                        ->columnSpanFull(),
                    Toggle::make('cod_enabled')
                        ->label('Offer pages: enable Cash on Delivery')
                        ->default(true),
                    TextInput::make('delivery_first_kg_inside')
                        ->label('First 1 kg — inside Dhaka')
                        ->numeric()
                        ->prefix('৳')
                        ->default(70)
                        ->minValue(0)
                        ->required(),
                    TextInput::make('delivery_first_kg_outside')
                        ->label('First 1 kg — outside Dhaka')
                        ->numeric()
                        ->prefix('৳')
                        ->default(110)
                        ->minValue(0)
                        ->required(),
                    TextInput::make('delivery_additional_per_kg')
                        ->label('Each additional started kg')
                        ->numeric()
                        ->prefix('৳')
                        ->default(20)
                        ->minValue(0)
                        ->required()
                        ->helperText('The total product weight is rounded up to the next whole kilogram.'),
                    TextInput::make('delivery_time_inside')
                        ->label('Delivery time — inside Dhaka')
                        ->maxLength(120)
                        ->placeholder('e.g. 1–2 days')
                        ->helperText('Shown at checkout and on product pages. Leave empty to show no delivery time.'),
                    TextInput::make('delivery_time_outside')
                        ->label('Delivery time — outside Dhaka')
                        ->maxLength(120)
                        ->placeholder('e.g. 3–5 days')
                        ->helperText('Shown at checkout and on product pages. Leave empty to show no delivery time.'),
                    Toggle::make('vat_enabled')
                        ->label('Charge VAT on storefront orders')
                        ->default(false)
                        ->live()
                        ->helperText('Off by default. VAT is calculated on the product subtotal only (delivery is not taxed).')
                        ->columnSpanFull(),
                    TextInput::make('vat_rate')
                        ->label('VAT rate')
                        ->numeric()
                        ->suffix('%')
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(0)
                        ->required(fn (Get $get): bool => (bool) $get('vat_enabled'))
                        ->visible(fn (Get $get): bool => (bool) $get('vat_enabled')),
                    Select::make('vat_mode')
                        ->label('Product prices are')
                        ->options([
                            StorefrontSetting::VAT_MODE_EXCLUSIVE => 'Excluding VAT — add VAT on top at checkout',
                            StorefrontSetting::VAT_MODE_INCLUSIVE => 'Including VAT — show the VAT share only',
                        ])
                        ->default(StorefrontSetting::VAT_MODE_EXCLUSIVE)
                        ->required(fn (Get $get): bool => (bool) $get('vat_enabled'))
                        ->visible(fn (Get $get): bool => (bool) $get('vat_enabled')),
                    Toggle::make('vat_use_product_rate')
                        ->label('Use each product\'s own VAT rate')
                        ->helperText('When on, a product\'s VAT rate from Products → VAT rate is used for that item; products without one use the store rate above.')
                        ->visible(fn (Get $get): bool => (bool) $get('vat_enabled')),
                    TextInput::make('vat_label')
                        ->label('VAT label')
                        ->maxLength(40)
                        ->placeholder('VAT')
                        ->visible(fn (Get $get): bool => (bool) $get('vat_enabled')),
                    Textarea::make('inside_dhaka_keywords')
                        ->label('Inside-Dhaka address keywords')
                        ->rows(4)
                        ->helperText('One keyword per line or comma-separated. Checkout detects the area from the delivery address. Leave empty to use the built-in Dhaka locality list.')
                        ->columnSpanFull(),
                    Toggle::make('new_customer_delivery_advance_enabled')
                        ->label('Require new-customer delivery advance')
                        ->default(true)
                        ->helperText('A new customer/order is created only after the online gateway verifies this payment.'),
                    Textarea::make('new_customer_advance_message')
                        ->label('New-customer payment message')
                        ->rows(5)
                        ->maxLength(2000)
                        ->placeholder(StorefrontSetting::DEFAULT_NEW_CUSTOMER_ADVANCE_MESSAGE)
                        ->columnSpanFull(),
                    TextInput::make('manual_bkash_number')
                        ->label('Offer pages: bKash Send Money number')
                        ->maxLength(20)
                        ->placeholder('01XXXXXXXXX'),
                    Textarea::make('manual_bkash_instructions')
                        ->label('Offer pages: bKash instructions')
                        ->rows(2)
                        ->maxLength(500)
                        ->placeholder('Send Money to this number, then enter the Transaction ID below.')
                        ->columnSpanFull(),
                    TextInput::make('manual_nagad_number')
                        ->label('Offer pages: Nagad Send Money number')
                        ->maxLength(20)
                        ->placeholder('01XXXXXXXXX'),
                    Textarea::make('manual_nagad_instructions')
                        ->label('Offer pages: Nagad instructions')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make('Checkout Protection')
                ->columnSpanFull()
                ->description('Validate Bangladesh phone numbers, enforce customer blacklists, and limit repeated storefront orders. Start with Observe only to review violations without blocking customers.')
                ->schema([
                    Select::make('checkout_policy_mode')
                        ->label('Policy mode')
                        ->options(StorefrontSetting::CHECKOUT_POLICY_MODES)
                        ->default(StorefrontSetting::CHECKOUT_POLICY_OFF)
                        ->required()
                        ->live(),
                    Toggle::make('checkout_validate_bd_phone')
                        ->label('Require a valid Bangladesh mobile number')
                        ->default(true)
                        ->visible(fn (Get $get): bool => $get('checkout_policy_mode') !== StorefrontSetting::CHECKOUT_POLICY_OFF),
                    Toggle::make('checkout_block_phone')
                        ->label('Enforce phone blacklist')
                        ->default(true)
                        ->visible(fn (Get $get): bool => $get('checkout_policy_mode') !== StorefrontSetting::CHECKOUT_POLICY_OFF),
                    Toggle::make('checkout_block_email')
                        ->label('Enforce email blacklist')
                        ->default(true)
                        ->visible(fn (Get $get): bool => $get('checkout_policy_mode') !== StorefrontSetting::CHECKOUT_POLICY_OFF),
                    Toggle::make('checkout_block_ip')
                        ->label('Enforce IP blacklist')
                        ->default(true)
                        ->visible(fn (Get $get): bool => $get('checkout_policy_mode') !== StorefrontSetting::CHECKOUT_POLICY_OFF),
                    Toggle::make('checkout_order_limit_enabled')
                        ->label('Limit repeated orders')
                        ->default(false)
                        ->live()
                        ->visible(fn (Get $get): bool => $get('checkout_policy_mode') !== StorefrontSetting::CHECKOUT_POLICY_OFF),
                    TextInput::make('checkout_order_limit_count')
                        ->label('Maximum recent orders')
                        ->integer()
                        ->default(1)
                        ->minValue(1)
                        ->maxValue(100)
                        ->required()
                        ->visible(fn (Get $get): bool => $get('checkout_policy_mode') !== StorefrontSetting::CHECKOUT_POLICY_OFF && (bool) $get('checkout_order_limit_enabled')),
                    TextInput::make('checkout_order_limit_hours')
                        ->label('Order-limit window (hours)')
                        ->integer()
                        ->default(24)
                        ->minValue(1)
                        ->maxValue(168)
                        ->required()
                        ->visible(fn (Get $get): bool => $get('checkout_policy_mode') !== StorefrontSetting::CHECKOUT_POLICY_OFF && (bool) $get('checkout_order_limit_enabled')),
                    TextInput::make('checkout_policy_contact_phone')
                        ->label('Checkout support phone')
                        ->tel()
                        ->maxLength(40)
                        ->helperText('Shown in the generic blocked-checkout message; the exact policy violation remains private.')
                        ->visible(fn (Get $get): bool => $get('checkout_policy_mode') !== StorefrontSetting::CHECKOUT_POLICY_OFF),
                    Toggle::make('risk_payment_enabled')
                        ->label('Use courier history for advance eligibility')
                        ->default(false)
                        ->live()
                        ->helperText('Checks configured Pathao, Steadfast, and RedX accounts at submit time. If every provider is unavailable, checkout continues without a risk advance.'),
                    TextInput::make('risk_payment_success_ratio_threshold')
                        ->label('Minimum successful-delivery ratio')
                        ->integer()
                        ->suffix('%')
                        ->default(70)
                        ->minValue(0)
                        ->maxValue(100)
                        ->required()
                        ->visible(fn (Get $get): bool => (bool) $get('risk_payment_enabled')),
                    Select::make('risk_payment_advance_type')
                        ->label('Risk advance calculation')
                        ->options(StorefrontSetting::RISK_ADVANCE_TYPES)
                        ->default(StorefrontSetting::RISK_ADVANCE_FIXED)
                        ->required()
                        ->live()
                        ->visible(fn (Get $get): bool => (bool) $get('risk_payment_enabled')),
                    TextInput::make('risk_payment_advance_amount')
                        ->label('Fixed risk advance')
                        ->numeric()
                        ->prefix('৳')
                        ->default(100)
                        ->minValue(0)
                        ->required()
                        ->visible(fn (Get $get): bool => (bool) $get('risk_payment_enabled') && $get('risk_payment_advance_type') === StorefrontSetting::RISK_ADVANCE_FIXED),
                    TextInput::make('risk_payment_advance_percent')
                        ->label('Risk advance percentage')
                        ->integer()
                        ->suffix('%')
                        ->default(20)
                        ->minValue(0)
                        ->maxValue(100)
                        ->required()
                        ->visible(fn (Get $get): bool => (bool) $get('risk_payment_enabled') && $get('risk_payment_advance_type') === StorefrontSetting::RISK_ADVANCE_PERCENT),
                    Select::make('risk_payment_zero_history_action')
                        ->label('When couriers report no history')
                        ->options(StorefrontSetting::RISK_ZERO_HISTORY_ACTIONS)
                        ->default(StorefrontSetting::RISK_ZERO_HISTORY_ALLOW_COD)
                        ->required()
                        ->visible(fn (Get $get): bool => (bool) $get('risk_payment_enabled')),
                    Textarea::make('risk_payment_customer_message')
                        ->label('Customer-facing advance notice')
                        ->rows(3)
                        ->maxLength(1000)
                        ->placeholder(StorefrontSetting::DEFAULT_RISK_PAYMENT_CUSTOMER_MESSAGE)
                        ->helperText('Keep this generic; courier scores and internal thresholds are never shown to customers.')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('risk_payment_enabled')),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(),

            Section::make('Thank-you & Complaint Integration')
                ->columnSpanFull()
                ->description('Company-specific WhatsApp CTA and Telegram destination for customer complaints.')
                ->schema([
                    TextInput::make('whatsapp_group_url')
                        ->label('WhatsApp group invite URL')
                        ->url()
                        ->maxLength(500)
                        ->placeholder(StorefrontSetting::DEFAULT_WHATSAPP_GROUP_URL),
                    Textarea::make('whatsapp_group_message')
                        ->label('WhatsApp group CTA message')
                        ->rows(3)
                        ->maxLength(1000)
                        ->placeholder(StorefrontSetting::DEFAULT_WHATSAPP_GROUP_MESSAGE)
                        ->columnSpanFull(),
                    Toggle::make('complaint_telegram_enabled')
                        ->label('Send complaints to Telegram')
                        ->default(false),
                    TextInput::make('telegram_credentials.chat_id')
                        ->label('Telegram chat ID')
                        ->maxLength(100)
                        ->helperText('Use the company-specific group, channel, or account chat ID.'),
                    TextInput::make('telegram_credentials.bot_token')
                        ->label('Telegram bot token')
                        ->password()
                        ->revealable()
                        ->maxLength(255)
                        ->helperText('Stored encrypted. Add the bot to the selected company chat.'),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(),

            Section::make('Customer Notifications & Reminders')
                ->columnSpanFull()
                ->description('Company-specific SMS credentials are shared by customer login OTP, password recovery, and abandoned-cart reminders. WhatsApp reminders run hourly via the scheduler.')
                ->schema([
                    Toggle::make('abandoned_cart_reminders_enabled')
                        ->label('Enable reminders')
                        ->default(false),
                    Toggle::make('checkout_autosave_enabled')
                        ->label('Capture incomplete checkout details')
                        ->default(false)
                        ->helperText('Securely saves valid name, phone, email, and address fields while the customer types. Email and address are encrypted at rest.'),
                    TextInput::make('abandoned_cart_delay_hours')
                        ->label('Remind after (hours)')
                        ->integer()
                        ->default(6)
                        ->minValue(1)
                        ->maxValue(168),
                    TextInput::make('notification_credentials.mail_host')
                        ->label('SMTP host')
                        ->maxLength(255)
                        ->placeholder('smtp.gmail.com')
                        ->helperText('Used for customer login OTP emails. Leave empty to keep using the server\'s default mailer.')
                        ->columnSpanFull(),
                    TextInput::make('notification_credentials.mail_port')
                        ->label('SMTP port')
                        ->numeric()
                        ->placeholder('587'),
                    Select::make('notification_credentials.mail_encryption')
                        ->label('SMTP encryption')
                        ->options(['tls' => 'TLS', 'ssl' => 'SSL', '' => 'None'])
                        ->default('tls'),
                    TextInput::make('notification_credentials.mail_username')
                        ->label('SMTP username')
                        ->maxLength(255),
                    TextInput::make('notification_credentials.mail_password')
                        ->label('SMTP password')
                        ->password()
                        ->revealable()
                        ->maxLength(255),
                    TextInput::make('notification_credentials.mail_from_address')
                        ->label('From address')
                        ->email()
                        ->maxLength(255)
                        ->helperText('Defaults to the SMTP username if left empty.'),
                    TextInput::make('notification_credentials.mail_from_name')
                        ->label('From name')
                        ->maxLength(255),
                    TextInput::make('notification_credentials.sms_api_url')
                        ->label('SMS gateway URL template')
                        ->maxLength(500)
                        ->placeholder('http://bulksmsbd.net/api/smsapi?api_key={api_key}&type=text&number={phone}&senderid={sender_id}&message={message}')
                        ->helperText('Used for login OTP, password reset, and cart reminders. Use {api_key}, {sender_id}, {phone}, {message} placeholders.')
                        ->columnSpanFull(),
                    TextInput::make('notification_credentials.sms_api_key')
                        ->label('SMS API key')
                        ->password()
                        ->revealable()
                        ->maxLength(255),
                    TextInput::make('notification_credentials.sms_sender_id')
                        ->label('SMS sender ID')
                        ->maxLength(100),
                    Select::make('notification_credentials.whatsapp_channel_id')
                        ->label('WhatsApp Chat Channel')
                        ->options(function (Get $get, ?StorefrontSetting $record): array {
                            $companyId = $get('company_id') ?: $record?->company_id ?: app(CompanyContext::class)->company()?->getKey();

                            return $companyId ? ConversationChannel::withoutGlobalScopes()
                                ->where('company_id', $companyId)
                                ->where('provider', 'whatsapp')
                                ->where('is_active', true)
                                ->orderBy('display_name')
                                ->get()
                                ->mapWithKeys(fn (ConversationChannel $channel): array => [
                                    $channel->getKey() => "{$channel->display_name} ({$channel->external_id})",
                                ])
                                ->all() : [];
                        })
                        ->searchable()
                        ->preload()
                        ->helperText('Recommended: use the company’s tested Chat Channel so the Inbox and reminders share one encrypted token and Phone Number ID.'),
                    TextInput::make('notification_credentials.whatsapp_token')
                        ->label('Legacy WhatsApp token')
                        ->password()
                        ->revealable()
                        ->maxLength(500)
                        ->helperText('Backward-compatible fallback only. Clear after selecting and verifying a Chat Channel.'),
                    TextInput::make('notification_credentials.whatsapp_phone_number_id')
                        ->label('Legacy WhatsApp Phone Number ID')
                        ->maxLength(100)
                        ->helperText('Backward-compatible fallback only when no active Chat Channel is available.'),
                    TextInput::make('notification_credentials.whatsapp_template_name')
                        ->label('WhatsApp template name')
                        ->maxLength(100)
                        ->helperText('Meta-approved template with two body variables: customer name and store name.'),
                    TextInput::make('notification_credentials.whatsapp_template_language')
                        ->label('WhatsApp template language')
                        ->maxLength(10)
                        ->placeholder('bn'),
                    TextInput::make('notification_credentials.whatsapp_recovery_template_name')
                        ->label('WhatsApp recovery-link template')
                        ->maxLength(100)
                        ->helperText('Optional Meta-approved template with three body variables: customer name, store name, and recovery URL. When empty, the existing two-variable reminder template remains unchanged.'),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(),

            Section::make('Lead Follow-up Reminders')
                ->columnSpanFull()
                ->description('When a lead\'s next follow-up date arrives, the assigned staff member is notified in-app, and (when enabled here) the lead is messaged over WhatsApp with an SMS fallback. Runs every 15 minutes via the scheduler. Uses the same SMS gateway and WhatsApp Chat Channel configured above.')
                ->schema([
                    Toggle::make('lead_follow_up_reminders_enabled')
                        ->label('Message the lead automatically')
                        ->default(false)
                        ->helperText('Staff are always notified in-app when a follow-up is due, regardless of this toggle.')
                        ->columnSpanFull(),
                    TextInput::make('notification_credentials.lead_follow_up_whatsapp_template_name')
                        ->label('WhatsApp template name')
                        ->maxLength(100)
                        ->helperText('Meta-approved template with one body variable: the lead\'s name.'),
                    Textarea::make('notification_credentials.lead_follow_up_sms_body')
                        ->label('SMS fallback message')
                        ->rows(3)
                        ->maxLength(500)
                        ->placeholder('Hi {{name}}, following up on your interest — reply to this SMS or call us any time.')
                        ->helperText('Sent when the WhatsApp template is empty, unset, or fails to deliver. Use {{name}} as a placeholder.'),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(),

            Section::make('Navigation Menus')
                ->columnSpanFull()
                ->description('Links shown in the storefront header navigation and the footer "Quick links" column. Leave empty to use the automatic defaults (Shop all, Track order, Account / published pages).')
                ->schema([
                    self::menuRepeater('header_menu', 'Header menu'),
                    self::menuRepeater('footer_menu', 'Footer menu'),
                ])
                ->collapsible()
                ->collapsed(),

            Section::make(__('Reseller Settings'))
                ->columnSpanFull()
                ->description(__('Money rules for the reseller program: the cost added to the wholesale rate, and the minimum withdrawal. Nothing here is shown on the public storefront.'))
                ->schema([
                    TextInput::make('reseller_cost_per_unit')
                        ->label(__('Reseller cost per unit'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix(fn (): string => app(CompanyContext::class)->company()?->currency ?: 'BDT')
                        ->helperText(__("Added to a product's lowest wholesale tier price to get a reseller's cost. Resellers can sell at any price at or above that cost; the difference is their commission.")),

                    TextInput::make('reseller_packaging_cost_per_unit')
                        ->label(__('Packaging charge per piece (own courier)'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix(fn (): string => app(CompanyContext::class)->company()?->currency ?: 'BDT')
                        ->helperText(__('Charged on orders a reseller sends with their own courier: this amount times the number of pieces. The reseller pays it in advance with the order.')),

                    TextInput::make('reseller_min_withdrawal')
                        ->label(__('Minimum reseller withdrawal'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix(fn (): string => app(CompanyContext::class)->company()?->currency ?: 'BDT')
                        ->helperText(__('The smallest amount a reseller can request to withdraw from their delivered-order commission.')),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(),

            Section::make('Footer Builder')
                ->columnSpanFull()
                ->description('Add, remove, and reorder the sections shown in the public footer. Leave empty to use the automatic default footer (brand, quick links, contact, bottom bar).')
                ->schema([
                    Toggle::make('reseller_program_enabled')
                        ->label('Show reseller program')
                        ->default(true)
                        ->helperText('Off hides "Become a reseller" from the account menu and footer. The reseller pages and dashboard keep working at their direct URL — only the links are hidden.')
                        ->columnSpanFull(),

                    Repeater::make('social_links')
                        ->label('Social links')
                        ->columnSpanFull()
                        ->reorderable()
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state): ?string => StorefrontSetting::SOCIAL_PLATFORMS[$state['platform'] ?? ''] ?? null)
                        ->addActionLabel('Add social link')
                        ->schema([
                            Select::make('platform')
                                ->options(StorefrontSetting::SOCIAL_PLATFORMS)
                                ->required(),
                            TextInput::make('url')
                                ->label('Profile URL')
                                ->url()
                                ->required()
                                ->maxLength(500),
                        ])
                        ->columns(2),

                    Repeater::make('footer_blocks')
                        ->label('Footer sections')
                        ->columnSpanFull()
                        ->reorderable()
                        ->collapsible()
                        ->defaultItems(0)
                        ->default(StorefrontSetting::DEFAULT_FOOTER_BLOCKS)
                        ->itemLabel(fn (array $state): ?string => StorefrontSetting::FOOTER_BLOCK_TYPES[$state['type'] ?? ''] ?? null)
                        ->addActionLabel('Add footer section')
                        ->schema([
                            Select::make('type')
                                ->options(StorefrontSetting::FOOTER_BLOCK_TYPES)
                                ->live()
                                ->required(),

                            TextInput::make('data.heading')
                                ->label('Heading')
                                ->maxLength(100)
                                ->visible(fn (Get $get): bool => in_array($get('type'), ['quick_links', 'contact_info', 'social_links'], true)),

                            Toggle::make('data.show_logo')
                                ->label('Show logo instead of company name')
                                ->visible(fn (Get $get): bool => $get('type') === 'brand_about'),
                            Textarea::make('data.text')
                                ->label('About text')
                                ->rows(2)
                                ->maxLength(500)
                                ->placeholder('Browse curated products, place direct orders, and track storefront purchases from {company name}.')
                                ->visible(fn (Get $get): bool => $get('type') === 'brand_about'),

                            TextInput::make('data.copyright_text')
                                ->label('Copyright text')
                                ->maxLength(255)
                                ->placeholder('© {year} {company_name}. All rights reserved.')
                                ->helperText('Supports {year} and {company_name} tokens.')
                                ->columnSpanFull()
                                ->visible(fn (Get $get): bool => $get('type') === 'bottom_bar'),
                            Repeater::make('data.legal_links')
                                ->label('Legal links')
                                ->schema([
                                    TextInput::make('label')->required()->maxLength(60),
                                    Select::make('page_id')
                                        ->label('Page')
                                        ->options(function (?StorefrontSetting $record): array {
                                            $companyId = $record?->company_id ?: app(CompanyContext::class)->company()?->getKey();

                                            return $companyId ? StorefrontPage::withoutGlobalScopes()
                                                ->where('company_id', $companyId)
                                                ->where('is_published', true)
                                                ->orderBy('title')
                                                ->pluck('title', 'id')
                                                ->all() : [];
                                        })
                                        ->required(),
                                ])
                                ->columns(2)
                                ->defaultItems(0)
                                ->addActionLabel('Add legal link')
                                ->visible(fn (Get $get): bool => $get('type') === 'bottom_bar'),
                        ])
                        ->columns(1),
                ])
                ->collapsible()
                ->collapsed(),

            Section::make('SEO')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('meta_title')
                        ->maxLength(70),
                    Textarea::make('meta_description')
                        ->rows(3)
                        ->maxLength(160)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make(__('Next.js Site'))
                ->columnSpanFull()
                ->description(__('The headless Next.js storefront reads this same storefront configuration through the ERP API. The Blade storefront above keeps working unchanged.'))
                ->schema([
                    Toggle::make('nextjs_site_enabled')
                        ->label(__('Enable Next.js site API'))
                        ->default(false)
                        ->helperText(__('When off, the Next.js site receives a 403 from /api/v1/storefront/*.')),
                    TextInput::make('nextjs_site_domain')
                        ->label(__('Next.js site domain'))
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => Company::normalizeDomain($state))
                        ->rule(fn (Get $get, ?StorefrontSetting $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                            $conflict = StorefrontSetting::nextjsDomainConflict(
                                (string) $value,
                                $get('company_id') ?: $record?->company_id,
                            );

                            if ($conflict) {
                                $fail($conflict);
                            }
                        })
                        ->helperText(__('Example: zamzamint.com. Do not include https:// or paths.'))
                        ->afterStateUpdated(fn (Set $set): mixed => $set('nextjs_site_domain_verified', false)),
                    Toggle::make('nextjs_site_domain_verified')
                        ->label(__('Domain verified'))
                        ->helperText(__('Changing the domain resets this status. Save the new domain, point DNS to the Coolify app, then turn this on in a second save.')),
                    Text::make(__('Create the API key and the cache-refresh webhook under Settings → Integrations → Website API. Keep the key in the Next.js server environment only, never in browser code.'))
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible(),
        ];

        $sectionKeys = [
            'publishing',
            'theme',
            'theme',
            'homepage',
            'design',
            'design',
            'design',
            'homepage',
            'homepage',
            'homepage',
            'launch_branding',
            'launch_branding',
            'checkout',
            'checkout',
            'integrations',
            'notifications',
            'notifications',
            'navigation_seo',
            'reseller',
            'navigation_seo',
            'navigation_seo',
            'nextjs_site',
        ];

        foreach ($components as $index => $component) {
            $section = $sectionKeys[$index] ?? 'publishing';

            $component
                ->hidden(fn ($livewire): bool => method_exists($livewire, 'isStorefrontSectionActive')
                    && ! $livewire->isStorefrontSectionActive($section))
                ->dehydratedWhenHidden()
                ->extraAttributes(['class' => 'zz-storefront-settings-anchor']);
        }

        return $schema->columns(1)->components($components);
    }

    protected static function menuRepeater(string $name, string $label): Repeater
    {
        $companyId = fn (Get $get, ?StorefrontSetting $record): ?int => $get('../../company_id') ?: $record?->company_id;

        return Repeater::make($name)
            ->label($label)
            ->columnSpanFull()
            ->reorderable()
            ->collapsible()
            ->defaultItems(0)
            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
            ->addActionLabel('Add menu item')
            ->schema([
                TextInput::make('label')
                    ->required()
                    ->maxLength(40),
                Select::make('type')
                    ->label('Link type')
                    ->options([
                        'shop' => 'Shop all products',
                        'category' => 'Category',
                        'page' => 'Content page',
                        'offers' => 'Offers',
                        'track' => 'Track order',
                        'account' => 'My account',
                        'reseller' => 'Become a reseller',
                        'custom' => 'Custom URL',
                    ])
                    ->default('shop')
                    ->required()
                    ->live(),
                Select::make('category_id')
                    ->label('Category')
                    ->visible(fn (Get $get): bool => $get('type') === 'category')
                    ->required(fn (Get $get): bool => $get('type') === 'category')
                    ->options(fn (Get $get, ?StorefrontSetting $record) => Category::withoutGlobalScopes()
                        ->where('company_id', $companyId($get, $record))
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')),
                Select::make('page_id')
                    ->label('Page')
                    ->visible(fn (Get $get): bool => $get('type') === 'page')
                    ->required(fn (Get $get): bool => $get('type') === 'page')
                    ->options(fn (Get $get, ?StorefrontSetting $record) => StorefrontPage::withoutGlobalScopes()
                        ->where('company_id', $companyId($get, $record))
                        ->where('is_published', true)
                        ->orderBy('title')
                        ->pluck('title', 'id')),
                TextInput::make('url')
                    ->label('Custom URL')
                    ->visible(fn (Get $get): bool => $get('type') === 'custom')
                    ->required(fn (Get $get): bool => $get('type') === 'custom')
                    ->maxLength(500)
                    ->placeholder('https://example.com or /some-path'),
                Toggle::make('new_tab')
                    ->label('Open in new tab')
                    ->default(false)
                    ->inline(false),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')
                    ->state(fn (StorefrontSetting $record): ?string => CompanyMedia::publicUrl($record->logo, $record))
                    ->height(36)
                    ->square()
                    ->toggleable(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company.domain')
                    ->label('Domain')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('sites')
                    ->label(__('Sites'))
                    ->state(fn (StorefrontSetting $record): array => self::siteBadges($record))
                    ->badge()
                    ->color(fn (string $state): string => str_starts_with($state, 'Next.js') ? 'success' : 'info')
                    ->wrap(),
                IconColumn::make('company.domain_verified')
                    ->label('Domain Verified')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('storefront_theme')
                    ->label('Theme')
                    ->formatStateUsing(fn (?string $state): string => StorefrontThemeRegistry::themeOptions()[StorefrontThemeRegistry::normalizeTheme($state)])
                    ->badge()
                    ->sortable(),
                TextColumn::make('homepage_template')
                    ->label('Homepage')
                    ->formatStateUsing(fn (?string $state, StorefrontSetting $record): string => StorefrontThemeRegistry::templateOptions($record->storefrontTheme())[$record->homepageTemplate()])
                    ->badge()
                    ->toggleable(),
                TextColumn::make('launch_readiness')
                    ->label('Launch Readiness')
                    ->state(fn (StorefrontSetting $record): string => self::readinessSummary($record))
                    ->badge()
                    ->color(fn (StorefrontSetting $record): string => self::readinessColor($record)),
                TextColumn::make('storefront_products_count')
                    ->label('Products')
                    ->state(fn (StorefrontSetting $record): int => self::productCount($record))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('storefront_pages_count')
                    ->label('Pages')
                    ->state(fn (StorefrontSetting $record): int => self::pageCount($record))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('missing_setup')
                    ->label('Missing Setup')
                    ->state(fn (StorefrontSetting $record): string => self::missingSetup($record))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('theme_color')
                    ->badge(),
                TextColumn::make('whatsapp_number')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->url(fn (StorefrontSetting $record): string => self::previewUrl($record))
                    ->openUrlInNewTab(),
                Action::make('openSite')
                    ->label('Open Site')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (StorefrontSetting $record): string => self::publicUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (StorefrontSetting $record): bool => filled($record->company?->domain)),
                Action::make('openNextjsSite')
                    ->label(__('Open Next.js site'))
                    ->icon('heroicon-o-computer-desktop')
                    ->url(fn (StorefrontSetting $record): string => 'https://'.$record->nextjs_site_domain)
                    ->openUrlInNewTab()
                    ->visible(fn (StorefrontSetting $record): bool => filled($record->nextjs_site_domain)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $context = app(CompanyContext::class);

        if ($context->isAllCompanies()) {
            return $query;
        }

        if ($context->hasCompany()) {
            return $query->where(
                $query->getModel()->qualifyColumn('company_id'),
                $context->id(),
            );
        }

        return $query->whereRaw('1 = 0');
    }

    public static function readinessChecks(StorefrontSetting $record): array
    {
        return [
            'Published' => $record->is_published,
            'Domain added' => filled($record->company?->domain),
            'Domain verified' => (bool) $record->company?->domain_verified,
            'Logo uploaded' => filled($record->logo),
            'Hero slide added' => StorefrontSlide::withoutGlobalScopes()
                ->where('company_id', $record->company_id)
                ->where('is_active', true)
                ->exists(),
            'SEO completed' => filled($record->meta_title) && filled($record->meta_description),
            'WhatsApp added' => filled($record->whatsapp_number),
            'Content pages' => self::pageCount($record) > 0,
            'Products visible' => self::productCount($record) > 0,
        ];
    }

    public static function readinessSummary(StorefrontSetting $record): string
    {
        $checks = self::readinessChecks($record);
        $passed = collect($checks)->filter()->count();

        return "{$passed}/".count($checks).' ready';
    }

    public static function readinessColor(StorefrontSetting $record): string
    {
        $checks = self::readinessChecks($record);
        $passed = collect($checks)->filter()->count();
        $total = count($checks);

        return match (true) {
            $passed === $total => 'success',
            $passed >= 6 => 'warning',
            default => 'danger',
        };
    }

    public static function missingSetup(StorefrontSetting $record): string
    {
        $missing = collect(self::readinessChecks($record))
            ->reject()
            ->keys()
            ->values();

        return $missing->isEmpty() ? 'Ready to launch' : $missing->implode(', ');
    }

    public static function productCount(StorefrontSetting $record): int
    {
        return Product::withoutGlobalScopes()
            ->where('company_id', $record->company_id)
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->count();
    }

    public static function pageCount(StorefrontSetting $record): int
    {
        return StorefrontPage::withoutGlobalScopes()
            ->where('company_id', $record->company_id)
            ->where('is_published', true)
            ->count();
    }

    public static function previewUrl(StorefrontSetting $record): string
    {
        return route('storefront.preview.show', (string) $record->company?->slug);
    }

    public static function publicUrl(StorefrontSetting $record): string
    {
        return 'https://'.$record->company->domain;
    }

    /**
     * The storefronts one company runs, for the list's "Sites" column: the
     * Blade storefront always exists; the Next.js site shows once it has a
     * domain or is switched on, and says "off" while the API is disabled.
     *
     * @return array<int, string>
     */
    public static function siteBadges(StorefrontSetting $record): array
    {
        $badges = ['Blade · '.($record->company?->domain ?: '—')];

        if ($record->nextjs_site_enabled || filled($record->nextjs_site_domain)) {
            $badges[] = 'Next.js · '.($record->nextjs_site_domain ?: '—')
                .($record->nextjs_site_enabled ? '' : ' ('.__('off').')');
        }

        return $badges;
    }

    protected static function contrastRule(Get $get, string $backgroundField, float $minimum, string $backgroundLabel): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $backgroundField, $minimum, $backgroundLabel): void {
            $ratio = StorefrontSetting::colorContrastRatio((string) $value, (string) $get($backgroundField));

            if ($ratio < $minimum) {
                $fail("Choose a color with at least {$minimum}:1 contrast against {$backgroundLabel}.");
            }
        };
    }

    public static function canViewAny(): bool
    {
        return SchemaFacade::hasTable('storefront_settings') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canCreate(): bool
    {
        return SchemaFacade::hasTable('storefront_settings') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canEdit($record): bool
    {
        return SchemaFacade::hasTable('storefront_settings') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canDelete($record): bool
    {
        return SchemaFacade::hasTable('storefront_settings') && (Auth::user()?->isSuperAdmin() ?? false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStorefrontSettings::route('/'),
            'create' => CreateStorefrontSetting::route('/create'),
            'edit' => EditStorefrontSetting::route('/{record}/edit'),
        ];
    }
}
