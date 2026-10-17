@php
    $productUrl = \App\Support\StorefrontUrl::route('products.show', [$product->slug], $previewSlug ?? null);
    $cartAddUrl = \App\Support\StorefrontUrl::route('cart.add', [$product->slug], $previewSlug ?? null);
    $comparePrice = (float) ($product->price ?? 0);
    $sellingPrice = (float) $product->selling_price;
    $discountPercent = $comparePrice > 0 && $sellingPrice > 0 && $sellingPrice < $comparePrice
        ? (int) round((1 - ($sellingPrice / $comparePrice)) * 100)
        : 0;
    $outOfStock = $product->stock < 1;
    $canQuickAdd = ! $outOfStock || $product->is_preorder;
@endphp

<article class="mp-card group">
    <div class="mp-card-media">
        <a href="{{ $productUrl }}" class="block" tabindex="-1" aria-hidden="true">
            @if ($product->image)
                <img class="aspect-square w-full object-cover transition duration-300 group-hover:scale-105" src="{{ \App\Support\CompanyMedia::publicUrl($product->image, $company) }}" alt="" width="800" height="800" loading="lazy" decoding="async">
            @else
                <span class="grid aspect-square w-full place-items-center text-5xl font-semibold text-[var(--storefront-brand)]">{{ mb_substr($product->name, 0, 1) }}</span>
            @endif
        </a>

        {{-- At most two badges: the discount and the stock state. --}}
        <div class="pointer-events-none absolute left-2.5 top-2.5 flex flex-col items-start gap-1.5">
            @if ($discountPercent > 0)
                <span class="mp-badge mp-badge-deal">-{{ $discountPercent }}%</span>
            @endif
            @if ($outOfStock)
                <span class="mp-badge {{ $product->is_preorder ? 'mp-badge-accent' : 'mp-badge-muted' }}">{{ $product->is_preorder ? __('Pre-order') : __('Out of stock') }}</span>
            @endif
        </div>

        @if ($product->has_variants)
            <a class="mp-card-add" href="{{ $productUrl }}" title="{{ __('Choose options') }}" aria-label="{{ __('Choose options for :name', ['name' => $product->name]) }}" data-choose-options>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h10m4 0h2M4 17h2m4 0h10M14 4v6M6 14v6"/></svg>
            </a>
        @else
            <form class="mp-card-add-form" method="POST" action="{{ $cartAddUrl }}" data-quick-add-form>
                @csrf
                <input type="hidden" name="quantity" value="{{ $product->effectiveMoq() }}">
                <button
                    class="mp-card-add"
                    type="submit"
                    title="{{ __('Add to cart') }}"
                    aria-label="{{ __('Add :name to cart', ['name' => $product->name]) }}"
                    data-pending-label="{{ __('Adding :name to cart', ['name' => $product->name]) }}"
                    @disabled(! $canQuickAdd)
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                </button>
            </form>
        @endif
    </div>

    <div class="flex flex-1 flex-col gap-1 p-3 sm:p-3.5">
        @if ($product->category)
            <span class="truncate text-[11px] font-semibold uppercase tracking-wide mp-muted">{{ $product->category->name }}</span>
        @endif
        <a class="mp-card-title" href="{{ $productUrl }}">
            <h3>{{ $product->name }}</h3>
        </a>
        <div class="mt-auto pt-1.5">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="mp-price">{{ \App\Support\MoneyFormatter::currency($sellingPrice) }}</span>
                @if ($discountPercent > 0)
                    <span class="text-xs mp-muted line-through">{{ \App\Support\MoneyFormatter::currency($comparePrice) }}</span>
                @endif
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] font-semibold">
                @if ($product->stock > 0 && $product->stock <= 5)
                    <span class="text-[var(--mp-deal)]">{{ __('Only :count left', ['count' => (int) $product->stock]) }}</span>
                @endif
                @if ($product->effectiveMoq() > 1)
                    <span class="rounded-full bg-[color-mix(in_srgb,var(--storefront-secondary)_8%,transparent)] px-2 py-0.5 mp-muted">{{ __('MOQ :count :unit', ['count' => $product->effectiveMoq(), 'unit' => $product->unit ?: __('pcs')]) }}</span>
                @endif
            </div>
        </div>
    </div>
</article>

@if (! $product->has_variants)
    @include('storefront.partials.quick-add-script')
@endif
