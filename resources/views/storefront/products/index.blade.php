@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@include('storefront.products.partials.listing-meta-events')

@php
    $sortOptions = [
        '' => 'Newest',
        'price_asc' => 'Price: Low to high',
        'price_desc' => 'Price: High to low',
    ];
    $baseProductsUrl = \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null);
@endphp

@section('content')
    <section class="border-b border-gray-200 dark:border-white/10">
        <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-5 lg:px-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-[var(--storefront-brand)]">{{ ($search ?? '') !== '' ? 'Search' : ($category?->name ?? 'Catalog') }}</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">
                        @if (($search ?? '') !== '')
                            Results for &ldquo;{{ $search }}&rdquo;
                        @else
                            {{ $category?->name ?? 'All products' }}
                        @endif
                    </h1>
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $products->total() }} {{ str('product')->plural($products->total()) }}</div>
            </div>

            @if ($categories->isNotEmpty())
                <div class="-mx-4 mt-6 flex snap-x gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0">
                    <a class="inline-flex min-h-11 shrink-0 snap-start items-center rounded-full border px-4 text-sm font-medium transition {{ ! $category ? 'border-[var(--storefront-brand)] bg-[var(--storefront-brand)] text-white' : 'border-gray-200 text-gray-600 hover:border-gray-400 dark:border-white/10 dark:text-gray-300' }}" href="{{ $baseProductsUrl }}" @if (! $category) aria-current="page" @endif>
                        All
                    </a>
                    @foreach ($categories as $chip)
                        @php
                            $chipUrl = \App\Support\StorefrontUrl::route('categories.show', [$chip->slug], $previewSlug ?? null);
                        @endphp
                        <a class="inline-flex min-h-11 shrink-0 snap-start items-center rounded-full border px-4 text-sm font-medium transition {{ $category?->id === $chip->id ? 'border-[var(--storefront-brand)] bg-[var(--storefront-brand)] text-white' : 'border-gray-200 text-gray-600 hover:border-gray-400 dark:border-white/10 dark:text-gray-300' }}" href="{{ $chipUrl }}" @if ($category?->id === $chip->id) aria-current="page" @endif>
                            {{ $chip->name }}
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-5 lg:px-6">
        <form class="mb-6 flex items-center justify-between gap-3 sm:justify-end" method="GET">
            @if (($search ?? '') !== '')
                <input type="hidden" name="q" value="{{ $search }}">
            @endif
            <label class="text-sm font-medium text-gray-600 sm:sr-only dark:text-gray-300" for="sort">{{ __('Sort by') }}</label>
            <select id="sort" name="sort" class="min-h-11 min-w-0 flex-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 sm:flex-none dark:border-white/10 dark:bg-gray-950 dark:text-gray-200" onchange="this.form.submit()">
                @foreach ($sortOptions as $value => $label)
                    <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </form>

        <div class="storefront-catalog-grid grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
            @forelse ($products as $product)
                @include('storefront.partials.product-card', ['product' => $product])
            @empty
                <div class="col-span-full rounded-xl border border-dashed border-gray-300 p-10 text-center text-gray-500 dark:border-white/15">
                    @if (($search ?? '') !== '')
                        No products match &ldquo;{{ $search }}&rdquo;. Try a different search term.
                    @else
                        No products found.
                    @endif
                </div>
            @endforelse
        </div>

        <div class="mt-10">
            {{ $products->links() }}
        </div>
    </section>
@endsection
