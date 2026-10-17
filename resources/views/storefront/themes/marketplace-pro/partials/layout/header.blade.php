{{--
    Marketplace Pro header (design handoff: "Storefront - Marketplace Pro.dc.html").
    Rendered inside storefront.layout, so every URL/menu variable prepared
    there ($homeUrl, $productsUrl, $cartUrl, $navCategories, $headerMenu,
    $authCustomer, ...) is available here. Form names, routes and account
    actions are the same as the default header — only the presentation
    differs.
--}}
@php
    $mpAnnouncement = $setting->marketplace_utility_bar_enabled && filled($setting->marketplace_utility_bar_text)
        ? trim((string) $setting->marketplace_utility_bar_text)
        : null;
    $mpHelpline = filled($setting->marketplace_helpline) ? trim((string) $setting->marketplace_helpline) : (filled($setting->phone_number) ? trim((string) $setting->phone_number) : null);
    $mpHelplineHref = $mpHelpline ? 'tel:'.preg_replace('/[^\d+]/', '', $mpHelpline) : null;
    $mpCategoryLinks = $navCategories->map(fn ($category) => ['label' => $category->name, 'url' => $categoryUrl($category), 'icon' => $category->icon]);
    $mpStripLinks = $headerMenu->isNotEmpty() ? $headerMenu : $mpCategoryLinks;
@endphp

@if ($mpAnnouncement || $mpHelpline)
    <div class="mp-utility-bar" data-mp-utility-bar>
        <div class="mp-shell flex min-h-8 items-center justify-between gap-3 text-xs">
            <p class="min-w-0 truncate">{{ $mpAnnouncement }}</p>
            <div class="flex shrink-0 items-center gap-4">
                @if ($mpHelpline)
                    <a class="hidden hover:text-white sm:inline" href="{{ $mpHelplineHref }}">{{ __('Helpline') }}: {{ $mpHelpline }}</a>
                @endif
                <a class="hover:text-white" href="{{ $trackUrl }}">{{ __('Track order') }}</a>
            </div>
        </div>
    </div>
@endif

<header class="storefront-header mp-header sticky top-0 z-40" x-data="{ drawer: false }" @mp-open-categories.window="drawer = true" @keydown.escape.window="drawer = false">
    <div class="mp-shell flex min-h-16 items-center gap-3 py-2.5 lg:gap-6">
        <button type="button" class="mp-icon-button lg:hidden" @click="drawer = true" aria-controls="mp-category-drawer" :aria-expanded="drawer.toString()" aria-label="{{ __('Open categories') }}">
            <svg class="h-6 w-6" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>

        <a class="flex min-w-0 shrink items-center gap-2.5" href="{{ $homeUrl }}">
            @if ($brandLogoDarkUrl || $brandLogoUrl)
                <img class="h-9 w-auto max-w-[160px] object-contain sm:max-w-[200px]" src="{{ $brandLogoDarkUrl ?: $brandLogoUrl }}" alt="{{ $brandName }}" width="800" height="281">
            @else
                <span class="mp-logo-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($brandName, 0, 2)) }}</span>
                <span class="mp-brand-name truncate">{{ $brandName }}</span>
            @endif
        </a>

        <form class="mp-search hidden md:flex" role="search" method="GET" action="{{ $productsUrl }}">
            <label class="sr-only" for="mp-search-desktop">{{ __('Search products') }}</label>
            <input id="mp-search-desktop" type="search" name="q" value="{{ $search ?? request('q') }}" placeholder="{{ __('Search products…') }}" autocomplete="off" enterkeyhint="search">
            <button type="submit" aria-label="{{ __('Search') }}">
                <svg class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="m21 21-4.3-4.3M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/></svg>
            </button>
        </form>

        <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-3">
            @if ($accountsEnabled)
                <div class="relative hidden sm:block" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button type="button" class="mp-header-link" @click="open = ! open" :aria-expanded="open.toString()" aria-controls="mp-account-menu">
                        <span class="block text-[11px] font-normal opacity-75">{{ $authCustomer ? __('Hello, :name', ['name' => \Illuminate\Support\Str::limit($authCustomer->name, 14)]) : __('Hello, sign in') }}</span>
                        <span class="block">{{ __('Account') }}</span>
                    </button>
                    <div id="mp-account-menu" class="absolute right-0 top-full z-50 w-60 pt-2" x-show="open" x-cloak x-transition>
                        <div class="rounded-xl border border-[var(--storefront-border)] bg-white p-1.5 text-sm text-[var(--storefront-text)] shadow-xl dark:border-[var(--storefront-dark-border)] dark:bg-[var(--storefront-dark-surface)] dark:text-[var(--storefront-dark-text)]" role="menu">
                            @if ($authCustomer)
                                <a class="mp-menu-item" href="{{ $accountUrl }}" role="menuitem">{{ __('Account overview') }}</a>
                                <a class="mp-menu-item" href="{{ $accountOrdersUrl }}" role="menuitem">{{ __('My orders') }}</a>
                                <a class="mp-menu-item" href="{{ $profileUrl }}" role="menuitem">{{ __('Profile settings') }}</a>
                                <form method="POST" action="{{ $logoutUrl }}">
                                    @csrf
                                    <button class="mp-menu-item w-full text-left text-red-600 dark:text-red-400" type="submit" role="menuitem">{{ __('Log out') }}</button>
                                </form>
                            @else
                                <a class="mp-menu-item font-semibold" href="{{ $loginUrl }}" role="menuitem">{{ __('Log in') }}</a>
                                <a class="mp-menu-item" href="{{ $registerUrl }}" role="menuitem">{{ __('Create account') }}</a>
                            @endif
                            <a class="mp-menu-item" href="{{ $trackUrl }}" role="menuitem">{{ __('Track an order') }}</a>
                            <button type="button" data-theme-toggle data-light-label="{{ __('Switch to light mode') }}" data-dark-label="{{ __('Switch to dark mode') }}" class="mp-menu-item w-full text-left" aria-pressed="false">
                                <span data-theme-toggle-label>{{ __('Dark mode') }}</span>
                            </button>
                        </div>
                    </div>
                </div>
                <a class="mp-header-link hidden lg:block" href="{{ $accountOrdersUrl }}">
                    <span class="block text-[11px] font-normal opacity-75">{{ __('Returns') }}</span>
                    <span class="block">{{ __('& Orders') }}</span>
                </a>
            @endif

            <a class="mp-cart-link" href="{{ $cartUrl }}" aria-label="{{ __('Cart') }}: {{ $cartCount }}">
                <span class="relative">
                    <svg class="h-7 w-7" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.39c.51 0 .95.34 1.09.84l.38 1.43M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.22c1.12-2.3 1.87-4.7 2.25-7.18a1.13 1.13 0 0 0-1.11-1.32H5.25M7.5 14.25 5.1 5.27M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/></svg>
                    <span class="mp-cart-count" aria-live="polite" data-cart-count>{{ $cartCount }}</span>
                </span>
                <span class="hidden text-sm font-bold sm:inline">{{ __('Cart') }}</span>
            </a>
        </div>
    </div>

    <div class="mp-shell pb-2.5 md:hidden">
        <form class="mp-search flex" role="search" method="GET" action="{{ $productsUrl }}">
            <label class="sr-only" for="mp-search-mobile">{{ __('Search products') }}</label>
            <input id="mp-search-mobile" type="search" name="q" value="{{ $search ?? request('q') }}" placeholder="{{ __('Search products…') }}" autocomplete="off" enterkeyhint="search">
            <button type="submit" aria-label="{{ __('Search') }}">
                <svg class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="m21 21-4.3-4.3M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/></svg>
            </button>
        </form>
    </div>

    <nav class="mp-category-strip hidden lg:block" aria-label="{{ __('Storefront navigation') }}">
        <div class="mp-shell flex min-h-10 items-center gap-5 overflow-x-auto text-sm font-semibold">
            @if ($mpCategoryLinks->isNotEmpty())
                <button type="button" class="flex shrink-0 items-center gap-1.5" @click="drawer = true" aria-controls="mp-category-drawer" :aria-expanded="drawer.toString()">
                    <svg class="h-4 w-4" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
                    {{ __('All categories') }}
                </button>
            @endif
            <a class="shrink-0 {{ $isCatalogCurrent ? 'text-white' : '' }}" href="{{ $productsUrl }}" @if ($isCatalogCurrent) aria-current="page" @endif>{{ __('Shop all') }}</a>
            @foreach ($mpStripLinks as $link)
                <a class="shrink-0 whitespace-nowrap {{ $isCurrentUrl($link['url']) ? 'text-white' : '' }}" href="{{ $link['url'] }}" @if ($isCurrentUrl($link['url'])) aria-current="page" @endif @if ($link['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ $link['label'] }}</a>
            @endforeach
            <a class="shrink-0" href="{{ $offersUrl }}">{{ __('Offers') }}</a>
        </div>
    </nav>

    {{-- Category drawer: opened by the hamburger, "All categories", or the mobile bottom-nav button. --}}
    <div class="fixed inset-0 z-50" x-show="drawer" x-cloak role="dialog" aria-modal="true" aria-labelledby="mp-category-drawer-title" id="mp-category-drawer">
        <div class="absolute inset-0 bg-black/50" @click="drawer = false" x-show="drawer" x-transition.opacity></div>
        <div class="mp-drawer-panel" x-show="drawer" x-transition:enter="transition duration-200" x-transition:enter-start="-translate-x-full" x-transition:leave="transition duration-150" x-transition:leave-end="-translate-x-full" x-effect="if (drawer) $nextTick(() => $el.querySelector('button')?.focus())">
            <div class="flex items-center justify-between bg-[var(--storefront-secondary)] px-4 py-3 text-white">
                <p id="mp-category-drawer-title" class="font-bold">{{ $authCustomer ? __('Hello, :name', ['name' => \Illuminate\Support\Str::limit($authCustomer->name, 18)]) : __('Browse categories') }}</p>
                <button type="button" class="mp-icon-button" @click="drawer = false" aria-label="{{ __('Close') }}">
                    <svg class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <nav class="flex-1 overflow-y-auto p-2" aria-label="{{ __('Categories') }}">
                <a class="mp-menu-item font-semibold" href="{{ $productsUrl }}">{{ __('Shop all') }}</a>
                @foreach ($mpCategoryLinks as $link)
                    <a class="mp-menu-item flex items-center gap-2.5" href="{{ $link['url'] }}" @if ($isCurrentUrl($link['url'])) aria-current="page" @endif>
                        @if ($link['icon'])
                            <span class="grid h-5 w-5 place-items-center text-[var(--storefront-accent)]">@include('storefront.partials.category-icon', ['icon' => $link['icon'], 'iconClass' => 'h-4 w-4'])</span>
                        @endif
                        {{ $link['label'] }}
                    </a>
                @endforeach
                @if ($headerMenu->isNotEmpty())
                    <div class="my-2 border-t border-[var(--storefront-border)] dark:border-[var(--storefront-dark-border)]"></div>
                    @foreach ($headerMenu as $menuItem)
                        <a class="mp-menu-item" href="{{ $menuItem['url'] }}" @if ($menuItem['new_tab']) target="_blank" rel="noopener" @endif>{{ $menuItem['label'] }}</a>
                    @endforeach
                @endif
                <div class="my-2 border-t border-[var(--storefront-border)] dark:border-[var(--storefront-dark-border)]"></div>
                <a class="mp-menu-item" href="{{ $offersUrl }}">{{ __('Offers') }}</a>
                <a class="mp-menu-item" href="{{ $trackUrl }}">{{ __('Track an order') }}</a>
                @if ($mpHelpline)
                    <a class="mp-menu-item" href="{{ $mpHelplineHref }}">{{ __('Helpline') }}: {{ $mpHelpline }}</a>
                @endif
            </nav>
        </div>
    </div>
</header>
