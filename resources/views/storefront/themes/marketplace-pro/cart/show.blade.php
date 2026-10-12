@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@include('storefront.cart.partials.cart-meta-events')

@section('content')
    @php
        $money = fn (float $amount) => \App\Support\MoneyFormatter::currency($amount);
        $homeUrl = \App\Support\StorefrontUrl::home($previewSlug ?? null);
        $productsUrl = \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null);
        $checkoutUrl = \App\Support\StorefrontUrl::route('checkout.show', [], $previewSlug ?? null);
        $itemCount = (int) $items->sum('quantity');
    @endphp

    <div class="mp-shell py-4 sm:py-6">
        <nav class="mp-breadcrumb" aria-label="{{ __('Breadcrumb') }}">
            <ol>
                <li><a href="{{ $homeUrl }}">{{ __('Home') }}</a></li>
                <li aria-current="page">{{ __('Cart') }}</li>
            </ol>
        </nav>

        <div class="mt-3 flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
            <h1 class="mp-page-title">{{ __('Shopping cart') }}</h1>
            @if ($items->isNotEmpty())
                <p class="text-sm mp-muted">{{ trans_choice(':count item|:count items', $itemCount, ['count' => $itemCount]) }}</p>
            @endif
        </div>

        @if ($items->isEmpty())
            <div class="mp-empty mt-5">
                <p class="text-base font-semibold">{{ __('Your cart is empty') }}</p>
                <p class="mt-1 text-sm mp-muted">{{ __('Add products to start an order.') }}</p>
                <a class="mp-button mp-button-accent mt-4" href="{{ $productsUrl }}">{{ __('Browse products') }}</a>
            </div>
        @else
            <div class="mt-5 lg:grid lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start lg:gap-6">
                <ul class="mp-cart-lines" aria-label="{{ __('Items in your cart') }}">
                    @foreach ($items as $item)
                        @php
                            $line = \App\Support\StorefrontCartLine::make($item, $company);
                            $product = $line->product;
                            $variant = $line->variant;
                            $unit = $product->unit ?: __('pcs');
                            $updateUrl = \App\Support\StorefrontUrl::route('cart.update', [$product->slug], $previewSlug ?? null);
                            $removeUrl = \App\Support\StorefrontUrl::route('cart.remove', [$product->slug], $previewSlug ?? null);
                            $productUrl = \App\Support\StorefrontUrl::route('products.show', [$product->slug], $previewSlug ?? null);
                            $stockState = match (true) {
                                $line->isPreorder && $line->stock < 1 => ['class' => 'is-preorder', 'label' => __('Available for pre-order')],
                                $line->isPreorder => ['class' => 'is-preorder', 'label' => __('Pre-order')],
                                $line->stock <= 5 => ['class' => 'is-low', 'label' => __('Only :count left', ['count' => $line->stock])],
                                default => ['class' => 'is-in', 'label' => __('In stock')],
                            };
                            $inputId = 'cart-qty-'.$product->getKey().'-'.($variant?->getKey() ?? 0);
                        @endphp
                        <li class="mp-cart-line" data-cart-line>
                            <a href="{{ $productUrl }}" class="mp-cart-thumb" tabindex="-1" aria-hidden="true">
                                @if ($line->imageUrl)
                                    <img src="{{ $line->imageUrl }}" alt="" width="192" height="192" loading="lazy" decoding="async">
                                @else
                                    <span>{{ mb_substr($product->name, 0, 1) }}</span>
                                @endif
                            </a>

                            <div class="min-w-0">
                                @if ($product->category)
                                    <p class="text-xs mp-muted">{{ $product->category->name }}</p>
                                @endif
                                <a href="{{ $productUrl }}" class="mp-cart-name">{{ $product->name }}</a>
                                @if ($variant)
                                    <p class="mt-0.5 text-xs font-semibold mp-muted">{{ $variant->label() }}</p>
                                @endif
                                <p class="mt-1 text-sm">
                                    <span class="font-bold">{{ __(':price each', ['price' => $money($line->unitPrice)]) }}</span>
                                </p>
                                <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                    <span class="mp-stock-pill {{ $stockState['class'] }}">{{ $stockState['label'] }}</span>
                                    @if ($line->moq > 1)
                                        <span class="mp-muted">{{ __('MOQ :count :unit', ['count' => $line->moq, 'unit' => $unit]) }}</span>
                                    @endif
                                </p>
                                @if ($line->nextTier)
                                    <p class="mp-cart-tier">{{ __('Add :count more to pay :price each', ['count' => $line->nextTier['add_qty'], 'price' => $money($line->nextTier['price'])]) }}</p>
                                @endif
                            </div>

                            <div class="mp-cart-actions">
                                <form method="POST" action="{{ $updateUrl }}" class="flex items-center gap-2" data-cart-line-form>
                                    @csrf
                                    @method('PATCH')
                                    @if ($variant)
                                        <input type="hidden" name="variant" value="{{ $variant->getKey() }}">
                                    @endif
                                    <div class="mp-qty" data-qty-stepper>
                                        <button type="button" data-qty-decrement aria-label="{{ __('Decrease quantity') }}">&minus;</button>
                                        <label class="sr-only" for="{{ $inputId }}">{{ __('Quantity') }}</label>
                                        <input id="{{ $inputId }}" data-qty-input type="number" name="quantity" value="{{ $line->quantity }}" min="{{ $line->minQuantity }}" max="{{ $line->maxQuantity }}" autocomplete="off">
                                        <button type="button" data-qty-increment aria-label="{{ __('Increase quantity') }}">+</button>
                                    </div>
                                    <button class="mp-button mp-button-outline" type="submit" data-pending-label="{{ __('Updating…') }}">{{ __('Update') }}</button>
                                </form>
                                <form method="POST" action="{{ $removeUrl }}" data-cart-line-form data-confirm="{{ __('Remove :product from your cart?', ['product' => $product->name]) }}">
                                    @csrf
                                    @method('DELETE')
                                    @if ($variant)
                                        <input type="hidden" name="variant" value="{{ $variant->getKey() }}">
                                    @endif
                                    <button class="mp-cart-remove" type="submit" data-pending-label="{{ __('Removing…') }}">{{ __('Remove') }}</button>
                                </form>
                                <p class="mp-cart-total" data-cart-line-total>{{ $money($line->lineTotal) }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <aside class="mp-panel mp-summary" aria-labelledby="mp-summary-title">
                    <h2 id="mp-summary-title" class="text-base font-bold">{{ __('Order summary') }}</h2>
                    <dl class="mp-summary-rows">
                        <div><dt>{{ __('Items') }}</dt><dd>{{ $itemCount }}</dd></div>
                        <div class="is-total"><dt>{{ __('Subtotal') }}</dt><dd data-cart-subtotal>{{ $money($subtotal) }}</dd></div>
                        <div class="mp-muted"><dt>{{ __('Delivery') }}</dt><dd>{{ __('Calculated at checkout') }}</dd></div>
                    </dl>
                    @if ($setting->vatActive())
                        <p class="mt-3 text-xs mp-muted" data-cart-vat-note>
                            {{ $setting->vatInclusive() ? __('Prices include :vat', ['vat' => $setting->vatDisplayLabel()]) : __(':vat is added at checkout', ['vat' => $setting->vatDisplayLabel()]) }}
                        </p>
                    @endif
                    <a class="mp-button mp-button-accent mp-button-lg mt-4 w-full" href="{{ $checkoutUrl }}">{{ __('Proceed to checkout') }}</a>
                    <a class="mt-3 block text-center text-sm font-semibold text-[var(--storefront-brand)] hover:underline" href="{{ $productsUrl }}">{{ __('Continue shopping') }}</a>
                    <p class="mt-4 text-xs mp-muted">{{ __('Your order is sent to :store for review and confirmation.', ['store' => $company->name]) }}</p>
                </aside>
            </div>
        @endif
    </div>

    {{-- Phone: the total and checkout stay reachable above the bottom navigation. --}}
    @if ($items->isNotEmpty())
        <div class="mp-sticky-buy sm:hidden" data-mobile-checkout-bar>
            <div class="min-w-0">
                <span class="block text-[11px] mp-muted">{{ __('Subtotal') }}</span>
                <span class="block truncate text-base font-extrabold">{{ $money($subtotal) }}</span>
            </div>
            <a class="mp-button mp-button-accent flex-1" href="{{ $checkoutUrl }}">{{ __('Proceed to checkout') }}</a>
        </div>
        <div class="sm:hidden" style="height: calc(4.75rem + env(safe-area-inset-bottom));" aria-hidden="true"></div>
    @endif

    @include('storefront.cart.partials.cart-script')
@endsection
