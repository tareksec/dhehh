<header class="storefront-header sticky top-0 z-40 border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
    <div class="mx-auto flex min-h-[60px] w-full max-w-7xl items-center justify-between gap-3 px-4 sm:px-5 lg:px-6">
        <a class="flex min-h-10 min-w-0 items-center gap-2.5" href="{{ $homeUrl }}">
            @if ($brandLogoUrl)
                <img class="h-9 w-auto max-w-[200px] shrink-0 object-contain sm:max-w-[240px] {{ $brandLogoDarkUrl ? 'dark:hidden' : '' }}" src="{{ $brandLogoUrl }}" alt="{{ $brandName }} logo" width="800" height="281">
                @if ($brandLogoDarkUrl)
                    <img class="hidden h-9 w-auto max-w-[200px] shrink-0 object-contain sm:max-w-[240px] dark:block" src="{{ $brandLogoDarkUrl }}" alt="{{ $brandName }} logo" width="800" height="281">
                @endif
                <span class="sr-only">{{ $brandName }}</span>
            @else
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-[var(--storefront-brand)] text-sm font-semibold text-white">
                    {{ mb_substr($brandName, 0, 1) }}
                </span>
                <span class="min-w-0 truncate text-base font-bold">{{ $brandName }}</span>
            @endif
        </a>

        <form class="mx-3 hidden min-w-0 max-w-3xl flex-1 md:flex lg:mx-6" role="search" method="GET" action="{{ $productsUrl }}">
            <label class="sr-only" for="storefront-search-desktop">Search products</label>
            <div class="relative w-full">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.34-4.34M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/></svg>
                <input
                    id="storefront-search-desktop"
                    type="search"
                    name="q"
                    value="{{ $search ?? request('q') }}"
                    placeholder="Search products…"
                    autocomplete="off"
                    enterkeyhint="search"
                    class="h-10 w-full rounded-lg border border-gray-300 bg-gray-50 py-2 pl-9 pr-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:bg-white focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:focus:bg-gray-950"
                >
            </div>
        </form>

        <div class="flex shrink-0 items-center gap-2">
            <button
                type="button"
                data-mobile-menu-toggle
                data-open-label="Open menu"
                data-close-label="Close menu"
                class="grid h-10 w-10 place-items-center rounded-md border border-gray-300 text-gray-600 transition hover:border-gray-400 hover:bg-gray-100 hover:text-gray-950 lg:hidden dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white"
                aria-label="Open menu"
                aria-expanded="false"
                aria-controls="storefront-mobile-menu"
            >
                <svg data-mobile-menu-icon-open class="h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/></svg>
                <svg data-mobile-menu-icon-close class="hidden h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
            @if ($setting->phone_number)
                <a class="hidden h-10 w-10 items-center justify-center rounded-md border border-gray-300 text-gray-600 transition hover:border-gray-400 hover:text-gray-950 lg:inline-flex dark:border-gray-700 dark:text-gray-300 dark:hover:text-white" href="tel:{{ preg_replace('/\s+/', '', $setting->phone_number) }}" title="Call support" aria-label="Call support">
                    <svg class="h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h1.5a2.25 2.25 0 0 0 2.25-2.25v-1.372a1 1 0 0 0-.804-.98l-4.204-.841a1 1 0 0 0-1.028.417l-.92 1.38a1 1 0 0 1-1.21.38 12.035 12.035 0 0 1-5.512-5.512 1 1 0 0 1 .38-1.21l1.38-.92a1 1 0 0 0 .417-1.028l-.84-4.204a1 1 0 0 0-.98-.804H4.5a2.25 2.25 0 0 0-2.25 2.25v.75Z"/></svg>
                </a>
            @endif
            @if ($setting->whatsapp_number)
                <a class="hidden h-10 items-center rounded-md bg-[var(--storefront-brand)] px-4 text-sm font-semibold text-white transition hover:opacity-90 lg:inline-flex" href="https://wa.me/{{ preg_replace('/\D+/', '', $setting->whatsapp_number) }}" target="_blank" rel="noopener">
                    WhatsApp
                </a>
            @endif

            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button
                    type="button"
                    @click="open = ! open"
                    :aria-expanded="open.toString()"
                    aria-controls="storefront-account-menu"
                    class="flex h-10 min-w-10 items-center justify-center gap-1 rounded-md border border-gray-300 px-2 text-gray-600 transition hover:border-gray-400 hover:bg-gray-100 hover:text-gray-950 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white"
                    aria-label="{{ $authCustomer ? 'Account menu' : 'Account' }}"
                >
                    @if ($authCustomer)
                        <span class="grid h-6 w-6 place-items-center rounded-full bg-[var(--storefront-brand)] text-[11px] font-semibold text-white" aria-hidden="true">
                            {{ mb_strtoupper(mb_substr($authCustomer->name, 0, 1)) }}
                        </span>
                    @else
                        <svg class="h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                    @endif
                    <svg class="hidden h-3.5 w-3.5 transition sm:block" :class="open ? 'rotate-180' : ''" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                </button>
                <div class="absolute right-0 top-full z-30 w-72 max-w-[calc(100vw-2rem)] pt-1.5" id="storefront-account-menu" x-show="open" x-cloak x-transition>
                    <div class="rounded-lg border border-gray-200 bg-white p-1.5 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="menu">
                        @if (! $accountsEnabled)
                            <a class="block rounded-lg px-3 py-2 text-sm text-gray-700 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white" href="{{ $trackUrl }}" @click="open = false">Track an order</a>
                        @elseif ($authCustomer)
                            <div class="flex items-center gap-2.5 border-b border-gray-100 px-2 py-2 dark:border-gray-800">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--storefront-brand)] text-sm font-semibold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr($authCustomer->name, 0, 1)) }}</span>
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $authCustomer->name }}</div>
                                    <div class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $authCustomer->phone }}</div>
                                </div>
                            </div>
                            <div class="px-2.5 pb-0.5 pt-1.5 text-[10px] font-semibold uppercase tracking-wider text-gray-400">My account</div>
                            <a class="flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 text-sm font-medium transition {{ request()->routeIs('storefront.account.index', 'storefront.preview.account.index') ? 'bg-gray-100 text-gray-950 dark:bg-gray-800 dark:text-white' : 'text-gray-700 hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white' }}" href="{{ $accountUrl }}" role="menuitem" @click="open = false">
                                @include('storefront.account.partials.nav-icon', ['icon' => 'home'])
                                Account overview
                            </a>
                            <a class="flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 text-sm font-medium transition {{ request()->routeIs('storefront.account.profile', 'storefront.preview.account.profile') ? 'bg-gray-100 text-gray-950 dark:bg-gray-800 dark:text-white' : 'text-gray-700 hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white' }}" href="{{ $profileUrl }}" role="menuitem" @click="open = false">
                                @include('storefront.account.partials.nav-icon', ['icon' => 'user'])
                                Profile settings
                            </a>
                            <a class="flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 text-sm font-medium transition {{ request()->routeIs('storefront.account.orders', 'storefront.preview.account.orders') ? 'bg-gray-100 text-gray-950 dark:bg-gray-800 dark:text-white' : 'text-gray-700 hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white' }}" href="{{ $accountOrdersUrl }}" role="menuitem" @click="open = false">
                                @include('storefront.account.partials.nav-icon', ['icon' => 'bag'])
                                My orders
                            </a>
                            <a class="flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 text-sm font-medium transition {{ request()->routeIs('storefront.account.activity', 'storefront.preview.account.activity') ? 'bg-gray-100 text-gray-950 dark:bg-gray-800 dark:text-white' : 'text-gray-700 hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white' }}" href="{{ $activityUrl }}" role="menuitem" @click="open = false">
                                @include('storefront.account.partials.nav-icon', ['icon' => 'activity'])
                                Account activity
                            </a>
                            <div class="my-0.5 border-t border-gray-100 dark:border-gray-800"></div>
                            <a class="flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 text-sm text-gray-700 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white" href="{{ $trackUrl }}" role="menuitem" @click="open = false">
                                <svg class="h-5 w-5 shrink-0" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 3-6.708M3 4.5v5.25h5.25M12 7.5V12l3 1.5"/></svg>
                                Track an order
                            </a>
                            @if ($setting->reseller_program_enabled ?? true)
                                <a class="flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 text-sm text-gray-700 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white" href="{{ $resellerUrl }}" role="menuitem" @click="open = false">
                                    <svg class="h-5 w-5 shrink-0" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372A9.337 9.337 0 0 0 21 18.872M15 19.128v-.003c0-1.113-.285-2.16-.786-3.071M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766v-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
                                    {{ in_array($authCustomer->reseller_status, [null, 'none'], true) ? 'Become a reseller' : 'Reseller status' }}
                                </a>
                            @endif
                            <form class="border-t border-gray-100 pt-0.5 dark:border-gray-800" method="POST" action="{{ $logoutUrl }}">
                                @csrf
                                <button class="flex min-h-10 w-full items-center gap-2.5 rounded-lg px-2.5 text-left text-sm font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30" type="submit" role="menuitem">
                                    <svg class="h-5 w-5 shrink-0" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12"/></svg>
                                    Log out
                                </button>
                            </form>
                        @else
                            <a class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-900 transition hover:bg-gray-100 dark:text-white dark:hover:bg-white/10" href="{{ $loginUrl }}" @click="open = false">Log in</a>
                            <a class="block rounded-lg px-3 py-2 text-sm text-gray-700 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white" href="{{ $registerUrl }}" @click="open = false">Create account</a>
                            <a class="block rounded-lg px-3 py-2 text-sm text-gray-700 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white" href="{{ $trackUrl }}" @click="open = false">Track an order</a>
                        @endif
                        <div class="my-0.5 border-t border-gray-100 dark:border-white/10"></div>
                        <button
                            type="button"
                            data-theme-toggle
                            data-light-label="Switch to light mode"
                            data-dark-label="Switch to dark mode"
                            class="flex min-h-10 w-full items-center gap-2.5 rounded-lg px-2.5 text-left text-sm text-gray-700 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white"
                            aria-label="Switch to dark mode"
                            aria-pressed="false"
                        >
                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-gray-100 dark:bg-white/10">
                                <svg data-theme-icon-light xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.36 6.36-1.42-1.42M7.05 7.05 5.64 5.64m12.72 0-1.42 1.42M7.05 16.95l-1.42 1.42M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z"/></svg>
                                <svg data-theme-icon-dark class="hidden h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/></svg>
                            </span>
                            <span data-theme-toggle-label>Dark mode</span>
                        </button>
                    </div>
                </div>
            </div>

            <a class="relative hidden h-10 items-center whitespace-nowrap rounded-md border border-gray-300 px-4 text-sm font-semibold text-gray-900 transition hover:border-[var(--storefront-brand)] sm:inline-flex dark:border-gray-700 dark:text-white" href="{{ $cartUrl }}">
                Cart
                @if ($cartCount > 0)
                    <span class="ml-2 grid h-5 min-w-5 place-items-center rounded-full bg-[var(--storefront-brand)] px-1 text-xs font-semibold text-white">{{ $cartCount }}</span>
                @endif
            </a>
        </div>
    </div>

    <div class="hidden border-t border-gray-200 bg-gray-50 lg:block dark:border-gray-800 dark:bg-gray-900">
        <nav class="mx-auto flex min-h-10 w-full max-w-7xl flex-wrap items-center gap-x-6 gap-y-1 px-5 text-sm font-semibold text-gray-700 lg:px-6 dark:text-gray-200" aria-label="Storefront navigation">
            @if ($headerMenu->isNotEmpty())
                @foreach ($headerMenu as $menuItem)
                    <a
                        class="inline-flex min-h-10 items-center border-b-2 transition hover:border-[var(--storefront-brand)] hover:text-gray-950 dark:hover:text-white {{ $isCurrentUrl($menuItem['url']) ? 'border-[var(--storefront-brand)] text-gray-950 dark:text-white' : 'border-transparent' }}"
                        href="{{ $menuItem['url'] }}"
                        @if ($isCurrentUrl($menuItem['url'])) aria-current="page" @endif
                        @if ($menuItem['new_tab']) target="_blank" rel="noopener" @endif
                    >
                        @if ($menuItem['icon'] ?? null)
                            <span class="mr-1.5 grid h-4 w-4 place-items-center text-[var(--storefront-brand)]">
                                @include('storefront.partials.category-icon', ['icon' => $menuItem['icon'], 'iconClass' => 'h-4 w-4'])
                            </span>
                        @endif
                        {{ $menuItem['label'] }}
                    </a>
                @endforeach
            @else
                <a class="inline-flex min-h-10 items-center border-b-2 transition hover:border-[var(--storefront-brand)] hover:text-gray-950 dark:hover:text-white {{ $isCatalogCurrent ? 'border-[var(--storefront-brand)] text-gray-950 dark:text-white' : 'border-transparent' }}" href="{{ $productsUrl }}" @if ($isCatalogCurrent) aria-current="page" @endif>Shop all</a>
            @endif

            @if ($navCategories->isNotEmpty())
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button
                        type="button"
                        @click="open = ! open"
                        :aria-expanded="open.toString()"
                        class="flex min-h-10 items-center gap-1 border-b-2 transition"
                        :class="open ? 'border-[var(--storefront-brand)] text-gray-950 dark:text-white' : 'border-transparent'"
                    >
                        Categories
                        <svg class="h-3.5 w-3.5 transition" :class="open ? 'rotate-180' : ''" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="absolute left-0 top-full z-30 w-[min(90vw,640px)] pt-2" x-show="open" x-cloak x-transition>
                        <div class="grid grid-cols-2 gap-1 rounded-xl border border-gray-200 bg-white p-4 shadow-lg sm:grid-cols-3 dark:border-white/10 dark:bg-gray-950">
                            @foreach ($navCategories as $navCategory)
                                <a class="rounded-lg px-3 py-2 text-sm text-gray-700 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white" href="{{ $categoryUrl($navCategory) }}" @if ($isCurrentUrl($categoryUrl($navCategory))) aria-current="page" @endif @click="open = false">
                                    {{ $navCategory->name }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            @if ($headerMenu->isEmpty())
                <a class="inline-flex min-h-10 items-center border-b-2 transition hover:border-[var(--storefront-brand)] hover:text-gray-950 dark:hover:text-white {{ $isCurrentUrl($trackUrl) ? 'border-[var(--storefront-brand)] text-gray-950 dark:text-white' : 'border-transparent' }}" href="{{ $trackUrl }}" @if ($isCurrentUrl($trackUrl)) aria-current="page" @endif>Track order</a>
            @endif
        </nav>
    </div>

    <div class="border-t border-gray-100 px-4 py-2 md:hidden dark:border-gray-800">
        <form class="mx-auto w-full max-w-7xl" role="search" method="GET" action="{{ $productsUrl }}">
            <label class="sr-only" for="storefront-search-mobile">Search products</label>
            <div class="relative">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.34-4.34M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/></svg>
                <input
                    id="storefront-search-mobile"
                    type="search"
                    name="q"
                    value="{{ $search ?? request('q') }}"
                    placeholder="Search products…"
                    autocomplete="off"
                    enterkeyhint="search"
                    class="min-h-11 w-full rounded-full border border-gray-200 bg-gray-50 py-2.5 pl-9 pr-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:bg-white focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-white/5 dark:text-white"
                >
            </div>
        </form>
    </div>

    <div id="storefront-mobile-menu" data-mobile-menu class="hidden border-t border-gray-200 bg-white lg:hidden dark:border-white/10 dark:bg-gray-950" hidden>
        <nav class="mx-auto flex w-full max-w-7xl flex-col gap-1 px-4 py-3" aria-label="Mobile menu">
            @if ($headerMenu->isNotEmpty())
                @foreach ($headerMenu as $menuItem)
                    <a class="flex items-center gap-1.5 rounded-lg px-3 py-3 text-sm font-medium text-gray-700 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white" href="{{ $menuItem['url'] }}" @if ($isCurrentUrl($menuItem['url'])) aria-current="page" @endif @if ($menuItem['new_tab']) target="_blank" rel="noopener" @endif>
                        @if ($menuItem['icon'] ?? null)
                            <span class="grid h-4 w-4 place-items-center text-[var(--storefront-brand)]">
                                @include('storefront.partials.category-icon', ['icon' => $menuItem['icon'], 'iconClass' => 'h-4 w-4'])
                            </span>
                        @endif
                        {{ $menuItem['label'] }}
                    </a>
                @endforeach
            @else
                <a class="rounded-lg px-3 py-3 text-sm font-medium text-gray-700 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white" href="{{ $productsUrl }}" @if ($isCatalogCurrent) aria-current="page" @endif>Shop all</a>
                <a class="rounded-lg px-3 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white" href="{{ $trackUrl }}" @if ($isCurrentUrl($trackUrl)) aria-current="page" @endif>Track order</a>
                @if ($accountsEnabled)
                    <a class="rounded-lg px-3 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white" href="{{ $mobileAccountUrl }}" @if ($isAccountCurrent) aria-current="page" @endif>
                        {{ $authCustomer ? 'My account' : 'Log in / Register' }}
                    </a>
                @endif
            @endif
            @if ($navCategories->isNotEmpty())
                <div class="mt-2 border-t border-gray-100 pt-2 dark:border-white/10">
                    <div class="px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Categories</div>
                    <div class="grid grid-cols-2 gap-1">
                        @foreach ($navCategories as $navCategory)
                            <a class="rounded-lg px-3 py-2 text-sm text-gray-600 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white" href="{{ $categoryUrl($navCategory) }}" @if ($isCurrentUrl($categoryUrl($navCategory))) aria-current="page" @endif>{{ $navCategory->name }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </nav>
    </div>
</header>
