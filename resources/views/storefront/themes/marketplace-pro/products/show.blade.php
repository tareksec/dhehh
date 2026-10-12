@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@include('storefront.products.partials.product-meta-events')

@section('content')
    @php
        use Filament\Forms\Components\RichEditor\RichContentRenderer;

        $productPage = \App\Support\StorefrontProductPage::make($product, $company);
        $galleryImages = $productPage->galleryImages;
        $variantData = $productPage->variantData;
        $isVariableProduct = $productPage->isVariableProduct;
        $inStock = $productPage->inStock;
        $isPreorder = $productPage->isPreorder;
        $tiers = $productPage->tiers;
        $unit = $product->unit ?: __('pcs');
        $money = fn (float $amount) => \App\Support\MoneyFormatter::currency($amount);

        $homeUrl = \App\Support\StorefrontUrl::home($previewSlug ?? null);
        $productsUrl = \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null);
        $categoryUrl = $product->category
            ? \App\Support\StorefrontUrl::route('categories.show', [$product->category->slug], $previewSlug ?? null)
            : null;
        $whatsappDigits = preg_replace('/\D+/', '', (string) $setting->whatsapp_number);
        $whatsappUrl = $whatsappDigits !== ''
            ? 'https://wa.me/'.$whatsappDigits.'?text='.rawurlencode(__('I am interested in :product', ['product' => $product->name]))
            : null;

        $addLabel = $inStock ? __('Add to cart') : ($isPreorder ? __('Pre-order now') : __('Out of stock'));
        $buyLabel = $isPreorder && ! $inStock ? __('Pre-order now') : __('Buy now');
        $stockState = match (true) {
            $isVariableProduct => null,
            $inStock && $product->stock <= 5 => ['class' => 'is-low', 'label' => __('Only :count left', ['count' => (int) $product->stock])],
            $inStock => ['class' => 'is-in', 'label' => __('In stock')],
            $isPreorder => ['class' => 'is-preorder', 'label' => __('Pre-order')],
            default => ['class' => 'is-out', 'label' => __('Out of stock')],
        };

        $deliveryTimeInside = $setting->deliveryTimeFor('inside');
        $deliveryTimeOutside = $setting->deliveryTimeFor('outside');
        $trustItems = $setting->marketplace_trust_strip_enabled ? $setting->marketplaceTrustItems() : [];
        $isHtmlDescription = str_contains((string) $product->description, '<');
    @endphp

    <div class="mp-shell py-4 sm:py-6" data-product-page>
        <nav class="mp-breadcrumb" aria-label="{{ __('Breadcrumb') }}">
            <ol>
                <li><a href="{{ $homeUrl }}">{{ __('Home') }}</a></li>
                <li><a href="{{ $productsUrl }}">{{ __('All products') }}</a></li>
                @if ($categoryUrl)
                    <li><a href="{{ $categoryUrl }}">{{ $product->category->name }}</a></li>
                @endif
                <li aria-current="page" class="line-clamp-1">{{ $product->name }}</li>
            </ol>
        </nav>

        <div class="mt-4 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-8 xl:grid-cols-[minmax(0,560px)_minmax(0,1fr)]">
            {{-- Gallery --}}
            <div class="min-w-0 lg:sticky lg:top-4 lg:self-start">
                @if ($galleryImages->isNotEmpty())
                    <div class="mp-gallery">
                        <div data-zoom-frame class="mp-gallery-main group">
                            <img data-main-image class="h-full w-full object-contain transition-transform duration-200 ease-out will-change-transform lg:cursor-zoom-in" src="{{ $galleryImages->first() }}" alt="{{ $product->name }}" width="960" height="960" fetchpriority="high">
                            @if ($productPage->discountPercent > 0)
                                <span class="mp-badge mp-badge-deal pointer-events-none absolute left-3 top-3">-{{ $productPage->discountPercent }}%</span>
                            @endif
                        </div>
                        <div data-thumbnails data-thumb-class="mp-gallery-thumb" class="mp-gallery-thumbs {{ $galleryImages->count() < 2 && ! $productPage->variantsHaveImages() ? 'hidden' : '' }}">
                            @foreach ($galleryImages as $galleryImage)
                                <button type="button" data-thumb data-src="{{ $galleryImage }}" class="mp-gallery-thumb" aria-label="{{ __('Show photo :number', ['number' => $loop->iteration]) }}">
                                    <img class="aspect-square w-full object-cover" src="{{ $galleryImage }}" alt="" width="160" height="160" loading="lazy" decoding="async">
                                </button>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="mp-gallery-main grid place-items-center text-7xl font-semibold text-[var(--storefront-brand)]">{{ mb_substr($product->name, 0, 1) }}</div>
                @endif

                @if ($productPage->videoUrl)
                    <video class="mt-3 aspect-video w-full rounded-xl bg-black object-contain" src="{{ $productPage->videoUrl }}" @if ($galleryImages->isNotEmpty()) poster="{{ $galleryImages->first() }}" @endif controls playsinline preload="metadata" aria-label="{{ __(':product video', ['product' => $product->name]) }}"></video>
                @endif
            </div>

            {{-- Buy box --}}
            <div class="min-w-0">
                @if ($product->category)
                    <a class="text-xs font-bold uppercase tracking-wide text-[var(--storefront-brand)]" href="{{ $categoryUrl }}">{{ $product->category->name }}</a>
                @endif
                <h1 class="mp-product-title mt-1">{{ $product->name }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs mp-muted">
                    @if ($product->sku)
                        <span>{{ __('SKU') }}: <span data-sku class="font-semibold">{{ $product->sku }}</span></span>
                    @endif
                    @if ($stockState)
                        <span class="mp-stock-pill {{ $stockState['class'] }}" data-status>{{ $stockState['label'] }}</span>
                    @endif
                </div>

                <div class="mp-buy-box mt-4">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <span data-price class="mp-product-price">{{ $money($productPage->sellingPrice) }}</span>
                        @if ($productPage->discountPercent > 0)
                            <span class="text-sm mp-muted line-through">{{ $money($productPage->comparePrice) }}</span>
                            <span class="text-sm font-bold text-[var(--mp-deal)]">{{ __('Save :amount', ['amount' => $money($productPage->comparePrice - $productPage->sellingPrice)]) }}</span>
                        @endif
                    </div>
                    @if ($setting->vatActive())
                        <p class="mt-1 text-xs mp-muted" data-product-vat-note>
                            {{ $setting->vatInclusive() ? __('Price includes :vat', ['vat' => $setting->vatDisplayLabel()]) : __('Price excludes :vat', ['vat' => $setting->vatDisplayLabel()]) }}
                        </p>
                    @endif

                    @if ($tiers !== [] && ! $isVariableProduct)
                        <table class="mp-tier-table mt-4">
                            <caption>{{ __('Bulk pricing') }}</caption>
                            <thead>
                                <tr><th scope="col">{{ __('Quantity') }}</th><th scope="col">{{ __('Unit price') }}</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>{{ $productPage->moq }}{{ ($tiers[0]['min_qty'] ?? 0) > $productPage->moq ? '–'.($tiers[0]['min_qty'] - 1) : '+' }} {{ $unit }}</td>
                                    <td>{{ $money($productPage->sellingPrice) }}</td>
                                </tr>
                                @foreach ($tiers as $index => $tier)
                                    <tr>
                                        <td>{{ $tier['min_qty'] }}{{ isset($tiers[$index + 1]) ? '–'.($tiers[$index + 1]['min_qty'] - 1) : '+' }} {{ $unit }}</td>
                                        <td>{{ $money($tier['price']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if ($productPage->moq > 1)
                        <p class="mt-3 text-sm font-semibold">{{ __('Minimum order: :count :unit', ['count' => $productPage->moq, 'unit' => $unit]) }}</p>
                    @endif

                    @if ($isPreorder && ! $inStock)
                        <p class="mp-note mt-3">{{ __('This item is available for pre-order. An advance payment of :percent% is collected online at checkout; cash on delivery is not available for pre-order quantities.', ['percent' => $product->preorderAdvancePercent()]) }}</p>
                    @endif

                    <form id="product-purchase-form" class="mt-4" method="POST" action="{{ \App\Support\StorefrontUrl::route('cart.add', [$product->slug], $previewSlug ?? null) }}" data-purchase-form>
                        @csrf

                        @if ($isVariableProduct)
                            <p class="mb-2 text-sm font-semibold">{{ __('Choose options and quantities') }}</p>
                            @if ($variantData->isNotEmpty())
                                <div class="mp-variant-list" data-variant-rows>
                                    @foreach ($variantData as $variant)
                                        <div class="mp-variant-row {{ $variant['stock'] < 1 ? 'is-out' : '' }}" data-variant-row data-variant='@json($variant)'>
                                            <button type="button" data-variant-preview class="min-w-0 flex-1 text-left">
                                                <span class="block truncate text-sm font-semibold">{{ $variant['label'] }}</span>
                                                <span class="mt-0.5 block text-xs mp-muted">
                                                    {{ $money($variant['price']) }} ·
                                                    @if ($variant['stock'] > 0)
                                                        {{ __(':count in stock', ['count' => $variant['stock']]) }}
                                                    @else
                                                        <span class="font-semibold text-[var(--mp-deal)]">{{ __('Out of stock') }}</span>
                                                    @endif
                                                </span>
                                            </button>
                                            @if ($variant['stock'] > 0)
                                                <div class="mp-qty" data-qty-stepper>
                                                    <button type="button" data-qty-decrement aria-label="{{ __('Decrease quantity') }}">&minus;</button>
                                                    <label class="sr-only" for="variant-qty-{{ $variant['id'] }}">{{ __('Quantity for :option', ['option' => $variant['label']]) }}</label>
                                                    <input id="variant-qty-{{ $variant['id'] }}" data-qty-input data-variant-qty type="number" name="quantities[{{ $variant['id'] }}]" value="0" min="0" max="{{ $variant['stock'] }}" autocomplete="off">
                                                    <button type="button" data-qty-increment aria-label="{{ __('Increase quantity') }}">+</button>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                <p class="mt-2 text-xs mp-muted">{{ __('Set the quantity for each option you want, then add everything to your cart in one click.') }}</p>
                            @else
                                <p class="mp-note">{{ __('Options are currently unavailable. Please check back later or contact us for availability.') }}</p>
                            @endif
                        @endif

                        <div class="mt-4 flex flex-wrap items-stretch gap-2.5">
                            @if (! $isVariableProduct)
                                <div class="mp-qty mp-qty-lg" data-qty-stepper>
                                    <button type="button" data-qty-decrement aria-label="{{ __('Decrease quantity') }}">&minus;</button>
                                    <label class="sr-only" for="quantity">{{ __('Quantity') }}</label>
                                    <input id="quantity" data-qty-input type="number" name="quantity" value="{{ $productPage->minOrderQuantity }}" min="{{ $productPage->minOrderQuantity }}" max="{{ $productPage->maxOrderQuantity }}" autocomplete="off">
                                    <button type="button" data-qty-increment aria-label="{{ __('Increase quantity') }}">+</button>
                                </div>
                            @endif
                            <button type="submit" class="mp-button mp-button-outline mp-button-lg flex-1" data-add-to-cart data-idle-label="{{ $addLabel }}" data-count-label="{{ __('Add to cart (:count)') }}" data-pending-label="{{ __('Adding…') }}" @disabled(! $productPage->orderable || $isVariableProduct)>{{ $addLabel }}</button>
                            @if (! $isVariableProduct)
                                <button type="submit" name="buy_now" value="1" class="mp-button mp-button-accent mp-button-lg flex-1" data-buy-now data-pending-label="{{ __('Opening checkout…') }}" @disabled(! $productPage->orderable)>{{ $buyLabel }}</button>
                            @endif
                        </div>
                        @if ($whatsappUrl)
                            <a class="mp-button mp-button-whatsapp mt-2.5 w-full" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2a9.9 9.9 0 0 0-8.5 15l-1.4 5 5.2-1.4A9.9 9.9 0 1 0 12.04 2Zm0 18.1a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3.1.8.8-3-.2-.3a8.2 8.2 0 1 1 7 3.9Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.4.8 3.2.7.5-.1 1.5-.6 1.8-1.2.2-.6.2-1.1.1-1.2l-.4-.2Z"/></svg>
                                {{ __('Ask on WhatsApp') }}
                            </a>
                        @endif
                    </form>
                </div>

                @if ($deliveryTimeInside || $deliveryTimeOutside)
                    <ul class="mp-info-list mt-4" data-product-delivery-info>
                        @if ($deliveryTimeInside)
                            <li><span class="font-semibold">{{ __('Delivery inside Dhaka:') }}</span> {{ $deliveryTimeInside }}</li>
                        @endif
                        @if ($deliveryTimeOutside)
                            <li><span class="font-semibold">{{ __('Delivery outside Dhaka:') }}</span> {{ $deliveryTimeOutside }}</li>
                        @endif
                    </ul>
                @endif

                @if ($trustItems !== [])
                    <ul class="mp-trust-list mt-4" aria-label="{{ __('Store benefits') }}">
                        @foreach ($trustItems as $item)
                            <li>
                                <span class="marketplace-trust-icon" aria-hidden="true">@include('storefront.themes.marketplace-pro.partials.trust-icon', ['icon' => $item['icon']])</span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold">{{ $item['title'] }}</span>
                                    @if (filled($item['subtitle'] ?? null))
                                        <span class="block text-xs mp-muted">{{ $item['subtitle'] }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <section class="mp-panel mt-8" aria-labelledby="mp-description-title">
            <h2 id="mp-description-title" class="mp-section-title">{{ __('Description') }}</h2>
            <div class="prose prose-sm mt-3 max-w-3xl dark:prose-invert">
                @if (! $product->description)
                    <p>{{ __('Product details will be updated soon. Contact us for specifications, availability, and delivery details.') }}</p>
                @elseif ($isHtmlDescription)
                    {{ RichContentRenderer::make($product->description) }}
                @else
                    <p>{{ $product->description }}</p>
                @endif
            </div>
        </section>

        @if ($related->isNotEmpty())
            <section class="mt-8" aria-labelledby="mp-related-title">
                <div class="marketplace-section-heading">
                    <h2 id="mp-related-title">{{ __('You may also like') }}</h2>
                    @if ($categoryUrl)
                        <a class="marketplace-link-arrow" href="{{ $categoryUrl }}">{{ __('See all') }} @include('storefront.partials.arrow-right-icon')</a>
                    @endif
                </div>
                <div class="marketplace-product-grid mp-related-grid">
                    @foreach ($related as $relatedProduct)
                        @include('storefront.themes.marketplace-pro.partials.product-card', ['product' => $relatedProduct])
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    {{-- Phone: buy buttons stay reachable above the bottom navigation. --}}
    @if ($productPage->orderable)
        <div class="mp-sticky-buy sm:hidden" data-mobile-purchase-bar>
            <div class="min-w-0">
                <span class="block text-[11px] mp-muted">{{ __('Price') }}</span>
                <span class="block truncate text-base font-extrabold">{{ $money($productPage->sellingPrice) }}</span>
            </div>
            @if ($isVariableProduct)
                <button type="submit" form="product-purchase-form" class="mp-button mp-button-accent flex-1" data-add-to-cart data-idle-label="{{ $addLabel }}" data-count-label="{{ __('Add to cart (:count)') }}" data-pending-label="{{ __('Adding…') }}" disabled>{{ $addLabel }}</button>
            @else
                <button type="submit" form="product-purchase-form" class="mp-button mp-button-outline flex-1" data-add-to-cart data-idle-label="{{ $addLabel }}" data-pending-label="{{ __('Adding…') }}">{{ $addLabel }}</button>
                <button type="submit" form="product-purchase-form" name="buy_now" value="1" class="mp-button mp-button-accent flex-1" data-pending-label="{{ __('Opening checkout…') }}">{{ $buyLabel }}</button>
            @endif
        </div>
        <div class="sm:hidden" style="height: calc(4.75rem + env(safe-area-inset-bottom));" aria-hidden="true" data-mobile-purchase-spacer></div>
    @endif

    @include('storefront.products.partials.product-page-script')
@endsection
