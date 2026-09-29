@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@push('meta-events')
    @php
        $metaTracking = app(\App\Services\StorefrontMetaTrackingService::class);
    @endphp
    @if (! isset($previewSlug) && $metaTracking->browserEventEnabled($setting, 'ViewCart', request()))
        <script>
            fbq('track', 'ViewCart', {{ Illuminate\Support\Js::from([
                'content_ids' => collect($items)->map(fn ($item) => (string) $item['product']->getKey())->unique()->values()->all(),
                'content_type' => 'product',
                'currency' => 'BDT',
                'value' => round((float) $subtotal, 2),
                'num_items' => collect($items)->sum('quantity'),
            ]) }}, {eventID: {{ Illuminate\Support\Js::from($metaTracking->eventId('view-cart')) }}});
        </script>
    @endif
@endpush

@section('content')
    <section class="border-b border-gray-200 dark:border-white/10">
        <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-5 lg:px-6">
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Your cart</h1>
            <p class="storefront-page-copy mt-2">
                Review selected products before checkout. Your order will be submitted to {{ $company->name }} for review and confirmation.
            </p>
        </div>
    </section>

    <section class="mx-auto grid w-full max-w-7xl gap-5 px-4 py-6 sm:px-5 lg:grid-cols-[minmax(0,1fr)_340px] lg:px-6">
        <div class="space-y-4">
            @forelse ($items as $item)
                @php
                    $product = $item['product'];
                    $variant = $item['variant'] ?? null;
                    $lineStock = $variant ? (int) $variant->stock : (int) $product->stock;
                    $isPreorderLine = ! $variant && (bool) $product->is_preorder;
                    $lineMax = $isPreorderLine
                        ? max($lineStock, \App\Services\StorefrontCart::PREORDER_STOCK_CEILING)
                        : max(1, $lineStock);
                    $lineMin = min($product->effectiveMoq(), $lineMax);
                    $lineImage = $variant && filled($variant->images) ? collect($variant->images)->first() : $product->image;
                    $updateUrl = \App\Support\StorefrontUrl::route('cart.update', [$product->slug], $previewSlug ?? null);
                    $removeUrl = \App\Support\StorefrontUrl::route('cart.remove', [$product->slug], $previewSlug ?? null);
                    $productUrl = \App\Support\StorefrontUrl::route('products.show', [$product->slug], $previewSlug ?? null);
                @endphp
                <article class="grid grid-cols-[80px_minmax(0,1fr)] gap-3 rounded-lg border border-gray-200 bg-white p-3 sm:grid-cols-[96px_minmax(0,1fr)] sm:gap-4 sm:p-4 dark:border-gray-800 dark:bg-gray-900">
                    <a href="{{ $productUrl }}" class="h-20 w-20 overflow-hidden rounded-lg bg-gray-100 sm:h-24 sm:w-24 dark:bg-white/5">
                        @if ($lineImage)
                            <img class="aspect-square h-full w-full object-cover" src="{{ \App\Support\CompanyMedia::publicUrl($lineImage, $company) }}" alt="{{ $product->name }}" width="192" height="192" loading="lazy" decoding="async">
                        @else
                            <div class="grid aspect-square h-full w-full place-items-center text-3xl font-semibold text-[var(--storefront-brand)]">{{ mb_substr($product->name, 0, 1) }}</div>
                        @endif
                    </a>
                    <div class="min-w-0 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="text-xs font-medium text-gray-400">{{ $product->category?->name ?? 'Product' }}</div>
                            <a href="{{ $productUrl }}" class="mt-1 block text-base font-semibold tracking-tight text-gray-950 dark:text-white">{{ $product->name }}</a>
                            @if ($variant)
                                <div class="mt-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $variant->label() }}</div>
                            @endif
                            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                BDT {{ \App\Support\MoneyFormatter::number($item['unit_price']) }} each
                                &middot;
                                @if ($isPreorderLine && $lineStock < 1)
                                    Available for pre-order
                                @elseif ($isPreorderLine)
                                    {{ $lineStock }} in stock; additional quantity available for pre-order
                                @else
                                    {{ $lineStock }} in stock
                                @endif
                            </div>
                            @if ($product->effectiveMoq() > 1)
                                <div class="mt-1 text-xs font-medium text-gray-400 dark:text-gray-500">Minimum order: {{ $product->effectiveMoq() }} {{ $product->unit ?: 'pcs' }}</div>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <form method="POST" action="{{ $updateUrl }}" class="flex items-center gap-2" data-cart-line-form>
                                @csrf
                                @method('PATCH')
                                @if ($variant)
                                    <input type="hidden" name="variant" value="{{ $variant->getKey() }}">
                                @endif
                                <div class="flex items-center rounded-lg border border-gray-300 dark:border-white/15" data-qty-stepper>
                                    <button type="button" data-qty-decrement class="grid h-11 w-11 place-items-center text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white [touch-action:manipulation]" aria-label="Decrease quantity">&minus;</button>
                                    <label class="sr-only" for="quantity-{{ $product->id }}-{{ $variant?->getKey() ?? 0 }}">Quantity</label>
                                    <input id="quantity-{{ $product->id }}-{{ $variant?->getKey() ?? 0 }}" data-qty-input class="h-11 w-12 border-x border-gray-300 bg-transparent text-center text-sm font-semibold outline-none focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15" type="number" name="quantity" min="{{ $lineMin }}" max="{{ $lineMax }}" value="{{ $item['quantity'] }}" autocomplete="off">
                                    <button type="button" data-qty-increment class="grid h-11 w-11 place-items-center text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white [touch-action:manipulation]" aria-label="Increase quantity">+</button>
                                </div>
                                <button class="inline-flex min-h-11 items-center rounded-lg bg-[var(--storefront-brand)] px-3 text-xs font-medium text-white transition hover:opacity-90 disabled:cursor-wait disabled:opacity-60" type="submit" data-pending-label="Updating&hellip;">Update</button>
                            </form>
                            <form method="POST" action="{{ $removeUrl }}" data-confirm="Remove {{ $product->name }} from your cart?" data-cart-line-form>
                                @csrf
                                @method('DELETE')
                                @if ($variant)
                                    <input type="hidden" name="variant" value="{{ $variant->getKey() }}">
                                @endif
                                <button class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-600 transition hover:border-red-400 hover:text-red-600 disabled:cursor-wait disabled:opacity-60 dark:border-white/15 dark:text-gray-300" type="submit" data-pending-label="Removing&hellip;">Remove</button>
                            </form>
                            <div class="w-full text-right text-base font-semibold text-gray-950 dark:text-white">BDT {{ \App\Support\MoneyFormatter::number($item['unit_price']) }}</div>
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-800 dark:bg-gray-900">
                    <h2 class="text-xl font-semibold">Your cart is empty</h2>
                    <p class="mt-2 text-gray-500 dark:text-gray-400">Add products from the catalog to start a storefront order.</p>
                    <a class="mt-6 inline-flex rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90" href="{{ \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null) }}">Browse products</a>
                </div>
            @endforelse
        </div>

        <aside class="h-fit rounded-lg border border-gray-200 bg-white p-5 lg:sticky lg:top-28 dark:border-gray-800 dark:bg-gray-900">
            <h2 class="text-lg font-semibold">Order summary</h2>
            <div class="mt-5 space-y-3 text-sm text-gray-600 dark:text-gray-300">
                <div class="flex justify-between">
                    <span>Items</span>
                    <span>{{ $items->sum('quantity') }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Subtotal</span>
                    <span>BDT {{ \App\Support\MoneyFormatter::number($subtotal) }}</span>
                </div>
                <div class="flex justify-between text-gray-400">
                    <span>Delivery</span>
                    <span>Calculated at checkout</span>
                </div>
            </div>
            <div class="mt-5 border-t border-gray-200 pt-5 dark:border-white/10">
                <div class="flex justify-between text-lg font-semibold">
                    <span>Total</span>
                    <span>BDT {{ \App\Support\MoneyFormatter::number($subtotal) }}</span>
                </div>
                @if ($items->isNotEmpty())
                    <a class="mt-5 flex w-full justify-center rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90" href="{{ \App\Support\StorefrontUrl::route('checkout.show', [], $previewSlug ?? null) }}">
                        Continue to checkout
                    </a>
                @else
                    <button class="mt-5 w-full cursor-not-allowed rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white opacity-60" type="button" disabled>
                        Add products first
                    </button>
                @endif
            </div>
        </aside>
    </section>

    <script>
        document.addEventListener('submit', function (event) {
            var form = event.target.closest && event.target.closest('[data-cart-line-form]');
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

                form.setAttribute('aria-busy', 'true');
                form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    button.textContent = button.getAttribute('data-pending-label') || 'Working…';
                });
            }, 0);
        });
    </script>
@endsection
