@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@php
    $tabUrl = fn (?string $type) => isset($previewSlug)
        ? route('storefront.preview.offers.index', [$previewSlug, ...($type ? ['type' => $type] : [])])
        : route('storefront.offers.index', $type ? ['type' => $type] : []);
    $offerUrl = fn (string $slug) => \App\Support\StorefrontUrl::route('offers.show', [$slug], $previewSlug ?? null);
@endphp

@section('content')
    <section class="border-b border-gray-200 dark:border-white/10">
        <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-5 lg:px-6">
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Offers') }}</h1>
            <p class="storefront-page-copy mt-2">{{ __('Special single-product and combo deals, priced lower than buying separately.') }}</p>

            <div class="mt-5 inline-flex rounded-full border border-gray-200 bg-gray-50 p-1 text-sm font-medium dark:border-white/10 dark:bg-white/5">
                <a href="{{ $tabUrl(null) }}" class="rounded-full px-4 py-2 transition {{ $activeType === null ? 'bg-[var(--storefront-brand)] text-white' : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white' }}">All offers</a>
                <a href="{{ $tabUrl('single') }}" class="rounded-full px-4 py-2 transition {{ $activeType === 'single' ? 'bg-[var(--storefront-brand)] text-white' : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white' }}">Single offer</a>
                <a href="{{ $tabUrl('combo') }}" class="rounded-full px-4 py-2 transition {{ $activeType === 'combo' ? 'bg-[var(--storefront-brand)] text-white' : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white' }}">Combo offer</a>
            </div>
        </div>
    </section>

    <section class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-5 lg:px-6">
        @if ($offers->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 px-6 py-16 text-center text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">
                No offers are available right now. Please check back soon.
            </div>
        @else
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($offers as $offer)
                    @php
                        $coverUrl = \App\Support\CompanyMedia::publicUrl($offer->cover_image, $company);
                        $finalPrice = $pricing->finalPrice($offer);
                        $subtotal = $pricing->componentsSubtotal($offer);
                    @endphp
                    <a href="{{ $offerUrl($offer->slug) }}" class="group flex h-full min-w-0 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white transition hover:border-gray-300 hover:shadow-md dark:border-gray-800 dark:bg-gray-900">
                        <div class="relative overflow-hidden bg-gray-100 dark:bg-gray-800">
                            @if ($coverUrl)
                                <img class="aspect-square w-full object-cover transition duration-300 group-hover:scale-105" src="{{ $coverUrl }}" alt="{{ $offer->title }}" width="800" height="800" loading="lazy" decoding="async">
                            @else
                                <div class="grid aspect-square w-full place-items-center text-5xl font-semibold text-[var(--storefront-brand)]">{{ mb_substr($offer->title, 0, 1) }}</div>
                            @endif
                            <span class="absolute left-3 top-3 rounded-full bg-[var(--storefront-brand)] px-2 py-1 text-[11px] font-semibold text-white">{{ \App\Models\Offer::TYPES[$offer->type] ?? $offer->type }}</span>
                        </div>
                        <div class="flex flex-1 flex-col p-4">
                            <h3 class="line-clamp-2 min-h-10 text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ $offer->title }}</h3>
                            <div class="mt-auto flex items-baseline gap-2 pt-2">
                                <span class="text-base font-semibold text-gray-950 dark:text-white">BDT {{ \App\Support\MoneyFormatter::number((float) $finalPrice) }}</span>
                                @if ($subtotal > $finalPrice)
                                    <span class="text-xs text-gray-400 line-through dark:text-gray-500">BDT {{ \App\Support\MoneyFormatter::number((float) $subtotal) }}</span>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
