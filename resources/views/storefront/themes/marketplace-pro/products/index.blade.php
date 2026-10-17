@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@include('storefront.products.partials.listing-meta-events')

@php
    $filters ??= new \App\Support\StorefrontListingFilters;
    $search = $search ?? '';
    $sortOptions = [
        '' => __('Newest'),
        'price_asc' => __('Price: Low to high'),
        'price_desc' => __('Price: High to low'),
    ];
    $homeUrl = \App\Support\StorefrontUrl::home($previewSlug ?? null);
    $baseProductsUrl = \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null);
    $categoryUrl = fn ($chip) => \App\Support\StorefrontUrl::route('categories.show', [$chip->slug], $previewSlug ?? null);
    $currentUrl = $category ? $categoryUrl($category) : $baseProductsUrl;
    // Keep the search term and sort when filters change, and vice versa.
    $clearFiltersUrl = $currentUrl.(($query = http_build_query(array_filter(['q' => $search, 'sort' => $sort]))) !== '' ? '?'.$query : '');
    $heading = $search !== '' ? __('Results for “:term”', ['term' => $search]) : ($category?->name ?? __('All products'));
    $priceValue = fn (?float $price) => $price === null ? '' : rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');
@endphp

@section('content')
    <div class="mp-shell py-4 sm:py-6" x-data="{ filtersOpen: false }" @keydown.escape.window="filtersOpen = false">
        <nav class="mp-breadcrumb" aria-label="{{ __('Breadcrumb') }}">
            <ol>
                <li><a href="{{ $homeUrl }}">{{ __('Home') }}</a></li>
                @if ($category || $search !== '')
                    <li><a href="{{ $baseProductsUrl }}">{{ __('All products') }}</a></li>
                    <li aria-current="page">{{ $search !== '' ? __('Search') : $category->name }}</li>
                @else
                    <li aria-current="page">{{ __('All products') }}</li>
                @endif
            </ol>
        </nav>

        <div class="mt-3 flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
            <h1 class="mp-page-title">{{ $heading }}</h1>
            <p class="text-sm mp-muted" aria-live="polite">{{ trans_choice(':count product|:count products', $products->total(), ['count' => $products->total()]) }}</p>
        </div>

        <div class="mt-5 lg:grid lg:grid-cols-[260px_minmax(0,1fr)] lg:items-start lg:gap-6">
            {{-- Filters: a sidebar from lg up, a slide-in panel below it. --}}
            <div class="mp-filter-backdrop lg:hidden" x-cloak x-show="filtersOpen" x-transition.opacity @click="filtersOpen = false"></div>
            <aside class="mp-filter-panel" :data-open="filtersOpen" aria-labelledby="mp-filter-title">
                <div class="flex items-center justify-between gap-3 lg:hidden">
                    <h2 id="mp-filter-title" class="text-base font-bold">{{ __('Filters') }}</h2>
                    <button class="mp-icon-ghost" type="button" @click="filtersOpen = false" aria-label="{{ __('Close') }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>

                @if ($categories->isNotEmpty())
                    <section class="mp-filter-group">
                        <h3>{{ __('Categories') }}</h3>
                        <ul class="mp-filter-categories">
                            <li><a href="{{ $baseProductsUrl }}" @if (! $category) aria-current="page" @endif>{{ __('All products') }}</a></li>
                            @foreach ($categories as $chip)
                                <li><a href="{{ $categoryUrl($chip) }}" @if ($category?->id === $chip->id) aria-current="page" @endif>{{ $chip->name }}</a></li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <form class="mp-filter-group" method="GET" action="{{ $currentUrl }}">
                    @if ($search !== '')
                        <input type="hidden" name="q" value="{{ $search }}">
                    @endif
                    @if ($sort !== '')
                        <input type="hidden" name="sort" value="{{ $sort }}">
                    @endif

                    <h3>{{ __('Availability') }}</h3>
                    <label class="mt-2 flex min-h-11 cursor-pointer items-center gap-2.5 text-sm">
                        <input class="mp-checkbox" type="checkbox" name="in_stock" value="1" @checked($filters->inStock)>
                        {{ __('In stock only') }}
                    </label>

                    <h3 class="mt-4">{{ __('Price') }}</h3>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <label class="block text-xs mp-muted">
                            {{ __('Min') }}
                            <input class="mp-input mt-1" type="number" name="min_price" min="0" step="any" inputmode="decimal" value="{{ $priceValue($filters->minPrice) }}">
                        </label>
                        <label class="block text-xs mp-muted">
                            {{ __('Max') }}
                            <input class="mp-input mt-1" type="number" name="max_price" min="0" step="any" inputmode="decimal" value="{{ $priceValue($filters->maxPrice) }}">
                        </label>
                    </div>

                    <div class="mt-4 flex items-center gap-3">
                        <button class="mp-button mp-button-accent flex-1" type="submit">{{ __('Apply filters') }}</button>
                        @if ($filters->active())
                            <a class="text-sm font-semibold text-[var(--storefront-brand)]" href="{{ $clearFiltersUrl }}">{{ __('Clear all') }}</a>
                        @endif
                    </div>
                </form>
            </aside>

            <div class="min-w-0">
                <div class="mp-listing-toolbar">
                    <button class="mp-button mp-button-outline mp-filter-toggle" type="button" @click="filtersOpen = true" :aria-expanded="filtersOpen">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M7 12h10M10 18h4"/></svg>
                        {{ __('Filters') }}
                        @if ($filters->active())
                            <span class="mp-dot" aria-hidden="true"></span>
                        @endif
                    </button>
                    <form class="flex min-w-0 flex-1 items-center justify-end gap-2" method="GET" action="{{ $currentUrl }}">
                        @foreach (array_filter(['q' => $search, 'in_stock' => $filters->inStock ? '1' : null, 'min_price' => $priceValue($filters->minPrice), 'max_price' => $priceValue($filters->maxPrice)], fn ($value) => $value !== null && $value !== '') as $name => $value)
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach
                        <label class="hidden whitespace-nowrap text-sm font-medium mp-muted sm:block" for="mp-sort">{{ __('Sort by') }}</label>
                        <select id="mp-sort" name="sort" class="mp-input mp-sort-select" onchange="this.form.submit()" aria-label="{{ __('Sort by') }}">
                            @foreach ($sortOptions as $value => $label)
                                <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <noscript><button class="mp-button mp-button-outline" type="submit">{{ __('Sort') }}</button></noscript>
                    </form>
                </div>

                @if ($products->isEmpty())
                    <div class="mp-empty">
                        <p class="text-base font-semibold">
                            {{ $search !== '' ? __('No products match “:term”.', ['term' => $search]) : __('No products found.') }}
                        </p>
                        <p class="mt-1 text-sm mp-muted">{{ __('Try another search, remove a filter or browse a category.') }}</p>
                        <div class="mt-4 flex flex-wrap justify-center gap-2">
                            @if ($filters->active())
                                <a class="mp-button mp-button-accent" href="{{ $clearFiltersUrl }}">{{ __('Clear all') }}</a>
                            @endif
                            @foreach ($categories->take(6) as $chip)
                                <a class="mp-chip" href="{{ $categoryUrl($chip) }}">{{ $chip->name }}</a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="marketplace-product-grid mp-listing-grid">
                        @foreach ($products as $product)
                            @include('storefront.themes.marketplace-pro.partials.product-card', ['product' => $product])
                        @endforeach
                    </div>
                @endif

                <div class="mt-8">
                    {{ $products->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
