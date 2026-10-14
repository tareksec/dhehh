<?php

namespace App\Filament\Resources\StorefrontSettings\Schemas;

use App\Filament\Resources\StorefrontSettings\StorefrontSettingResource;
use App\Models\Category;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Support\StorefrontHomeStarter;
use App\Support\StorefrontSiteDesign;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The "Website Design" tab of the Next.js row in Site → Storefront Settings:
 * top bar, header, floating help button and the Home Page Builder. These are
 * the options the headless Next.js site reads (`/api/v1/storefront/site` →
 * `design` and `home_sections`); the Blade storefront has its own themes and
 * ignores them. All values are bounded and cleaned by StorefrontSiteDesign
 * before they leave the ERP, so the limits here only guide the admin.
 */
final class NextjsDesignSections
{
    /** @return list<Section> */
    public static function sections(): array
    {
        return [
            self::topBar(),
            self::header(),
            self::productCard(),
            self::floatingHelp(),
            self::homeBuilder(),
        ];
    }

    // ------------------------------------------------------------------ top bar

    private static function topBar(): Section
    {
        return Section::make(__('Top Bar'))
            ->columnSpanFull()
            ->description(__('The thin dark strip above the header: social icons on the left, quick links on the right.'))
            ->schema([
                Toggle::make('site_design.topbar.enabled')
                    ->label(__('Show the top bar'))
                    ->default(true),
                Toggle::make('site_design.topbar.show_social')
                    ->label(__('Show social icons'))
                    ->helperText(__('Uses the social links from the Footer Builder section.'))
                    ->default(true),
                TextInput::make('site_design.topbar.message')
                    ->label(__('Short message'))
                    ->maxLength(160)
                    ->helperText(__('Optional line shown in the middle of the bar, for example a delivery notice.')),
                ColorPicker::make('site_design.topbar.background')
                    ->label(__('Background colour'))
                    ->default(StorefrontSiteDesign::DEFAULT_TOPBAR_BACKGROUND),
                self::linkRepeater('site_design.topbar.links', __('Quick links (right side)'), 6, withIcon: true)
                    ->helperText(__('For example Mobile App, Customer Service, Track Order. Up to 6 links.')),
            ])
            ->columns(2)
            ->collapsible();
    }

    // ------------------------------------------------------------------- header

    private static function header(): Section
    {
        return Section::make(__('Header'))
            ->columnSpanFull()
            ->description(__('The logo line, search box, icons, support phone and the category menu. The logo itself is uploaded under Launch & Branding.'))
            ->schema([
                TextInput::make('site_design.header.tagline')
                    ->label(__('Tagline under the site name'))
                    ->maxLength(80)
                    ->helperText(__('Shown next to the site name when no logo image is uploaded, and in the footer.')),
                TextInput::make('site_design.header.search_placeholder')
                    ->label(__('Search box hint'))
                    ->maxLength(80),
                Toggle::make('site_design.header.show_search')
                    ->label(__('Show the search box'))
                    ->default(true),
                Toggle::make('site_design.header.show_theme_toggle')
                    ->label(__('Show the dark-mode button'))
                    ->default(true),
                Toggle::make('site_design.header.show_account')
                    ->label(__('Show the account icon'))
                    ->default(true),
                Toggle::make('site_design.header.show_wishlist')
                    ->label(__('Show the wishlist icon'))
                    ->default(true),
                Toggle::make('site_design.header.show_support')
                    ->label(__('Show the support phone'))
                    ->default(true)
                    ->live(),
                TextInput::make('site_design.header.support_label')
                    ->label(__('Support label'))
                    ->maxLength(30)
                    ->visible(fn (Get $get): bool => (bool) $get('site_design.header.show_support')),
                TextInput::make('site_design.header.support_phone')
                    ->label(__('Support phone'))
                    ->tel()
                    ->maxLength(40)
                    ->helperText(__('Leave blank to use the helpline, else the support number from Publishing.'))
                    ->visible(fn (Get $get): bool => (bool) $get('site_design.header.show_support')),
                Toggle::make('site_design.header.show_categories_menu')
                    ->label(__('Show the "All categories" menu button'))
                    ->default(true)
                    ->live(),
                TextInput::make('site_design.header.categories_label')
                    ->label(__('"All categories" button label'))
                    ->maxLength(30)
                    ->visible(fn (Get $get): bool => (bool) $get('site_design.header.show_categories_menu')),
                Toggle::make('site_design.header.sticky')
                    ->label(__('Keep the header visible while scrolling'))
                    ->default(true),
                Text::make(__('The menu links beside the category button are the Header menu under Navigation & SEO.'))
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->collapsible()
            ->collapsed();
    }

    // ------------------------------------------------------------- product card

    private static function productCard(): Section
    {
        return Section::make(__('Product Cards'))
            ->columnSpanFull()
            ->description(__('How every product card looks on the Next.js site: colours and which parts to show.'))
            ->schema([
                ColorPicker::make('site_design.product_card.price_color')
                    ->label(__('Price colour'))
                    ->default(StorefrontSiteDesign::DEFAULT_PRICE_COLOR),
                ColorPicker::make('site_design.product_card.badge_color')
                    ->label(__('Discount badge colour'))
                    ->default(StorefrontSiteDesign::DEFAULT_BADGE_COLOR),
                Toggle::make('site_design.product_card.show_stock')
                    ->label(__('Show the stock label'))
                    ->default(true),
                Toggle::make('site_design.product_card.show_add_to_cart')
                    ->label(__('Show the "Add to cart" button'))
                    ->default(true),
                Toggle::make('site_design.product_card.show_wishlist')
                    ->label(__('Show the wishlist heart'))
                    ->default(true),
                Toggle::make('site_design.product_card.show_quick_view')
                    ->label(__('Show the quick-view button'))
                    ->default(true),
                Toggle::make('site_design.product_card.show_rating')
                    ->label(__('Show the star rating'))
                    ->default(true),
            ])
            ->columns(2)
            ->collapsible()
            ->collapsed();
    }

    // ------------------------------------------------------------ floating help

    private static function floatingHelp(): Section
    {
        return Section::make(__('Floating Help Button'))
            ->columnSpanFull()
            ->description(__('A small "Need help?" button fixed to the bottom corner of every page.'))
            ->schema([
                Toggle::make('site_design.floating_help.enabled')
                    ->label(__('Show the help button'))
                    ->default(true),
                TextInput::make('site_design.floating_help.title')
                    ->label(__('Title'))
                    ->maxLength(40),
                TextInput::make('site_design.floating_help.subtitle')
                    ->label(__('Subtitle'))
                    ->maxLength(60),
                TextInput::make('site_design.floating_help.url')
                    ->label(__('Link'))
                    ->maxLength(500)
                    ->placeholder('https://wa.me/8801XXXXXXXXX')
                    ->helperText(__('Leave blank to open a WhatsApp chat with the WhatsApp number from Publishing. With no link and no number the button is hidden.')),
            ])
            ->columns(2)
            ->collapsible()
            ->collapsed();
    }

    // ----------------------------------------------------------- home page builder

    private static function homeBuilder(): Section
    {
        return Section::make(__('Home Page Builder'))
            ->key('home_builder')
            ->columnSpanFull()
            ->headerActions([self::loadStarterLayoutAction(), self::fillCategoryDetailsAction()])
            ->description(__('Add, remove and reorder the sections of the home page. Hero banners are managed under Site → Hero Slides, flash-sale / trending / new-arrival products under Site → Offers (Collection), prices and stock come from the catalog. Leave a title blank to use the website\'s default heading.'))
            ->schema([
                Builder::make('home_sections')
                    ->label(__('Home page sections'))
                    ->columnSpanFull()
                    ->reorderable()
                    ->collapsible()
                    ->collapsed()
                    ->blockNumbers(false)
                    ->addActionLabel(__('Add section'))
                    ->default(StorefrontSiteDesign::homeSections(null, forForm: true))
                    ->blocks([
                        self::heroBlock(),
                        self::trustStripBlock(),
                        self::categoryGridBlock(),
                        self::dealsBannerBlock(),
                        self::productRowBlock(),
                        self::flashSaleBlock(),
                        self::bannersBlock(),
                        self::mobileCtaBlock(),
                        self::textBlock(),
                        self::seoTextBlock(),
                        self::businessCalloutBlock(),
                    ]),
            ])
            ->collapsible();
    }

    /**
     * Fills the builder with the starter home page (StorefrontHomeStarter). It only changes the form:
     * the owner reviews the sections and presses Save changes.
     */
    private static function loadStarterLayoutAction(): Action
    {
        return Action::make('loadStarterLayout')
            ->label(__('Load the starter layout'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Load the starter home page?'))
            ->modalDescription(__('This replaces every section below with the starter home page — hero, trust strip, shop by category, best deals, latest products, flash deal, four category rows, the WhatsApp section and the about text — and adds the top bar quick links if there are none. Nothing is saved until you press Save changes, and you can edit every word afterwards.'))
            ->modalSubmitActionLabel(__('Load the starter layout'))
            ->action(function (Set $set, Get $get, ?StorefrontSetting $record): void {
                $sections = StorefrontHomeStarter::sections(self::companyCategories($record));

                $set('home_sections', collect($sections)->mapWithKeys(fn (array $section): array => [(string) Str::uuid() => $section])->all());

                if (blank($get('site_design.topbar.links'))) {
                    $set('site_design.topbar.links', collect(StorefrontHomeStarter::topBarLinks())->mapWithKeys(fn (array $link): array => [(string) Str::uuid() => $link])->all());
                }

                Notification::make()
                    ->success()
                    ->title(__('Starter layout loaded'))
                    ->body(__('Check the sections, then press Save changes.'))
                    ->send();
            });
    }

    /**
     * The category cards show a tagline and an icon from Categories; this fills the blank ones with
     * the reference's. It writes straight away (unlike the layout), so it only touches blanks and
     * only categories the signed-in user may edit.
     */
    private static function fillCategoryDetailsAction(): Action
    {
        return Action::make('fillCategoryDetails')
            ->label(__('Fill empty category details'))
            ->icon('heroicon-o-tag')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Fill the empty category taglines and icons?'))
            ->modalDescription(__('Adds the short line (for example "Earbuds · TWS · Headsets") and the icon shown on the category cards to the categories that have none, matching them by name. A tagline or icon you already set is never changed. This is saved straight away.'))
            ->modalSubmitActionLabel(__('Fill them'))
            ->action(function (?StorefrontSetting $record): void {
                $categories = self::companyCategories($record);
                $changed = 0;

                foreach (StorefrontHomeStarter::categoryFills($categories) as $id => $fill) {
                    $category = $categories->firstWhere('id', $id);

                    if ($category && Gate::allows('update', $category)) {
                        $category->forceFill($fill)->save();
                        $changed++;
                    }
                }

                Notification::make()
                    ->success()
                    ->title($changed > 0 ? __(':count categories updated', ['count' => $changed]) : __('Nothing to fill'))
                    ->body($changed > 0 ? null : __('Every matching category already has its tagline and icon.'))
                    ->send();
            });
    }

    /** @return Collection<int, Category> */
    private static function companyCategories(?StorefrontSetting $record): Collection
    {
        $companyId = self::companyId($record);

        return $companyId ? Category::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get() : collect();
    }

    private static function block(string $type, ?string $titleKey = 'title'): Block
    {
        return Block::make($type)
            ->label(function (?array $state) use ($type, $titleKey): string {
                $label = __(StorefrontSiteDesign::HOME_SECTION_TYPES[$type]);
                $data = $state['data'] ?? $state ?? [];
                $title = $titleKey ? ($data[$titleKey] ?? null) : null;
                $off = array_key_exists('enabled', (array) $data) && ! $data['enabled'] ? ' ('.__('hidden').')' : '';

                return $label.(filled($title) ? ' — '.$title : '').$off;
            });
    }

    private static function enabledToggle(): Toggle
    {
        return Toggle::make('enabled')
            ->label(__('Show this section'))
            ->default(true);
    }

    private static function heroBlock(): Block
    {
        return self::block('hero', null)->schema([
            self::enabledToggle(),
            Select::make('height')
                ->label(__('Banner height'))
                ->options(self::translated(StorefrontSiteDesign::HERO_HEIGHTS))
                ->default('standard')
                ->native(false),
            TextInput::make('autoplay_seconds')
                ->label(__('Seconds per slide'))
                ->numeric()->minValue(2)->maxValue(20)->default(5),
            Toggle::make('show_dots')->label(__('Show the dots'))->default(true),
            Toggle::make('show_arrows')->label(__('Show the arrows'))->default(false),
            Text::make(__('The banners are Site → Hero Slides. Use "Show on" there to choose which site a slide appears on.'))
                ->columnSpanFull(),
        ])->columns(2);
    }

    private static function trustStripBlock(): Block
    {
        return self::block('trust_strip', null)->schema([
            self::enabledToggle(),
            Repeater::make('items')
                ->label(__('Highlights'))
                ->columnSpanFull()
                ->reorderable()
                ->collapsible()
                ->defaultItems(0)
                ->maxItems(6)
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->addActionLabel(__('Add highlight'))
                ->helperText(__('Leave empty to use the Trust Strip messages from the Homepage tab.'))
                ->schema([
                    Select::make('icon')
                        ->label(__('Icon'))
                        ->options(self::translated(StorefrontSiteDesign::TRUST_ICONS))
                        ->default('truck')
                        ->native(false)
                        ->required(),
                    TextInput::make('title')->label(__('Title'))->required()->maxLength(60),
                    TextInput::make('subtitle')->label(__('Subtitle'))->maxLength(80),
                ])
                ->columns(3),
        ]);
    }

    private static function categoryGridBlock(): Block
    {
        return self::block('category_grid')->schema([
            self::enabledToggle(),
            TextInput::make('title')->label(__('Heading'))->maxLength(80),
            TextInput::make('subtitle')->label(__('Sub-heading'))->maxLength(160),
            Select::make('category_ids')
                ->label(__('Categories'))
                ->multiple()
                ->searchable()
                ->options(self::categoryOptions())
                ->helperText(__('Leave empty to show every category that has products, in alphabetical order.'))
                ->columnSpanFull(),
            Select::make('style')
                ->label(__('Style'))
                ->options([
                    'tiles' => __('Cards with icon and tagline'),
                    'circles' => __('Round pictures'),
                ])
                ->default('tiles')
                ->native(false),
            TextInput::make('limit')->label(__('How many'))->numeric()->minValue(1)->maxValue(30)->default(12),
            Toggle::make('show_tagline')->label(__('Show each category\'s tagline'))->default(true),
            Toggle::make('show_count')->label(__('Show the product count'))->default(false),
        ])->columns(2);
    }

    private static function dealsBannerBlock(): Block
    {
        return self::block('deals_banner')->schema([
            self::enabledToggle(),
            TextInput::make('title')->label(__('Heading'))->maxLength(80),
            TextInput::make('subtitle')->label(__('Sub-heading'))->maxLength(160),
            Select::make('source')
                ->label(__('Products'))
                ->options(self::translated(self::sourceOptions(['top_discount', 'flash_sale', 'trending', 'new_arrivals'])))
                ->default('top_discount')
                ->native(false),
            TextInput::make('limit')->label(__('How many'))->numeric()->minValue(2)->maxValue(8)->default(4),
            TextInput::make('link_label')->label(__('Link label'))->maxLength(40),
            TextInput::make('link_url')
                ->label(__('Link'))
                ->maxLength(500)
                ->placeholder('/products')
                ->helperText(__('Leave blank to open the product list.')),
            ColorPicker::make('color_from')->label(__('Gradient start'))->helperText(__('Leave blank to use the brand colour.')),
            ColorPicker::make('color_to')->label(__('Gradient end')),
        ])->columns(2);
    }

    private static function productRowBlock(): Block
    {
        return self::block('product_row')->schema([
            self::enabledToggle(),
            TextInput::make('title')->label(__('Heading'))->maxLength(80),
            TextInput::make('subtitle')->label(__('Sub-heading'))->maxLength(160),
            Select::make('source')
                ->label(__('Products'))
                ->options(self::translated(StorefrontSiteDesign::PRODUCT_SOURCES))
                ->default('latest')
                ->native(false)
                ->live(),
            Select::make('category_ids')
                ->label(__('Categories'))
                ->options(self::categoryOptions())
                ->multiple()
                ->maxItems(8)
                ->searchable()
                ->native(false)
                ->helperText(__('Pick one or more — the row mixes products from all of them.'))
                ->visible(fn (Get $get): bool => $get('source') === 'category')
                ->required(fn (Get $get): bool => $get('source') === 'category'),
            TextInput::make('limit')->label(__('How many products'))->numeric()->minValue(1)->maxValue(24)->default(6),
            Select::make('columns')
                ->label(__('Columns on a large screen'))
                ->options([4 => '4', 5 => '5', 6 => '6'])
                ->default(6)
                ->native(false),
            TextInput::make('link_label')->label(__('"View all" label'))->maxLength(40),
            TextInput::make('link_url')
                ->label(__('"View all" link'))
                ->maxLength(500)
                ->helperText(__('Leave blank to link to the matching product list.')),
        ])->columns(2);
    }

    private static function flashSaleBlock(): Block
    {
        return self::block('flash_sale')->schema([
            self::enabledToggle(),
            TextInput::make('badge')->label(__('Badge text'))->maxLength(40),
            TextInput::make('title')
                ->label(__('Heading'))
                ->maxLength(80)
                ->helperText(__('Leave blank to use the Flash Sale offer\'s own title.')),
            Textarea::make('description')
                ->label(__('Description'))
                ->rows(2)
                ->maxLength(200)
                ->helperText(__('Leave blank to use the Flash Sale offer\'s short description.'))
                ->columnSpanFull(),
            Select::make('source')
                ->label(__('Products'))
                ->options(self::translated(self::sourceOptions(['flash_sale', 'top_discount', 'trending', 'new_arrivals'])))
                ->default('flash_sale')
                ->native(false),
            TextInput::make('limit')->label(__('How many'))->numeric()->minValue(2)->maxValue(6)->default(4),
            Toggle::make('show_countdown')
                ->label(__('Show the countdown'))
                ->helperText(__('Needs a Flash Sale offer with an end time.'))
                ->default(true),
            TextInput::make('countdown_label')->label(__('Countdown label'))->maxLength(40),
            TextInput::make('button_label')->label(__('Button label'))->maxLength(40),
            TextInput::make('button_url')
                ->label(__('Button link'))
                ->maxLength(500)
                ->helperText(__('Leave blank to open the matching product list.')),
            ColorPicker::make('background')->label(__('Background colour'))->default(StorefrontSiteDesign::DEFAULT_FLASH_BACKGROUND),
        ])->columns(2);
    }

    private static function bannersBlock(): Block
    {
        return self::block('banners', null)->schema([
            self::enabledToggle(),
            Select::make('columns')
                ->label(__('Banners per row'))
                ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4'])
                ->default(2)
                ->native(false),
            Repeater::make('items')
                ->label(__('Banners'))
                ->columnSpanFull()
                ->reorderable()
                ->collapsible()
                ->defaultItems(0)
                ->maxItems(6)
                ->itemLabel(fn (array $state): ?string => $state['alt'] ?? null)
                ->addActionLabel(__('Add banner'))
                ->schema([
                    StorefrontSettingResource::designImageUpload('image', __('Image'))->required(),
                    TextInput::make('alt')->label(__('Description of the image'))->maxLength(120),
                    TextInput::make('url')->label(__('Link'))->maxLength(500)->placeholder('/category/audio'),
                    Toggle::make('new_tab')->label(__('Open in a new tab'))->default(false)->inline(false),
                ])
                ->columns(2),
        ]);
    }

    private static function mobileCtaBlock(): Block
    {
        return self::block('mobile_cta', 'heading')->schema([
            self::enabledToggle(),
            TextInput::make('eyebrow')->label(__('Small label above the heading'))->maxLength(60),
            TextInput::make('heading')
                ->label(__('Heading'))
                ->maxLength(120)
                ->helperText(__('The section is hidden while this is empty.')),
            Textarea::make('text')->label(__('Text'))->rows(2)->maxLength(300)->columnSpanFull(),
            Toggle::make('show_whatsapp')
                ->label(__('Show the WhatsApp button'))
                ->helperText(__('Uses the WhatsApp number from Publishing.'))
                ->default(true),
            TextInput::make('whatsapp_label')->label(__('WhatsApp button label'))->maxLength(40),
            Toggle::make('show_call')
                ->label(__('Show the call button'))
                ->helperText(__('Uses the support number from Publishing.'))
                ->default(true),
            TextInput::make('call_label')
                ->label(__('Call button label'))
                ->maxLength(40)
                ->helperText(__('Use {phone} for the number, for example "Call: {phone}".')),
            Toggle::make('show_mockups')
                ->label(__('Show the phone pictures with products'))
                ->default(true),
            TextInput::make('mockup_button_label')->label(__('Button text inside the phone pictures'))->maxLength(30),
            ColorPicker::make('background')->label(__('Background colour'))->default(StorefrontSiteDesign::DEFAULT_MOBILE_CTA_BACKGROUND),
        ])->columns(2);
    }

    private static function textBlock(): Block
    {
        return self::block('text', 'heading')->schema([
            self::enabledToggle(),
            TextInput::make('heading')->label(__('Heading'))->maxLength(120),
            Textarea::make('body')->label(__('Text'))->rows(4)->maxLength(2000)->columnSpanFull(),
            TextInput::make('button_label')->label(__('Button label'))->maxLength(40),
            TextInput::make('button_url')->label(__('Button link'))->maxLength(500),
            Select::make('align')
                ->label(__('Alignment'))
                ->options(['left' => __('Left'), 'center' => __('Centre')])
                ->default('left')
                ->native(false),
            Select::make('tone')
                ->label(__('Background'))
                ->options(self::translated(StorefrontSiteDesign::TEXT_TONES))
                ->default('plain')
                ->native(false),
        ])->columns(2);
    }

    private static function seoTextBlock(): Block
    {
        return self::block('seo_text', 'heading')->schema([
            self::enabledToggle(),
            TextInput::make('heading')->label(__('Heading'))->maxLength(120),
            Textarea::make('body')
                ->label(__('Text'))
                ->rows(5)
                ->maxLength(3000)
                ->helperText(__('You can use {company_name}, {address}, {products_count} and {categories_count}; they are filled in from the catalog. The section is hidden while this is empty.'))
                ->columnSpanFull(),
            TextInput::make('collapsed_lines')
                ->label(__('Lines shown before "Read more"'))
                ->numeric()->minValue(2)->maxValue(10)->default(3),
        ])->columns(2);
    }

    private static function businessCalloutBlock(): Block
    {
        return self::block('business_callout', null)->schema([
            self::enabledToggle(),
            Text::make(__('Shows the business-account heading and text. Edit them under Marketplace Pro Content; the section stays hidden while business accounts are switched off.'))
                ->columnSpanFull(),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Menu-style links (label, target, new tab) for the top bar and a custom
     * footer column. Same link types as the header and footer menus.
     */
    public static function linkRepeater(string $name, string $label, int $max, bool $withIcon = false): Repeater
    {
        return Repeater::make($name)
            ->label($label)
            ->columnSpanFull()
            ->reorderable()
            ->collapsible()
            ->defaultItems(0)
            ->maxItems($max)
            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
            ->addActionLabel(__('Add link'))
            ->schema([
                TextInput::make('label')->label(__('Label'))->required()->maxLength(40),
                Select::make('type')
                    ->label(__('Link type'))
                    ->options(self::translated(StorefrontSiteDesign::LINK_TYPES))
                    ->default('shop')
                    ->native(false)
                    ->required()
                    ->live(),
                Select::make('category_id')
                    ->label(__('Category'))
                    ->visible(fn (Get $get): bool => $get('type') === 'category')
                    ->required(fn (Get $get): bool => $get('type') === 'category')
                    ->options(self::categoryOptions()),
                Select::make('page_id')
                    ->label(__('Page'))
                    ->visible(fn (Get $get): bool => $get('type') === 'page')
                    ->required(fn (Get $get): bool => $get('type') === 'page')
                    ->options(fn (?StorefrontSetting $record): array => self::companyId($record) ? StorefrontPage::withoutGlobalScopes()
                        ->where('company_id', self::companyId($record))
                        ->when($record, fn ($query) => $query->forSite($record))
                        ->where('is_published', true)
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all() : []),
                TextInput::make('url')
                    ->label(__('Custom URL'))
                    ->visible(fn (Get $get): bool => $get('type') === 'custom')
                    ->required(fn (Get $get): bool => $get('type') === 'custom')
                    ->maxLength(500)
                    ->placeholder('https://example.com or /some-path'),
                Select::make('icon')
                    ->label(__('Small icon'))
                    ->options(self::translated(StorefrontSiteDesign::LINK_ICONS))
                    ->default('none')
                    ->native(false)
                    ->visible($withIcon),
                Toggle::make('new_tab')->label(__('Open in a new tab'))->default(false)->inline(false),
            ])
            ->columns(2);
    }

    /** @return \Closure(?StorefrontSetting): array<int, string> */
    public static function categoryOptions(): \Closure
    {
        return fn (?StorefrontSetting $record): array => self::companyId($record) ? Category::withoutGlobalScopes()
            ->where('company_id', self::companyId($record))
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all() : [];
    }

    /** The company the settings row belongs to (or, on the create page, the active one). */
    private static function companyId(?StorefrontSetting $record): ?int
    {
        return $record?->company_id ?: app(CompanyContext::class)->company()?->getKey();
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private static function translated(array $labels): array
    {
        return array_map(fn (string $label): string => __($label), $labels);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private static function sourceOptions(array $keys): array
    {
        return array_intersect_key(StorefrontSiteDesign::PRODUCT_SOURCES, array_flip($keys));
    }
}
