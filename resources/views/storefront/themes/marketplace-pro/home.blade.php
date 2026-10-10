@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@section('content')
    @php
        $template = $setting->homepageTemplate();
        $productLimit = $setting->marketplaceProductLimit();
        $visibleProducts = $products->take($productLimit);
        $slides = $slides ?? collect();
        $productsUrl = \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null);
        $categoryUrl = fn ($category) => \App\Support\StorefrontUrl::route('categories.show', [$category->slug], $previewSlug ?? null);
        $resellerJoinUrl = isset($previewSlug) ? null : $company->resellerJoinUrl();
        $resellerUrl = $resellerJoinUrl
            ?: \App\Support\StorefrontUrl::route('reseller.show', [], $previewSlug ?? null);
        $whatsappDigits = preg_replace('/\D+/', '', (string) $setting->whatsapp_number);
        $quoteUrl = $whatsappDigits !== '' ? 'https://wa.me/'.$whatsappDigits : $productsUrl;
        // Campaign copy is owner-entered only (Storefront Settings →
        // Marketplace Pro Content); nothing is invented when it is empty.
        $campaignBadge = filled($setting->marketplace_campaign_badge) ? $setting->marketplace_campaign_badge : null;
        $campaignHeading = filled($setting->marketplace_campaign_heading) ? $setting->marketplace_campaign_heading : null;
        $campaignSubheading = filled($setting->marketplace_campaign_subheading) ? $setting->marketplace_campaign_subheading : null;
        $campaignCta = filled($setting->marketplace_campaign_cta_label)
            ? $setting->marketplace_campaign_cta_label
            : ($setting->marketplace_quote_enabled ? __('Request a quote') : __('Shop products'));
        $campaignUrl = $setting->marketplace_quote_enabled ? $quoteUrl : $productsUrl;
        $campaignOpensWhatsapp = $setting->marketplace_quote_enabled && $whatsappDigits !== '';
        $trustItems = $setting->marketplaceTrustItems();
        $bulkPricingRows = $setting->marketplaceBulkPricingRows();
        $businessHeading = filled($setting->marketplace_business_heading) ? $setting->marketplace_business_heading : __('Open a business account');
        $businessText = filled($setting->marketplace_business_text) ? $setting->marketplace_business_text : null;
    @endphp

    <div class="marketplace-pro-home">
        {{-- Hero: the owner's hero banner only (Storefront → Hero Slides). --}}
        @if ($slides->isNotEmpty())
            @include('storefront.partials.image-banner', ['slides' => $slides])
        @elseif ($campaignHeading && $template !== \App\Support\StorefrontThemeRegistry::MARKETPLACE_COMPACT)
            <section class="marketplace-section" aria-labelledby="marketplace-hero-title">
                <div class="marketplace-hero-banner">
                    <div class="relative z-10 flex min-h-[220px] max-w-2xl flex-col justify-center p-6 sm:min-h-[280px] sm:p-10">
                        @if ($campaignBadge)
                            <span class="marketplace-badge self-start">{{ $campaignBadge }}</span>
                        @endif
                        <h1 id="marketplace-hero-title" class="mt-4 text-balance text-3xl font-extrabold leading-tight text-white sm:text-4xl">{{ $campaignHeading }}</h1>
                        @if ($campaignSubheading)
                            <p class="mt-3 max-w-xl text-sm leading-6 text-white/80 sm:text-base">{{ $campaignSubheading }}</p>
                        @endif
                        <div class="mt-6 flex flex-wrap gap-3">
                            <a class="marketplace-button marketplace-button-accent" href="{{ $campaignUrl }}" @if ($campaignOpensWhatsapp) target="_blank" rel="noopener noreferrer" @endif>{{ $campaignCta }}</a>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        @if ($setting->marketplace_trust_strip_enabled && $trustItems !== [] && $template !== \App\Support\StorefrontThemeRegistry::MARKETPLACE_COMPACT)
            <section class="marketplace-section !mt-5" aria-label="{{ __('Store benefits') }}">
                <div class="marketplace-trust-grid">
                    @foreach ($trustItems as $item)
                        <div class="flex items-center gap-3">
                            <span class="marketplace-trust-icon" aria-hidden="true">
                                @include('storefront.themes.marketplace-pro.partials.trust-icon', ['icon' => $item['icon']])
                            </span>
                            <span>
                                <strong class="block text-sm">{{ $item['title'] }}</strong>
                                @if ($item['subtitle'])
                                    <span class="text-xs text-[var(--storefront-muted-text)] dark:text-[var(--storefront-dark-muted-text)]">{{ $item['subtitle'] }}</span>
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($template === \App\Support\StorefrontThemeRegistry::MARKETPLACE_COMPACT)
            <div class="marketplace-container marketplace-dense-shell {{ $setting->marketplace_sidebar_enabled ? 'marketplace-dense-shell-with-sidebar' : '' }}">
                @if ($setting->marketplace_sidebar_enabled)
                    <aside class="marketplace-sidebar" aria-label="{{ __('Product categories') }}">
                        <p class="text-xs font-bold uppercase tracking-wider text-[var(--storefront-muted-text)]">{{ __('Categories') }}</p>
                        <nav class="mt-3 flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible">
                            @foreach ($categories as $category)
                                <a class="marketplace-sidebar-link" href="{{ $categoryUrl($category) }}">{{ $category->name }}</a>
                            @endforeach
                        </nav>
                        @if ($setting->marketplace_quote_enabled)
                            <div class="mt-5 hidden rounded-xl bg-gray-100 p-4 lg:block dark:bg-white/10">
                                <p class="text-sm font-bold">{{ __('Need a custom quote?') }}</p>
                                <a class="marketplace-button marketplace-button-dark mt-3 w-full" href="{{ $quoteUrl }}" @if ($whatsappDigits !== '') target="_blank" rel="noopener noreferrer" @endif>{{ __('Request a quote') }}</a>
                            </div>
                        @endif
                    </aside>
                @endif

                <section class="min-w-0 py-5 lg:px-6" aria-labelledby="dense-products-title">
                    @if ($slides->isEmpty() && $campaignHeading)
                        <div class="marketplace-dense-banner">
                            <div>
                                <p class="font-bold">{{ $campaignHeading }}</p>
                                @if ($campaignSubheading)
                                    <p class="mt-1 text-xs text-white/75">{{ $campaignSubheading }}</p>
                                @endif
                            </div>
                            <a class="marketplace-button marketplace-button-accent" href="{{ $productsUrl }}">{{ __('Shop now') }}</a>
                        </div>
                    @endif
                    @if ($setting->marketplace_trust_strip_enabled && $trustItems !== [])
                        <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 border-b border-[var(--storefront-border)] pb-4 text-xs font-semibold dark:border-[var(--storefront-dark-border)]">
                            @foreach ($trustItems as $item)<span>✓ {{ $item['title'] }}</span>@endforeach
                        </div>
                    @endif
                    <div class="mt-5 flex items-end justify-between gap-4">
                        <h1 id="dense-products-title" class="text-xl font-extrabold sm:text-2xl">{{ __('All products') }}</h1>
                        <a class="marketplace-link-arrow text-sm font-semibold text-[var(--storefront-brand)]" href="{{ $productsUrl }}">{{ __('View all') }} @include('storefront.partials.arrow-right-icon')</a>
                    </div>
                    <div class="marketplace-product-grid marketplace-product-grid-dense mt-4">
                        @forelse ($visibleProducts as $product)
                            @include('storefront.themes.marketplace-pro.partials.product-card', ['product' => $product])
                        @empty
                            <p class="col-span-full rounded-xl bg-[var(--storefront-surface)] p-8 text-center dark:bg-[var(--storefront-dark-surface)]">{{ __('No products are available yet.') }}</p>
                        @endforelse
                    </div>
                </section>
            </div>
        @else
            @if ($setting->marketplace_categories_enabled && $categories->isNotEmpty())
                <section class="marketplace-section" aria-labelledby="marketplace-categories-title">
                    <div class="marketplace-section-heading"><h2 id="marketplace-categories-title">{{ __('Shop by category') }}</h2><a class="marketplace-link-arrow" href="{{ $productsUrl }}">{{ __('See all') }} @include('storefront.partials.arrow-right-icon')</a></div>
                    <div class="marketplace-category-grid">
                        @foreach ($categories->take(6) as $category)
                            <a class="marketplace-category-card" href="{{ $categoryUrl($category) }}">
                                <span class="marketplace-category-media">
                                    @if ($category->image)
                                        <img src="{{ \App\Support\CompanyMedia::publicUrl($category->image, $company) }}" alt="" width="144" height="144" loading="lazy" decoding="async">
                                    @elseif ($category->icon)
                                        @include('storefront.partials.category-icon', ['icon' => $category->icon, 'iconClass' => 'h-8 w-8'])
                                    @else
                                        <span data-category-initial="{{ mb_substr($category->name, 0, 1) }}">{{ mb_substr($category->name, 0, 1) }}</span>
                                    @endif
                                </span>
                                <strong>{{ $category->name }}</strong>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($setting->marketplace_deals_enabled)
                <section class="marketplace-section {{ $template === \App\Support\StorefrontThemeRegistry::MARKETPLACE_CAMPAIGN ? 'marketplace-deals-surface' : '' }}" aria-labelledby="marketplace-deals-title">
                    <div class="marketplace-section-heading"><h2 id="marketplace-deals-title">{{ $template === \App\Support\StorefrontThemeRegistry::MARKETPLACE_CAMPAIGN ? __('Flash deals') : __('Featured offers') }}</h2><a class="marketplace-link-arrow" href="{{ $productsUrl }}">{{ __('See all') }} @include('storefront.partials.arrow-right-icon')</a></div>
                    <div class="marketplace-product-grid">
                        @forelse ($visibleProducts->take(5) as $product)
                            @include('storefront.themes.marketplace-pro.partials.product-card', ['product' => $product])
                        @empty
                            <p class="col-span-full rounded-xl bg-[var(--storefront-surface)] p-8 text-center dark:bg-[var(--storefront-dark-surface)]">{{ __('No products are available yet.') }}</p>
                        @endforelse
                    </div>
                </section>
            @endif

            @if ($template === \App\Support\StorefrontThemeRegistry::MARKETPLACE_CAMPAIGN && (($setting->marketplace_bulk_pricing_enabled && $bulkPricingRows !== []) || $setting->marketplace_business_accounts_enabled))
                <section class="marketplace-section grid gap-4 lg:grid-cols-2">
                    @if ($setting->marketplace_bulk_pricing_enabled && $bulkPricingRows !== [])
                        <div class="marketplace-info-card">
                            <h2 class="text-lg font-extrabold">{{ __('Bulk pricing') }}</h2>
                            <dl class="mt-4 divide-y divide-[var(--storefront-border)] text-sm dark:divide-[var(--storefront-dark-border)]">
                                @foreach ($bulkPricingRows as $row)
                                    <div class="flex justify-between gap-4 py-3"><dt>{{ $row['label'] }}</dt><dd class="font-bold">{{ $row['value'] }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                    @endif
                    @if ($setting->marketplace_business_accounts_enabled)
                        <div class="marketplace-business-card">
                            <div>
                                <h2 class="text-lg font-extrabold">{{ $businessHeading }}</h2>
                                @if ($businessText)
                                    <p class="mt-2 text-sm leading-6 text-white/75">{{ $businessText }}</p>
                                @endif
                            </div>
                            <a class="marketplace-button marketplace-button-accent mt-5 self-start" href="{{ $resellerUrl }}">{{ __('Get started') }}</a>
                        </div>
                    @endif
                </section>
            @endif

            <section class="marketplace-section" aria-labelledby="marketplace-products-title">
                <div class="marketplace-section-heading"><h2 id="marketplace-products-title">{{ __('Recommended for you') }}</h2><a class="marketplace-link-arrow" href="{{ $productsUrl }}">{{ __('View all products') }} @include('storefront.partials.arrow-right-icon')</a></div>
                <div class="marketplace-product-grid">
                    @forelse ($visibleProducts->skip(5)->take(5) as $product)
                        @include('storefront.themes.marketplace-pro.partials.product-card', ['product' => $product])
                    @empty
                        @foreach ($visibleProducts->take(5) as $product)
                            @include('storefront.themes.marketplace-pro.partials.product-card', ['product' => $product])
                        @endforeach
                    @endforelse
                </div>
            </section>

            @if ($setting->marketplace_business_strip_enabled && $template === \App\Support\StorefrontThemeRegistry::MARKETPLACE_HERO)
                <section class="marketplace-section">
                    <div class="marketplace-business-strip">
                        <div>
                            <h2 class="text-lg font-extrabold sm:text-xl">{{ $businessHeading }}</h2>
                            @if ($businessText)
                                <p class="mt-1 text-sm text-white/70">{{ $businessText }}</p>
                            @endif
                        </div>
                        <a class="marketplace-button marketplace-button-accent" href="{{ $resellerUrl }}">{{ __('Open business account') }}</a>
                    </div>
                </section>
            @endif
        @endif
    </div>
@endsection
