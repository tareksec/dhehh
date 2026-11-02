<nav class="storefront-mobile-nav fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 border-t border-gray-200 bg-white/95 backdrop-blur sm:hidden dark:border-white/10 dark:bg-gray-950/95" style="padding-bottom: env(safe-area-inset-bottom);" aria-label="{{ __('Mobile navigation') }}">
    <a class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-medium {{ $isHomeCurrent ? 'text-[var(--storefront-brand)]' : 'text-gray-600 dark:text-gray-300' }}" href="{{ $homeUrl }}" @if ($isHomeCurrent) aria-current="page" @endif>
        <svg class="h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="m3 12 8.954-8.19a1.5 1.5 0 0 1 2.092 0L23 12M5.25 9.75V19.5a1.5 1.5 0 0 0 1.5 1.5H9.75v-6a1.5 1.5 0 0 1 1.5-1.5h1.5a1.5 1.5 0 0 1 1.5 1.5v6h3a1.5 1.5 0 0 0 1.5-1.5V9.75"/></svg>
        {{ __('Home') }}
    </a>
    <a class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-medium {{ $isCatalogCurrent ? 'text-[var(--storefront-brand)]' : 'text-gray-600 dark:text-gray-300' }}" href="{{ $productsUrl }}" @if ($isCatalogCurrent) aria-current="page" @endif>
        <svg class="h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/></svg>
        {{ __('Catalog') }}
    </a>
    <a class="relative flex flex-col items-center gap-1 py-2.5 text-[11px] font-medium {{ $isCartCurrent ? 'text-[var(--storefront-brand)]' : 'text-gray-600 dark:text-gray-300' }}" href="{{ $cartUrl }}" @if ($isCartCurrent) aria-current="page" @endif>
        <svg class="h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.836l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 1.874-4.708 2.25-7.183a1.125 1.125 0 0 0-1.11-1.317H5.25M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/></svg>
        @if ($cartCount > 0)
            <span class="absolute right-4 top-1 grid h-4 min-w-4 place-items-center rounded-full bg-[var(--storefront-brand)] px-1 text-[10px] font-semibold text-white">{{ $cartCount }}</span>
        @endif
        {{ __('Cart') }}
    </a>
    <a class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-medium {{ $isAccountCurrent ? 'text-[var(--storefront-brand)]' : 'text-gray-600 dark:text-gray-300' }}" href="{{ $mobileAccountUrl }}" @if ($isAccountCurrent) aria-current="page" @endif>
        @if ($authCustomer)
            <span class="grid h-5 w-5 place-items-center rounded-full bg-[var(--storefront-brand)] text-[10px] font-semibold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr($authCustomer->name, 0, 1)) }}</span>
        @elseif (! $accountsEnabled)
            <svg class="h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 4.5A6.75 6.75 0 1 0 18 11.25M15.75 3v4.5h4.5M14.25 14.25l4.5 4.5"/></svg>
        @else
            <svg class="h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
        @endif
        {{ $accountsEnabled ? __('Account') : __('Track') }}
    </a>
</nav>
