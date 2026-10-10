@php
    $themePalette = $setting->themePalette();
    $themeColor = $themePalette['primary'];
    $themeForeground = $themePalette['primary_contrast'];
    $themeMode = $setting->theme_mode ?: 'system';
    $googleFontsUrl = $setting->googleFontsUrl();
    $cornerRadii = $setting->appearanceCornerRadii();
    $logoUrl = \App\Support\CompanyMedia::publicUrl($setting->logo, $company);
    $logoDarkUrl = \App\Support\CompanyMedia::publicUrl($setting->logo_dark, $company);
    $title = $setting->meta_title ?: $company->name;
    $description = $setting->meta_description ?: 'Shop products from '.$company->name;
    $bannerImage = \App\Models\StorefrontSlide::forCompany($company->getKey())->first()?->image;
    $shareImageUrl = \App\Support\CompanyMedia::publicUrl($bannerImage, $company) ?: ($logoUrl ?: null);
    $previewSlug = $previewSlug ?? null; // isset() stays false when null
    $resellerStoreSlug = \App\Support\StorefrontUrl::currentResellerSlug();
    // Inside a reseller's own store the header carries only that reseller's
    // name/brand -- never the company's name or logo (owner's answer,
    // 13_RESELLER_PORTAL_PLAN.md §2.1 Q3). The reseller's own logos (light
    // and dark) come from the portal's My Store page; none uploaded = name only.
    $storeReseller = request()->attributes->get('storefront_reseller');
    $brandName = $storeReseller ? $storeReseller->resellerStoreName() : $company->name;
    $brandLogoUrl = $storeReseller ? $storeReseller->resellerLogoUrl() : $logoUrl;
    $brandLogoDarkUrl = $storeReseller ? $storeReseller->resellerLogoDarkUrl() : $logoDarkUrl;
    $homeUrl = $resellerStoreSlug
        ? \App\Support\StorefrontUrl::route('show', [], $previewSlug)
        : (isset($previewSlug) ? route('storefront.preview.show', $previewSlug) : route('marketing.home'));
    $productsUrl = \App\Support\StorefrontUrl::route('products.index', [], $previewSlug);
    $cartUrl = \App\Support\StorefrontUrl::route('cart.show', [], $previewSlug);
    $trackUrl = \App\Support\StorefrontUrl::route('track.index', [], $previewSlug ?? null);
    $accountOrdersUrl = \App\Support\StorefrontUrl::route('account.orders', [], $previewSlug ?? null);
    $offersUrl = \App\Support\StorefrontUrl::route('offers.index', [], $previewSlug ?? null);
    $cartCount = app(\App\Services\StorefrontCart::class)->count($company);
    $footerPages = \Illuminate\Support\Facades\Schema::hasTable('storefront_pages')
        ? \App\Models\StorefrontPage::query()
            ->where('company_id', $company->getKey())
            ->forSite($setting)
            ->published()
            ->orderBy('sort_order')
            ->orderBy('title')
            ->limit(6)
            ->get()
        : collect();
    $navCategories = \App\Models\Category::query()
        ->where('company_id', $company->getKey())
        ->where('is_active', true)
        ->whereHas('products', fn ($query) => $query->where('is_active', true)->where('status', \App\Models\Product::STATUS_AVAILABLE))
        ->orderBy('name')
        ->limit(10)
        ->get();
    $categoryUrl = fn (\App\Models\Category $category) => \App\Support\StorefrontUrl::route('categories.show', [$category->slug], $previewSlug);
    // Owner's answer (13_RESELLER_PORTAL_PLAN.md §2.1 Q4): applications now
    // go through the reseller portal, not the old in-storefront /reseller
    // page. Preview has no portal host to link to, so it keeps the old page.
    $resellerJoinUrl = isset($previewSlug) ? null : $company->resellerJoinUrl();
    $resellerUrl = $resellerJoinUrl
        ?: \App\Support\StorefrontUrl::route('reseller.show', [], $previewSlug ?? null);
    // Someone who already applied goes to the portal (their status), not the guide.
    $resellerPortalHost = isset($previewSlug) ? null : $company->resellerPortalHost();
    $resellerStatusUrl = $resellerPortalHost ? request()->getScheme().'://'.$resellerPortalHost.'/portal' : $resellerUrl;
    $pageUrl = fn (string $slug) => \App\Support\StorefrontUrl::route('pages.show', [$slug], $previewSlug ?? null);

    $accountsEnabled = (bool) ($setting->customer_accounts_enabled ?? true);
    $authCustomer = $accountsEnabled ? \Illuminate\Support\Facades\Auth::guard('customer')->user() : null;
    $accountRoute = static fn (string $name) => isset($previewSlug)
        ? route('storefront.preview.account.'.$name, $previewSlug)
        : route('storefront.account.'.$name);
    $loginUrl = $accountsEnabled ? $accountRoute('login') : null;
    $registerUrl = $accountsEnabled ? $accountRoute('register') : null;
    $accountUrl = $accountsEnabled ? $accountRoute('index') : null;
    $profileUrl = $accountsEnabled ? $accountRoute('profile') : null;
    $activityUrl = $accountsEnabled ? $accountRoute('activity') : null;
    $logoutUrl = $accountsEnabled ? $accountRoute('logout') : null;
    $mobileAccountUrl = $accountsEnabled ? ($authCustomer ? $accountUrl : $loginUrl) : $trackUrl;
    $isCurrentUrl = static function (?string $candidate): bool {
        if (blank($candidate)) {
            return false;
        }

        $candidateHost = parse_url($candidate, PHP_URL_HOST);
        if ($candidateHost && strcasecmp($candidateHost, request()->getHost()) !== 0) {
            return false;
        }

        $candidatePath = parse_url($candidate, PHP_URL_PATH) ?: '/';
        $currentPath = '/'.ltrim(request()->path(), '/');

        return rtrim($candidatePath, '/') === rtrim($currentPath, '/');
    };
    $isHomeCurrent = request()->routeIs('marketing.home', 'storefront.preview.show', 'storefront.reseller_store.show');
    $isCatalogCurrent = request()->routeIs(
        'storefront.products.*',
        'storefront.categories.*',
        'storefront.preview.products.*',
        'storefront.preview.categories.*',
        'storefront.reseller_store.products.*',
        'storefront.reseller_store.categories.*',
    );
    $isCartCurrent = request()->routeIs(
        'storefront.cart.*',
        'storefront.checkout.*',
        'storefront.preview.cart.*',
        'storefront.preview.checkout.*',
        'storefront.reseller_store.cart.*',
        'storefront.reseller_store.checkout.*',
    );
    $isAccountCurrent = $accountsEnabled
        ? request()->routeIs('storefront.account.*')
        : request()->routeIs('storefront.track.*', 'storefront.preview.track.*');

    // Admin-managed navigation menus (Storefront Settings → Navigation Menus).
    // Footer Builder "Custom links" columns hold menu-style links too.
    $customFooterLinks = collect($setting->footerBlocks())->where('type', 'custom_links')->flatMap(fn ($block) => $block['data']['links'] ?? [])->all();
    $menuCategoryIds = collect([$setting->header_menu, $setting->footer_menu, $customFooterLinks])->flatten(1)->filter()->where('type', 'category')->pluck('category_id')->filter()->unique();
    $menuPageSlugs = collect([$setting->header_menu, $setting->footer_menu, $customFooterLinks])->flatten(1)->filter()->where('type', 'page')->pluck('page_id')->filter()->unique();
    $menuCategoryData = $menuCategoryIds->isEmpty() ? collect() : \App\Models\Category::withoutGlobalScopes()->where('company_id', $company->getKey())->whereIn('id', $menuCategoryIds)->where('is_active', true)->get(['id', 'slug', 'icon'])->keyBy('id');
    $menuPageSlugs = $menuPageSlugs->isEmpty() ? collect() : \App\Models\StorefrontPage::withoutGlobalScopes()->where('company_id', $company->getKey())->forSite($setting)->whereIn('id', $menuPageSlugs)->where('is_published', true)->pluck('slug', 'id');
    $resolveMenu = function (?array $items) use ($productsUrl, $trackUrl, $accountOrdersUrl, $resellerUrl, $offersUrl, $previewSlug, $menuCategoryData, $menuPageSlugs, $pageUrl) {
        return collect($items ?? [])->map(function ($item) use ($productsUrl, $trackUrl, $accountOrdersUrl, $resellerUrl, $offersUrl, $previewSlug, $menuCategoryData, $menuPageSlugs, $pageUrl) {
            $label = trim((string) ($item['label'] ?? ''));
            $menuCategory = ($item['type'] ?? null) === 'category' ? $menuCategoryData->get($item['category_id'] ?? null) : null;
            $url = match ($item['type'] ?? null) {
                'shop' => $productsUrl,
                'track' => $trackUrl,
                'account' => $accountOrdersUrl,
                'reseller' => $resellerUrl,
                'offers' => $offersUrl,
                'category' => $menuCategory
                    ? \App\Support\StorefrontUrl::route('categories.show', [$menuCategory->slug], $previewSlug)
                    : null,
                'page' => ($slug = $menuPageSlugs->get($item['page_id'] ?? null)) ? $pageUrl($slug) : null,
                'custom' => filled($item['url'] ?? null) ? $item['url'] : null,
                default => null,
            };

            return ($label === '' || $url === null) ? null : ['label' => $label, 'url' => $url, 'new_tab' => (bool) ($item['new_tab'] ?? false), 'icon' => $menuCategory?->icon];
        })->filter()->values();
    };
    $headerMenu = $resolveMenu($setting->header_menu);
    $footerMenu = $resolveMenu($setting->footer_menu);
    $sisterCompanies = \App\Models\Company::query()
        ->where('id', '!=', $company->getKey())
        ->where('is_active', true)
        ->whereNotNull('domain')
        ->whereHas('storefrontSetting', fn ($query) => $query->where('is_published', true))
        ->orderBy('name')
        ->get();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description }}">
    @if (isset($previewSlug))
        {{-- Preview URLs (/storefront/{slug}/...) must never compete with the live domain in search results. --}}
        <meta name="robots" content="noindex, nofollow">
    @endif
    @stack('head')
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:type" content="website">
    @if ($shareImageUrl)
        <meta property="og:image" content="{{ $shareImageUrl }}">
    @endif
    <meta name="twitter:card" content="{{ $shareImageUrl ? 'summary_large_image' : 'summary' }}">
    <meta name="theme-color" content="{{ $themePalette['background'] }}" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="{{ $themePalette['dark_background'] }}" media="(prefers-color-scheme: dark)">
    @php
        $publicDisk = app(\App\Services\CompanyStorageService::class)->publicDiskName();
        $storageHost = parse_url(\Illuminate\Support\Facades\Storage::disk($publicDisk)->url(''), PHP_URL_HOST);
    @endphp
    @if ($storageHost && $storageHost !== request()->getHost())
        <link rel="preconnect" href="https://{{ $storageHost }}">
    @endif
    @if ($googleFontsUrl)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $googleFontsUrl }}">
    @endif
    <title>{{ $title }}</title>
    <script>
        (function () {
            var stored = null;
            try { stored = localStorage.getItem('storefrontTheme'); } catch (e) {}
            var mode = stored ?? '{{ $themeMode }}';
            var dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('storefront.partials.meta-pixel')
    @stack('meta-events')
</head>
<body
    class="storefront-shell bg-white text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100"
    data-storefront-theme="{{ $setting->storefrontTheme() }}"
    data-homepage-template="{{ $setting->homepageTemplate() }}"
    data-typography-scale="{{ $setting->typographyScale() }}"
    data-card-hover="{{ $setting->appearanceCardHover() }}"
    data-motion="{{ $setting->appearance_motion ?: 'standard' }}"
    style="--storefront-brand: {{ $themeColor }};
        --storefront-brand-contrast: {{ $themeForeground }};
        --storefront-secondary: {{ $themePalette['secondary'] }};
        --storefront-secondary-contrast: {{ $themePalette['secondary_contrast'] }};
        --storefront-accent: {{ $themePalette['accent'] }};
        --storefront-accent-contrast: {{ $themePalette['accent_contrast'] }};
        --storefront-background: {{ $themePalette['background'] }};
        --storefront-surface: {{ $themePalette['surface'] }};
        --storefront-text: {{ $themePalette['text'] }};
        --storefront-muted-text: {{ $themePalette['muted_text'] }};
        --storefront-border: {{ $themePalette['border'] }};
        --storefront-dark-background: {{ $themePalette['dark_background'] }};
        --storefront-dark-surface: {{ $themePalette['dark_surface'] }};
        --storefront-dark-text: {{ $themePalette['dark_text'] }};
        --storefront-dark-muted-text: {{ $themePalette['dark_muted_text'] }};
        --storefront-dark-border: {{ $themePalette['dark_border'] }};
        --storefront-heading-font: {{ $setting->headingFontFamily() }};
        --storefront-body-font: {{ $setting->bodyFontFamily() }};
        --storefront-base-size: {{ $setting->typographyBaseSize() }}px;
        --storefront-heading-weight: {{ $setting->typographyHeadingWeight() }};
        --storefront-body-weight: {{ $setting->typographyBodyWeight() }};
        --storefront-line-height: {{ $setting->typographyLineHeight() }};
        --storefront-heading-tracking: {{ $setting->typographyHeadingTracking() }};
        --storefront-body-tracking: {{ $setting->typographyBodyTracking() }};
        --storefront-content-measure: {{ $setting->typographyContentWidth() }};
        --storefront-content-width: {{ $setting->appearanceContainerWidth() }};
        --storefront-page-gutter: {{ $setting->appearancePageGutter() }};
        --storefront-card-shadow: {{ $setting->appearanceCardShadow() }};
        --storefront-card-shadow-dark: {{ $setting->appearanceCardShadow(true) }};
        --storefront-motion-duration: {{ $setting->appearanceMotionDuration() }};
        --radius-sm: {{ $cornerRadii['sm'] }};
        --radius-md: {{ $cornerRadii['md'] }};
        --radius-lg: {{ $cornerRadii['lg'] }};
        --radius-xl: {{ $cornerRadii['xl'] }};
        --radius-2xl: {{ $cornerRadii['2xl'] }};"
>
    <a class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-[var(--storefront-brand)] focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-[var(--storefront-brand-contrast)]" href="#main-content">
        Skip to content
    </a>

    @include(\App\Support\StorefrontThemeRegistry::view($setting->storefrontTheme(), 'partials.layout.header'))

    <main id="main-content" class="pb-16 {{ $setting->storefrontTheme() === \App\Support\StorefrontThemeRegistry::MARKETPLACE_PRO ? 'lg:pb-0' : 'sm:pb-0' }}">
        @if (session('storefront_status'))
            <div class="mx-auto mt-3 w-full max-w-7xl px-4 sm:px-5 lg:px-6">
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-200" role="status" aria-live="polite" aria-atomic="true">
                    {{ session('storefront_status') }}
                </div>
            </div>
        @endif
        @yield('content')
    </main>

    @include(\App\Support\StorefrontThemeRegistry::view($setting->storefrontTheme(), 'partials.layout.footer'))

    @include(\App\Support\StorefrontThemeRegistry::view($setting->storefrontTheme(), 'partials.layout.mobile-nav'))

    @include('storefront.partials.meta-consent')

    <script>
        (function () {
            var toggle = document.querySelector('[data-mobile-menu-toggle]');
            var menu = document.querySelector('[data-mobile-menu]');
            if (! toggle || ! menu) { return; }

            var isOpen = function () {
                return toggle.getAttribute('aria-expanded') === 'true';
            };
            var setOpen = function (open, restoreFocus) {
                menu.hidden = ! open;
                menu.classList.toggle('hidden', ! open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.setAttribute('aria-label', open ? toggle.dataset.closeLabel : toggle.dataset.openLabel);
                toggle.querySelector('[data-mobile-menu-icon-open]').classList.toggle('hidden', open);
                toggle.querySelector('[data-mobile-menu-icon-close]').classList.toggle('hidden', ! open);

                if (open) {
                    window.requestAnimationFrame(function () {
                        var firstItem = menu.querySelector('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');
                        if (firstItem) { firstItem.focus(); }
                    });
                } else if (restoreFocus) {
                    toggle.focus();
                }
            };

            toggle.addEventListener('click', function () {
                setOpen(! isOpen(), false);
            });

            menu.addEventListener('click', function (event) {
                if (event.target.closest('a[href]')) { setOpen(false, false); }
            });

            document.addEventListener('click', function (event) {
                if (isOpen() && ! menu.contains(event.target) && ! toggle.contains(event.target)) {
                    setOpen(false, false);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && isOpen()) {
                    event.preventDefault();
                    setOpen(false, true);
                }
            });

            var desktopQuery = window.matchMedia('(min-width: 1024px)');
            var closeAtDesktop = function (event) {
                if (event.matches && isOpen()) { setOpen(false, false); }
            };
            if (desktopQuery.addEventListener) {
                desktopQuery.addEventListener('change', closeAtDesktop);
            } else {
                desktopQuery.addListener(closeAtDesktop);
            }
        })();

        (function () {
            var toggle = document.querySelector('[data-theme-toggle]');
            if (! toggle) { return; }

            var syncIcons = function () {
                var dark = document.documentElement.classList.contains('dark');
                document.querySelectorAll('[data-theme-icon-light]').forEach(function (el) { el.classList.toggle('hidden', dark); });
                document.querySelectorAll('[data-theme-icon-dark]').forEach(function (el) { el.classList.toggle('hidden', ! dark); });
                document.querySelectorAll('[data-theme-toggle-label]').forEach(function (el) { el.textContent = dark ? 'Light mode' : 'Dark mode'; });
                toggle.setAttribute('aria-label', dark ? toggle.dataset.lightLabel : toggle.dataset.darkLabel);
                toggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
                toggle.title = dark ? toggle.dataset.lightLabel : toggle.dataset.darkLabel;
            };

            toggle.addEventListener('click', function () {
                var dark = ! document.documentElement.classList.contains('dark');
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
                try { localStorage.setItem('storefrontTheme', dark ? 'dark' : 'light'); } catch (e) {}
                syncIcons();
            });

            syncIcons();
        })();

        (function () {
            document.addEventListener('click', function (event) {
                var button = event.target.closest('[data-qty-decrement], [data-qty-increment]');
                if (! button) { return; }

                var wrapper = button.closest('[data-qty-stepper]');
                var input = wrapper && wrapper.querySelector('[data-qty-input]');
                if (! input) { return; }

                var step = button.hasAttribute('data-qty-decrement') ? -1 : 1;
                var min = parseInt(input.min || '0', 10);
                var max = input.max ? parseInt(input.max, 10) : Infinity;
                var next = (parseInt(input.value || '0', 10) || 0) + step;

                input.value = Math.min(max, Math.max(min, next));
            });
        })();

        (function () {
            document.addEventListener('submit', function (event) {
                var message = event.target.getAttribute && event.target.getAttribute('data-confirm');
                if (message && ! window.confirm(message)) {
                    event.preventDefault();
                }
            });
        })();
    </script>
</body>
</html>
