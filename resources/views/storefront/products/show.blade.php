@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@include('storefront.products.partials.product-meta-events')

@section('content')
    @php
        use Filament\Forms\Components\RichEditor\RichContentRenderer;

        $isHtmlDescription = str_contains((string) $product->description, '<');
        $categoryUrl = $product->category
            ? (\App\Support\StorefrontUrl::route('categories.show', [$product->category->slug], $previewSlug ?? null))
            : null;
        $productsUrl = \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null);

        $productPage = \App\Support\StorefrontProductPage::make($product, $company);
        $galleryImages = $productPage->galleryImages;
        $productVideoUrl = $productPage->videoUrl;
        $isVariableProduct = $productPage->isVariableProduct;
        $variantData = $productPage->variantData;
        $inStock = $productPage->inStock;
        $isPreorder = $productPage->isPreorder;
        $orderable = $productPage->orderable;
        $moq = $productPage->moq;
        $maxOrderQuantity = $productPage->maxOrderQuantity;
        $minOrderQuantity = $productPage->minOrderQuantity;
        $tiers = $productPage->tiers;
    @endphp

    <nav class="mx-auto w-full max-w-7xl px-4 pt-4 text-xs text-gray-500 sm:px-5 lg:px-6 dark:text-gray-400" aria-label="{{ __('Breadcrumb') }}">
        <a class="hover:text-gray-900 dark:hover:text-white" href="{{ $productsUrl }}">{{ __('Shop all') }}</a>
        @if ($categoryUrl)
            <span class="mx-2">/</span>
            <a class="hover:text-gray-900 dark:hover:text-white" href="{{ $categoryUrl }}">{{ $product->category->name }}</a>
        @endif
        <span class="mx-2">/</span>
        <span class="text-gray-900 dark:text-white">{{ $product->name }}</span>
    </nav>

    <section class="mx-auto grid w-full max-w-7xl gap-6 px-4 py-6 sm:px-5 lg:grid-cols-[minmax(0,460px)_minmax(0,1fr)] lg:gap-8 lg:px-6" data-product-page>
        <div class="w-full max-w-[460px] lg:sticky lg:top-28 lg:self-start">
            @if ($galleryImages->isNotEmpty())
                <div data-zoom-frame class="group relative aspect-square w-full overflow-hidden rounded-lg border border-gray-200 bg-gray-50 p-3 sm:p-4 dark:border-gray-800 dark:bg-gray-900">
                    <img data-main-image class="h-full w-full cursor-zoom-in object-contain transition-transform duration-200 ease-out will-change-transform" src="{{ $galleryImages->first() }}" alt="{{ $product->name }}" width="960" height="960" fetchpriority="high">
                    <div class="pointer-events-none absolute bottom-3 right-3 hidden items-center gap-1.5 rounded-full border border-white/70 bg-white/90 px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm backdrop-blur lg:flex dark:border-gray-700 dark:bg-gray-950/90 dark:text-gray-200">
                        <svg class="h-4 w-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0ZM11.25 8.25v6m3-3h-6"/></svg>
                        {{ __('Hover to zoom') }}
                    </div>
                </div>
                <div data-thumbnails class="mt-3 grid grid-cols-5 gap-2 {{ $galleryImages->count() < 2 && ! $productPage->variantsHaveImages() ? 'hidden' : '' }}">
                    @foreach ($galleryImages as $galleryImage)
                        <button type="button" data-thumb data-src="{{ $galleryImage }}" class="overflow-hidden rounded-lg border border-gray-200 transition hover:border-[var(--storefront-brand)] dark:border-white/10">
                            <img class="aspect-square w-full object-cover" src="{{ $galleryImage }}" alt="{{ $product->name }} photo {{ $loop->iteration }}" width="240" height="240" loading="lazy" decoding="async">
                        </button>
                    @endforeach
                </div>
            @else
                <div class="grid aspect-square w-full place-items-center rounded-lg border border-gray-200 bg-gray-100 text-7xl font-semibold text-[var(--storefront-brand)] dark:border-gray-800 dark:bg-gray-900">
                    {{ mb_substr($product->name, 0, 1) }}
                </div>
            @endif

            @if ($productVideoUrl)
                <div class="mt-3" data-product-video>
                    <video
                        class="aspect-video w-full rounded-lg border border-gray-200 bg-black object-contain dark:border-gray-800"
                        src="{{ $productVideoUrl }}"
                        @if ($galleryImages->isNotEmpty()) poster="{{ $galleryImages->first() }}" @endif
                        controls
                        playsinline
                        preload="metadata"
                        aria-label="{{ __(':product video', ['product' => $product->name]) }}"
                    ></video>
                </div>
            @endif
        </div>

        <div>
            <h1>{{ $product->name }}</h1>
            <div data-price class="mt-3 text-2xl font-semibold text-gray-950 dark:text-white">BDT {{ \App\Support\MoneyFormatter::number($product->selling_price) }}</div>
            @if ($tiers !== [] && ! $isVariableProduct)
                <div class="mt-4 max-w-xl overflow-hidden rounded-lg border border-gray-200 dark:border-white/15">
                    <div class="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                        {{ __('Wholesale pricing') }}
                    </div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            <tr>
                                <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300">{{ $moq }}{{ ($tiers[0]['min_qty'] ?? 0) > $moq ? ' - '.($tiers[0]['min_qty'] - 1) : '+' }} {{ $product->unit ?: 'pcs' }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold text-gray-950 dark:text-white">BDT {{ \App\Support\MoneyFormatter::number($product->selling_price) }}</td>
                            </tr>
                            @foreach ($tiers as $index => $tier)
                                <tr>
                                    <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300">
                                        {{ $tier['min_qty'] }}{{ isset($tiers[$index + 1]) ? ' - '.($tiers[$index + 1]['min_qty'] - 1) : '+' }} {{ $product->unit ?: 'pcs' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-semibold text-gray-950 dark:text-white">BDT {{ \App\Support\MoneyFormatter::number($tier['price']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($moq > 1)
                <div class="mt-4 inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                    {{ __('Minimum order: :moq :unit', ['moq' => $moq, 'unit' => $product->unit ?: __('pcs')]) }}
                </div>
            @endif

            @if ($isPreorder && ! $inStock)
                <div class="mt-4 rounded-lg border border-[var(--storefront-brand)]/30 bg-[var(--storefront-brand)]/5 px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ __('This item is available for pre-order. An advance payment of :product% is collected online at checkout; cash on delivery is not available for pre-order quantities.', ['product' => $product->preorderAdvancePercent()]) }}
                </div>
            @endif

            <dl class="mt-4 grid grid-cols-3 gap-3 border-y border-gray-200 py-4 text-sm dark:border-white/10">
                <div>
                    <dt class="text-gray-400">{{ __('Stock') }}</dt>
                    <dd data-stock class="mt-1 font-semibold">{{ (int) $product->stock }}</dd>
                </div>
                <div>
                    <dt class="text-gray-400">SKU</dt>
                    <dd data-sku class="mt-1 truncate font-semibold">{{ $product->sku }}</dd>
                </div>
                <div>
                    <dt class="text-gray-400">{{ __('Status') }}</dt>
                    <dd data-status class="mt-1 font-semibold {{ $inStock ? 'text-emerald-600 dark:text-emerald-400' : ($isPreorder ? 'text-[var(--storefront-brand)]' : 'text-red-600 dark:text-red-400') }}">
                        {{ $inStock ? __('Available') : ($isPreorder ? __('Pre-order') : __('Out of stock')) }}
                    </dd>
                </div>
            </dl>

            @php
                $deliveryTimeInside = $setting->deliveryTimeFor('inside');
                $deliveryTimeOutside = $setting->deliveryTimeFor('outside');
            @endphp
            @if ($deliveryTimeInside || $deliveryTimeOutside || $setting->vatActive())
                <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300" data-product-delivery-info>
                    @if ($deliveryTimeInside)
                        <li><span class="font-medium text-gray-900 dark:text-white">{{ __('Delivery inside Dhaka:') }}</span> {{ $deliveryTimeInside }}</li>
                    @endif
                    @if ($deliveryTimeOutside)
                        <li><span class="font-medium text-gray-900 dark:text-white">{{ __('Delivery outside Dhaka:') }}</span> {{ $deliveryTimeOutside }}</li>
                    @endif
                    @if ($setting->vatActive())
                        <li class="text-xs text-gray-500 dark:text-gray-400" data-product-vat-note>
                            {{ $setting->vatInclusive() ? __('Price includes :vat', ['vat' => $setting->vatDisplayLabel()]) : __('Price excludes :vat', ['vat' => $setting->vatDisplayLabel()]) }}
                        </li>
                    @endif
                </ul>
            @endif

            <form id="product-purchase-form" class="mt-4" method="POST" action="{{ \App\Support\StorefrontUrl::route('cart.add', [$product->slug], $previewSlug ?? null) }}" data-purchase-form>
                @csrf

                @if ($isVariableProduct)
                    <div class="mb-5">
                        <div class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Choose options and quantities') }}</div>
                        @if ($variantData->isNotEmpty())
                            <div class="divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 dark:divide-white/10 dark:border-white/15" data-variant-rows>
                                @foreach ($variantData as $variant)
                                    <div class="flex items-center justify-between gap-3 px-4 py-3 {{ $variant['stock'] < 1 ? 'opacity-50' : '' }}" data-variant-row data-variant='@json($variant)'>
                                        <button type="button" data-variant-preview class="min-w-0 flex-1 text-left">
                                            <div class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $variant['label'] }}</div>
                                            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                BDT {{ \App\Support\MoneyFormatter::number($variant['price']) }}
                                                &middot;
                                                @if ($variant['stock'] > 0)
                                                    {{ __(':variant in stock', ['variant' => $variant['stock']]) }}
                                                @else
                                                    <span class="font-medium text-red-600 dark:text-red-400">{{ __('Out of stock') }}</span>
                                                @endif
                                            </div>
                                        </button>
                                        @if ($variant['stock'] > 0)
                                            <div class="flex shrink-0 items-center rounded-lg border border-gray-300 dark:border-white/15" data-qty-stepper>
                                                <button type="button" data-qty-decrement class="grid h-11 w-11 place-items-center text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white [touch-action:manipulation]" aria-label="{{ __('Decrease quantity') }}">&minus;</button>
                                                <label class="sr-only" for="variant-qty-{{ $variant['id'] }}">{{ __('Quantity for :variant', ['variant' => $variant['label']]) }}</label>
                                                <input id="variant-qty-{{ $variant['id'] }}" data-qty-input data-variant-qty class="h-11 w-12 border-x border-gray-300 bg-transparent text-center text-sm font-semibold text-gray-950 outline-none focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:text-white" type="number" name="quantities[{{ $variant['id'] }}]" value="0" min="0" max="{{ $variant['stock'] }}" autocomplete="off">
                                                <button type="button" data-qty-increment class="grid h-11 w-11 place-items-center text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white [touch-action:manipulation]" aria-label="{{ __('Increase quantity') }}">+</button>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            <p data-variant-hint class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Set the quantity for each option you want, then add everything to your cart in one click.') }}</p>
                        @else
                            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                                {{ __('Options are currently unavailable. Please check back later or contact us for availability.') }}
                            </div>
                        @endif
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    @if (! $isVariableProduct)
                        <div class="flex items-center rounded-lg border border-gray-300 dark:border-white/15" data-qty-stepper>
                            <button type="button" data-qty-decrement class="grid h-12 w-11 place-items-center text-lg text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white [touch-action:manipulation]" aria-label="{{ __('Decrease quantity') }}">&minus;</button>
                            <label class="sr-only" for="quantity">{{ __('Quantity') }}</label>
                            <input id="quantity" data-qty-input class="h-12 w-14 border-x border-gray-300 bg-transparent text-center text-sm font-semibold text-gray-950 outline-none focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:text-white" type="number" name="quantity" value="{{ $minOrderQuantity }}" min="{{ $minOrderQuantity }}" max="{{ $maxOrderQuantity }}" autocomplete="off">
                            <button type="button" data-qty-increment class="grid h-12 w-11 place-items-center text-lg text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white [touch-action:manipulation]" aria-label="{{ __('Increase quantity') }}">+</button>
                        </div>
                    @endif
                    <button type="submit" data-add-to-cart data-idle-label="{{ $inStock ? __('Add to cart') : ($isPreorder ? __('Pre-order now') : __('Out of stock')) }}" data-pending-label="{{ __('Adding…') }}" class="inline-flex h-12 items-center rounded-lg border border-gray-950 px-7 text-sm font-medium text-gray-950 transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white dark:text-white dark:hover:bg-white/10" @disabled(! $orderable || $isVariableProduct)>
                        {{ $inStock ? __('Add to cart') : ($isPreorder ? __('Pre-order now') : __('Out of stock')) }}
                    </button>
                    @if (! $isVariableProduct)
                        <button type="submit" name="buy_now" value="1" data-buy-now data-pending-label="{{ __('Opening checkout…') }}" class="inline-flex h-12 items-center rounded-lg bg-[var(--storefront-brand)] px-7 text-sm font-medium text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50" @disabled(! $orderable)>
                            {{ $isPreorder && ! $inStock ? __('Pre-order now') : __('Buy now') }}
                        </button>
                    @endif
                    @if ($setting->whatsapp_number)
                        <a class="inline-flex h-12 items-center rounded-lg border border-gray-300 px-7 text-sm font-medium text-gray-900 transition hover:border-gray-950 dark:border-white/15 dark:text-white dark:hover:border-white" href="https://wa.me/{{ preg_replace('/\D+/', '', $setting->whatsapp_number) }}?text={{ rawurlencode(__('I am interested in :product', ['product' => $product->name])) }}" target="_blank" rel="noopener">
                            {{ __('Ask on WhatsApp') }}
                        </a>
                    @endif
                </div>
            </form>
        </div>
    </section>

    @if ($orderable)
        <div class="fixed inset-x-0 z-30 flex gap-3 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur sm:hidden dark:border-white/10 dark:bg-gray-950/95" style="bottom: calc(4rem + env(safe-area-inset-bottom));" data-mobile-purchase-bar>
            <button type="submit" form="product-purchase-form" data-add-to-cart data-add-to-cart-mobile data-idle-label="{{ $inStock ? __('Add to cart') : ($isPreorder ? __('Pre-order') : __('Out of stock')) }}" data-pending-label="{{ __('Adding…') }}" class="flex-1 rounded-lg border border-gray-950 px-4 py-3 text-sm font-medium text-gray-950 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white dark:text-white" @disabled($isVariableProduct)>
                {{ $inStock ? __('Add to cart') : ($isPreorder ? __('Pre-order') : __('Out of stock')) }}
            </button>
            @if (! $isVariableProduct)
                <button type="submit" form="product-purchase-form" name="buy_now" value="1" data-pending-label="{{ __('Opening checkout…') }}" class="flex-1 rounded-lg bg-[var(--storefront-brand)] px-4 py-3 text-sm font-medium text-white transition hover:opacity-90">
                    {{ $isPreorder && ! $inStock ? __('Pre-order now') : __('Buy now') }}
                </button>
            @endif
        </div>
    @endif

    <section class="mx-auto w-full max-w-7xl px-4 pb-8 sm:px-5 lg:px-6" x-data="{ tab: 'description' }">
        <div class="flex gap-6 border-b border-gray-200 text-sm font-medium dark:border-white/10" role="tablist" aria-label="{{ __('Product information') }}">
            <button
                id="product-tab-description"
                x-ref="descriptionTab"
                type="button"
                role="tab"
                aria-controls="product-panel-description"
                :aria-selected="(tab === 'description').toString()"
                :tabindex="tab === 'description' ? 0 : -1"
                @click="tab = 'description'"
                @keydown.right.prevent="tab = 'shipping'; $nextTick(() => $refs.shippingTab.focus())"
                @keydown.end.prevent="tab = 'shipping'; $nextTick(() => $refs.shippingTab.focus())"
                :class="tab === 'description' ? 'border-gray-950 text-gray-950 dark:border-white dark:text-white' : 'border-transparent text-gray-500 dark:text-gray-400'"
                class="inline-flex min-h-11 items-center border-b-2 transition"
            >{{ __('Description') }}</button>
            <button
                id="product-tab-shipping"
                x-ref="shippingTab"
                type="button"
                role="tab"
                aria-controls="product-panel-shipping"
                :aria-selected="(tab === 'shipping').toString()"
                :tabindex="tab === 'shipping' ? 0 : -1"
                @click="tab = 'shipping'"
                @keydown.left.prevent="tab = 'description'; $nextTick(() => $refs.descriptionTab.focus())"
                @keydown.home.prevent="tab = 'description'; $nextTick(() => $refs.descriptionTab.focus())"
                :class="tab === 'shipping' ? 'border-gray-950 text-gray-950 dark:border-white dark:text-white' : 'border-transparent text-gray-500 dark:text-gray-400'"
                class="inline-flex min-h-11 items-center border-b-2 transition"
            >{{ __('Shipping & Return') }}</button>
        </div>
        <div class="max-w-3xl py-6 text-sm leading-7 text-gray-600 dark:text-gray-300">
            <div id="product-panel-description" role="tabpanel" aria-labelledby="product-tab-description" tabindex="0" x-show="tab === 'description'">
                <div class="prose prose-sm max-w-none dark:prose-invert">
                    @if (! $product->description)
                        {{ __('Product details will be updated soon. Contact us for specifications, availability, and delivery details.') }}
                    @elseif ($isHtmlDescription)
                        {{ RichContentRenderer::make($product->description) }}
                    @else
                        <p>{{ $product->description }}</p>
                    @endif
                </div>
            </div>
            <div id="product-panel-shipping" role="tabpanel" aria-labelledby="product-tab-shipping" tabindex="0" x-show="tab === 'shipping'" x-cloak>
                <p>{{ __('Orders are processed and shipped after confirmation. Delivery time and charges depend on your location and are confirmed at checkout.') }}</p>
                @if ($setting->whatsapp_number)
                    <p class="mt-3">{{ __('For questions about returns or exchanges, message us on WhatsApp before returning an item.') }}</p>
                @endif
            </div>
        </div>
    </section>

    @if ($related->isNotEmpty())
        <section class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-5 lg:px-6">
            <h2 class="mb-6 text-2xl font-semibold tracking-tight">{{ __('You may also like') }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-5">
                @foreach ($related as $relatedProduct)
                    @include('storefront.partials.product-card', ['product' => $relatedProduct])
                @endforeach
            </div>
        </section>
    @endif

    @if ($orderable)
        <div class="sm:hidden" style="height: calc(4.5rem + env(safe-area-inset-bottom));" aria-hidden="true" data-mobile-purchase-spacer></div>
    @endif

    @include('storefront.products.partials.product-page-script')
@endsection
