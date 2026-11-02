@php
    $productUrl = \App\Support\StorefrontUrl::route('products.show', [$product->slug], $previewSlug ?? null);
    $cartAddUrl = \App\Support\StorefrontUrl::route('cart.add', [$product->slug], $previewSlug ?? null);
    $comparePrice = (float) ($product->price ?? 0);
    $sellingPrice = (float) $product->selling_price;
    $discountPercent = $comparePrice > 0 && $sellingPrice > 0 && $sellingPrice < $comparePrice
        ? (int) round((1 - ($sellingPrice / $comparePrice)) * 100)
        : 0;
@endphp

<article class="group flex h-full min-w-0 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white transition hover:border-gray-300 hover:shadow-md dark:border-gray-800 dark:bg-gray-900">
    <div class="relative overflow-hidden bg-gray-100 dark:bg-gray-800">
        <a href="{{ $productUrl }}" class="block">
            @if ($product->image)
                <img class="aspect-square w-full object-cover transition duration-300 group-hover:scale-105" src="{{ \App\Support\CompanyMedia::publicUrl($product->image, $company) }}" alt="{{ $product->name }}" width="800" height="800" loading="lazy" decoding="async">
            @else
                <div class="grid aspect-square w-full place-items-center text-5xl font-semibold text-[var(--storefront-brand)]">
                    {{ mb_substr($product->name, 0, 1) }}
                </div>
            @endif
        </a>
        @if ($discountPercent > 0)
            <span class="absolute left-3 top-3 rounded-full bg-red-600 px-2 py-1 text-[11px] font-semibold text-white">-{{ $discountPercent }}%</span>
        @endif
        @if ($product->stock < 1)
            <div class="absolute inset-x-0 bottom-0 {{ $product->is_preorder ? 'bg-[var(--storefront-brand)]/90' : 'bg-gray-950/80' }} px-3 py-1.5 text-center text-xs font-semibold uppercase tracking-wide text-white">
                {{ $product->is_preorder ? __('Pre-order') : __('Out of stock') }}
            </div>
        @endif

        @if ($product->has_variants)
            <a
                class="absolute bottom-3 right-3 grid h-11 w-11 place-items-center rounded-full bg-white text-gray-900 shadow-md transition hover:bg-[var(--storefront-brand)] hover:text-white dark:bg-gray-900 dark:text-white"
                href="{{ $productUrl }}"
                title="{{ __('Choose options') }}"
                aria-label="Choose options for {{ $product->name }}"
                data-choose-options
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h10m4 0h2M4 17h2m4 0h10M14 4v6M6 14v6"/></svg>
            </a>
        @else
            <form class="absolute bottom-3 right-3 transition focus-within:opacity-100" method="POST" action="{{ $cartAddUrl }}" data-quick-add-form>
                @csrf
                <input type="hidden" name="quantity" value="{{ $product->effectiveMoq() }}">
                <button
                    class="grid h-11 w-11 place-items-center rounded-full bg-white text-gray-900 shadow-md transition hover:bg-[var(--storefront-brand)] hover:text-white disabled:cursor-not-allowed disabled:opacity-50 dark:bg-gray-900 dark:text-white"
                    type="submit"
                    title="{{ __('Quick add to cart') }}"
                    aria-label="Add {{ $product->name }} to cart"
                    data-pending-label="Adding {{ $product->name }} to cart"
                    @disabled($product->stock < 1 && ! $product->is_preorder)
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                </button>
            </form>
        @endif
    </div>
    <div class="flex flex-1 flex-col p-4">
        <div class="text-xs font-medium text-gray-400">{{ $product->category?->name ?? __('Product') }}</div>
        <a href="{{ $productUrl }}">
            <h3 class="mt-1 line-clamp-2 min-h-10 text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ $product->name }}</h3>
        </a>
        <div class="mt-auto flex flex-wrap items-end justify-between gap-x-3 gap-y-1 pt-2">
            <div class="flex items-baseline gap-2">
                <span class="text-base font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::currency($sellingPrice) }}</span>
                @if ($discountPercent > 0)
                    <span class="text-xs text-gray-400 line-through dark:text-gray-500">{{ \App\Support\MoneyFormatter::currency($sellingPrice) }}</span>
                @endif
            </div>
            @if ($product->stock > 0 && $product->stock <= 5)
                <span class="text-xs font-medium text-amber-600 dark:text-amber-400">{{ __(':stock left', ['stock' => (int) $product->stock]) }}</span>
            @endif
        </div>
        @if ($product->effectiveMoq() > 1)
            <div class="mt-2 inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                MOQ {{ $product->effectiveMoq() }} {{ $product->unit ?: 'pcs' }}
            </div>
        @endif
    </div>
</article>

@if (! $product->has_variants)
    @once
        <script>
            document.addEventListener('submit', function (event) {
                var form = event.target.closest && event.target.closest('[data-quick-add-form]');
                if (!form) return;

                if (form.dataset.submitting === 'true') {
                    event.preventDefault();
                    return;
                }

                form.dataset.submitting = 'true';

                window.setTimeout(function () {
                    if (event.defaultPrevented) {
                        delete form.dataset.submitting;
                        return;
                    }

                    var button = form.querySelector('button[type="submit"]');
                    if (!button) return;

                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    button.setAttribute('aria-label', button.getAttribute('data-pending-label') || 'Adding to cart');
                    button.textContent = '…';
                }, 0);
            });
        </script>
    @endonce
@endif
