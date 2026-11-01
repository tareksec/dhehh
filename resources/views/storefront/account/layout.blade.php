@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@php
    $accountRoute = static fn (string $name) => isset($previewSlug)
        ? route('storefront.preview.account.'.$name, $previewSlug)
        : route('storefront.account.'.$name);
    $accountLinks = [
        ['url' => $accountRoute('index'), 'active' => ['storefront.account.index', 'storefront.preview.account.index'], 'label' => 'Overview', 'icon' => 'home'],
        ['url' => $accountRoute('profile'), 'active' => ['storefront.account.profile', 'storefront.preview.account.profile'], 'label' => 'Profile', 'icon' => 'user'],
        ['url' => $accountRoute('orders'), 'active' => ['storefront.account.orders', 'storefront.preview.account.orders'], 'label' => 'Orders', 'icon' => 'bag'],
        ['url' => $accountRoute('activity'), 'active' => ['storefront.account.activity', 'storefront.preview.account.activity'], 'label' => 'Activity', 'icon' => 'activity'],
    ];

    if ($customer->isApprovedReseller()) {
        // The reseller dashboard lives in the portal (preview has none, so it keeps the old page).
        $resellerPortalHost = isset($previewSlug) ? null : $company->resellerPortalHost();
        $accountLinks[] = ['url' => $resellerPortalHost ? request()->getScheme().'://'.$resellerPortalHost.'/portal' : $accountRoute('reseller'), 'active' => ['storefront.account.reseller', 'storefront.preview.account.reseller'], 'label' => 'Your Store', 'icon' => 'store'];
    }
@endphp

@php
    $customerInitial = mb_strtoupper(mb_substr($customer->name, 0, 1));
@endphp

@section('content')
    <section class="mx-auto w-full max-w-7xl px-4 py-4 sm:px-5 lg:px-6">
        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center gap-3">
                <div class="grid h-12 w-12 shrink-0 place-items-center rounded-lg bg-[var(--storefront-brand)] text-base font-bold text-white" aria-hidden="true">
                    {{ $customerInitial }}
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wider text-[var(--storefront-brand)]">{{ __('Customer account') }}</p>
                    <h1 class="mt-1 truncate text-2xl font-semibold tracking-tight text-gray-950 dark:text-white sm:text-3xl">{{ $customer->name }}</h1>
                    <p class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">{{ $customer->phone }} &middot; Member since {{ $customer->created_at->format('M Y') }}</p>
                </div>
            </div>
        </div>

        <nav class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-gray-900 lg:hidden" aria-label="Customer account">
            <div class="flex min-w-max gap-1">
                @foreach ($accountLinks as $link)
                    @php $isActive = request()->routeIs(...$link['active']); @endphp
                    <a class="inline-flex min-h-10 items-center gap-2 rounded-lg px-3 text-sm font-medium transition {{ $isActive ? 'bg-[var(--storefront-brand)] text-white' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white' }}" href="{{ $link['url'] }}" @if ($isActive) aria-current="page" @endif>
                        @include('storefront.account.partials.nav-icon', ['icon' => $link['icon']])
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>
        </nav>

        <div class="mt-4 grid items-start gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
            <aside class="sticky top-28 hidden rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-800 dark:bg-gray-900 lg:block">
                <nav class="space-y-0.5" aria-label="Customer account">
                    @foreach ($accountLinks as $link)
                        @php $isActive = request()->routeIs(...$link['active']); @endphp
                        <a class="flex min-h-10 items-center gap-2.5 rounded-lg px-3 text-sm font-medium transition {{ $isActive ? 'bg-[var(--storefront-brand)] text-white' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white' }}" href="{{ $link['url'] }}" @if ($isActive) aria-current="page" @endif>
                            @include('storefront.account.partials.nav-icon', ['icon' => $link['icon']])
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </nav>
                <div class="my-2 border-t border-gray-200 dark:border-gray-800"></div>
                <form method="POST" action="{{ $accountRoute('logout') }}">
                    @csrf
                    <button class="flex min-h-10 w-full items-center gap-2.5 rounded-lg px-3 text-left text-sm font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30" type="submit">
                        <svg class="h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12"/></svg>
                        Log out
                    </button>
                </form>
            </aside>

            <main class="min-w-0">
                @yield('account-content')
            </main>
        </div>
    </section>
@endsection
