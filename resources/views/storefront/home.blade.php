@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@section('content')
    @php
        $heroProduct = $products->first();
        $slides = $slides ?? collect();
        $heroHeading = $setting->hero_heading ?: __('Shop the latest from :company.', ['company' => $company->name]);
        $heroSubheading = $setting->hero_subheading ?: __('Browse current products, order directly, and track purchases from one clean storefront.');
        $heroCta = $setting->hero_cta_label ?: __('Start shopping');
    @endphp

    @if ($slides->isNotEmpty())
        @include('storefront.partials.image-banner', ['slides' => $slides])
    @else
        <section class="border-b border-gray-200 dark:border-white/10" x-reveal>
            <div class="mx-auto grid w-full max-w-7xl gap-7 px-4 py-10 sm:px-5 lg:grid-cols-[1.05fr_0.95fr] lg:items-center lg:px-6 lg:py-12">
                <div>
                    <p class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-[var(--storefront-brand)]">
                        <span class="h-1.5 w-1.5 rounded-full bg-[var(--storefront-brand)]"></span>
                        {{ __('Official storefront') }}
                    </p>
                    <h1 class="mt-4 max-w-xl text-balance text-4xl font-semibold tracking-tight text-gray-950 sm:text-5xl lg:text-6xl dark:text-white">
                        {{ $heroHeading }}
                    </h1>
                    <p class="storefront-page-copy mt-3">
                        {{ $heroSubheading }}
                    </p>
                    <div class="mt-5 flex flex-wrap gap-3">
                        <a class="inline-flex items-center rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90" href="{{ \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null) }}">
                            {{ $heroCta }}
                        </a>
                        @if ($categories->isNotEmpty())
                            <a class="inline-flex items-center rounded-lg border border-gray-300 px-6 py-3 text-sm font-medium text-gray-900 transition hover:border-gray-950 dark:border-white/15 dark:text-white dark:hover:border-white" href="#collections">
                                {{ __('Browse collections') }}
                            </a>
                        @endif
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-900">
                    @if ($heroProduct?->image)
                        <img class="aspect-[4/3] w-full object-cover" src="{{ \App\Support\CompanyMedia::publicUrl($heroProduct->image, $company) }}" alt="{{ $heroProduct->name }}" width="1200" height="900" fetchpriority="high">
                    @else
                        <div class="grid aspect-[4/3] place-items-center text-7xl font-semibold text-[var(--storefront-brand)]">
                            {{ mb_substr($heroProduct?->name ?? $company->name, 0, 1) }}
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif

    @if ($categories->isNotEmpty())
        <section id="collections" class="border-b border-gray-200 dark:border-white/10" x-reveal>
            <div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-5 lg:px-6">
                <div class="mb-6 flex items-end justify-between gap-5">
                    <div>
                        <h2 class="text-xl font-semibold tracking-tight sm:text-2xl">{{ __('Shop by category') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Browse our full collection by category') }}</p>
                    </div>
                    <a class="inline-flex min-h-11 items-center text-sm font-medium text-gray-500 hover:text-gray-950 dark:hover:text-white" href="{{ \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null) }}">{{ __('See all') }}</a>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-6">
                    @foreach ($categories as $category)
                        <a
                            class="group relative flex flex-col items-center gap-3 overflow-hidden rounded-xl border border-gray-200 bg-white p-4 transition hover:-translate-y-1 hover:shadow-lg hover:shadow-gray-200/60 dark:border-white/10 dark:bg-gray-900 dark:hover:shadow-none"
                            href="{{ \App\Support\StorefrontUrl::route('categories.show', [$category->slug], $previewSlug ?? null) }}"
                        >
                            <div class="grid h-14 w-14 place-items-center overflow-hidden rounded-full bg-[var(--storefront-brand)]/10 text-[var(--storefront-brand)] transition group-hover:bg-[var(--storefront-brand)] group-hover:text-white">
                                @if ($category->image)
                                    <img class="h-full w-full object-cover" src="{{ \App\Support\CompanyMedia::publicUrl($category->image, $company) }}" alt="{{ $category->name }}" width="112" height="112" loading="lazy" decoding="async">
                                @elseif ($category->icon)
                                    @include('storefront.partials.category-icon', ['icon' => $category->icon, 'iconClass' => 'h-7 w-7'])
                                @else
                                    <span class="text-xl font-semibold" data-category-initial="{{ mb_substr($category->name, 0, 1) }}">{{ mb_substr($category->name, 0, 1) }}</span>
                                @endif
                            </div>
                            <div class="w-full truncate text-center text-sm font-medium text-gray-800 dark:text-gray-100">{{ $category->name }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($setting->hasActiveOffer())
        <section
            class="border-b border-gray-200 bg-[var(--storefront-secondary)] text-[var(--storefront-secondary-contrast)] dark:border-white/10"
            x-reveal
            x-data="{
                endsAt: new Date('{{ $setting->offer_ends_at->toIso8601String() }}').getTime(),
                remaining: { d: 0, h: 0, m: 0, s: 0 },
                tick() {
                    const diff = Math.max(0, this.endsAt - Date.now());
                    this.remaining = {
                        d: Math.floor(diff / 86400000),
                        h: Math.floor((diff / 3600000) % 24),
                        m: Math.floor((diff / 60000) % 60),
                        s: Math.floor((diff / 1000) % 60),
                    };
                }
            }"
            x-init="tick(); setInterval(() => tick(), 1000)"
        >
            <div class="mx-auto flex w-full max-w-7xl flex-wrap items-center justify-center gap-3 px-4 py-2.5 text-sm sm:justify-between sm:px-5 lg:px-6">
                <div class="font-semibold">
                    {{ $setting->offer_title }}
                    @if ($setting->offer_discount_percent)
                        <span class="text-[var(--storefront-accent)]">{{ __('— up to :offer_discount_percent% off', ['offer_discount_percent' => $setting->offer_discount_percent]) }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-1.5 font-mono text-xs" aria-label="{{ __('Offer ends in') }}">
                    <template x-if="remaining.d > 0">
                        <span class="rounded bg-white/10 px-2 py-1" x-text="remaining.d + 'd'"></span>
                    </template>
                    <span class="rounded bg-white/10 px-2 py-1" x-text="String(remaining.h).padStart(2, '0') + 'h'"></span>
                    <span class="rounded bg-white/10 px-2 py-1" x-text="String(remaining.m).padStart(2, '0') + 'm'"></span>
                    <span class="rounded bg-white/10 px-2 py-1" x-text="String(remaining.s).padStart(2, '0') + 's'"></span>
                </div>
            </div>
        </section>
    @endif

    @if ($setting->trust_strip_delivery || $setting->trust_strip_return || $setting->trust_strip_payment)
        @php
            $trustItems = collect([
                [
                    'text' => $setting->trust_strip_delivery,
                    'gradient' => 'from-sky-400 to-blue-600',
                    'glow' => 'shadow-blue-500/30',
                    // Truck
                    'icon' => 'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.9 17.9 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
                ],
                [
                    'text' => $setting->trust_strip_return,
                    'gradient' => 'from-emerald-400 to-teal-600',
                    'glow' => 'shadow-emerald-500/30',
                    // Arrow path (returns)
                    'icon' => 'M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3',
                ],
                [
                    'text' => $setting->trust_strip_payment,
                    'gradient' => 'from-amber-400 to-orange-600',
                    'glow' => 'shadow-amber-500/30',
                    // Banknotes
                    'icon' => 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-3.75H21a.75.75 0 0 0-.75.75v.75m0-16.5h.375a1.125 1.125 0 0 1 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375M21 12a2.25 2.25 0 0 0-2.25 2.25M12 10.875a2.625 2.625 0 1 0 0 5.25 2.625 2.625 0 0 0 0-5.25Z',
                ],
            ])->filter(fn (array $item) => filled($item['text']));
        @endphp
        <section class="border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/[0.02]">
            <div class="mx-auto grid w-full max-w-7xl grid-cols-3 gap-3 px-4 py-4 sm:gap-4 sm:px-5 sm:py-5 lg:px-6">
                @foreach ($trustItems as $item)
                    <div class="flex flex-col items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-4 text-center sm:flex-row sm:gap-4 sm:px-5 sm:text-left dark:border-white/10 dark:bg-gray-900">
                        <div class="relative grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br {{ $item['gradient'] }} shadow-lg {{ $item['glow'] }} sm:h-14 sm:w-14">
                            <span class="pointer-events-none absolute inset-x-1.5 top-1 h-1/3 rounded-full bg-white/40 blur-[3px]"></span>
                            <svg class="relative h-6 w-6 text-white drop-shadow sm:h-7 sm:w-7" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                            </svg>
                        </div>
                        <div class="text-xs font-semibold leading-4 text-gray-800 sm:text-sm sm:leading-5 dark:text-gray-100">{{ $item['text'] }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="mx-auto w-full max-w-7xl px-4 py-7 sm:px-5 sm:py-8 lg:px-6" x-reveal>
        <div class="mb-5 flex items-end justify-between gap-5">
            <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Featured products') }}</h2>
            <a class="inline-flex min-h-11 items-center text-sm font-medium text-gray-500 hover:text-gray-950 dark:hover:text-white" href="{{ \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null) }}">{{ __('View all') }}</a>
        </div>

        <div class="storefront-catalog-grid grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
            @forelse ($products->take(8) as $product)
                @include('storefront.partials.product-card', ['product' => $product])
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center text-gray-500 dark:border-white/15">
                    {{ __('No available products yet.') }}
                </div>
            @endforelse
        </div>
    </section>

    @foreach ($carousels ?? [] as $carousel)
        <section class="mx-auto w-full max-w-7xl px-4 pb-8 sm:px-5 lg:px-6" x-reveal>
            <div class="mb-5 flex items-end justify-between gap-5">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $carousel->title }}</h2>
                    @if ($carousel->subtitle)
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $carousel->subtitle }}</p>
                    @endif
                </div>
                <a class="hidden min-h-11 items-center text-sm font-medium text-gray-500 hover:text-gray-950 sm:inline-flex dark:hover:text-white" href="{{ \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null) }}">{{ __('View all') }}</a>
            </div>
            <div class="-mx-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0">
                <div class="flex snap-x snap-mandatory gap-5">
                    @foreach ($carousel->products as $product)
                        <div class="w-64 shrink-0 snap-start sm:w-72">
                            @include('storefront.partials.product-card', ['product' => $product])
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endforeach

    @if ($products->count() > 8)
        <section class="mx-auto w-full max-w-7xl px-4 pb-8 sm:px-5 lg:px-6" x-reveal>
            <div class="mb-6 flex items-end justify-between gap-5">
                <h2 class="text-xl font-semibold tracking-tight sm:text-3xl">{{ __('Explore more products') }}</h2>
                <a class="inline-flex min-h-11 items-center text-sm font-medium text-gray-500 hover:text-gray-950 dark:hover:text-white" href="{{ \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null) }}">{{ __('View all') }}</a>
            </div>
            <div class="storefront-catalog-grid grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-5 lg:grid-cols-5">
                @foreach ($products->skip(8) as $product)
                    @include('storefront.partials.product-card', ['product' => $product])
                @endforeach
            </div>
        </section>
    @endif

    <section class="border-t border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/[0.02]">
        <div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-5 sm:py-9 lg:px-6">
            <h2 class="mb-5 text-center text-xl font-semibold tracking-tight sm:text-2xl">{{ __('How to order') }}</h2>
            <style>
                /* Each step "activates" in turn on an 8s loop (2s per step).
                   All animations start paused; .steps-live (added on scroll into view) runs them. */
                .order-steps .step-card { animation: stepActive 8s ease-in-out 1 paused; }
                .order-steps .step-card:nth-child(1), .order-steps .step-card:nth-child(1) .step-badge, .order-steps .step-card:nth-child(1) .step-halo { animation-delay: 0s; }
                .order-steps .step-card:nth-child(2), .order-steps .step-card:nth-child(2) .step-badge, .order-steps .step-card:nth-child(2) .step-halo { animation-delay: 2s; }
                .order-steps .step-card:nth-child(3), .order-steps .step-card:nth-child(3) .step-badge, .order-steps .step-card:nth-child(3) .step-halo { animation-delay: 4s; }
                .order-steps .step-card:nth-child(4), .order-steps .step-card:nth-child(4) .step-badge, .order-steps .step-card:nth-child(4) .step-halo { animation-delay: 6s; }
                @keyframes stepActive {
                    0%, 25%, 100% { transform: translateY(0) scale(1); box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); }
                    8%, 17% { transform: translateY(-6px) scale(1.03); box-shadow: 0 18px 30px -12px rgb(0 0 0 / 0.25); }
                }
                /* Badge pulse + halo ring, synced with the card. */
                .order-steps .step-card .step-badge { animation: badgePulse 8s ease-in-out 1 paused; }
                @keyframes badgePulse {
                    0%, 25%, 100% { transform: scale(1) rotate(0deg); }
                    8%, 17% { transform: scale(1.12) rotate(-4deg); }
                }
                .order-steps .step-card .step-halo { animation: haloPing 8s ease-out 1 paused; opacity: 0; }
                @keyframes haloPing {
                    0%, 5% { transform: scale(.8); opacity: 0; }
                    9% { opacity: .55; }
                    22% { transform: scale(1.6); opacity: 0; }
                    100% { opacity: 0; }
                }
                /* Progress track between the badges (sm+): fill grows step to step, a glowing dot travels along it. */
                .order-steps .step-track-fill { animation: trackFill 8s linear 1 paused; transform-origin: left; }
                @keyframes trackFill {
                    0% { transform: scaleX(0); }
                    82% { transform: scaleX(1); }
                    92% { transform: scaleX(1); opacity: 1; }
                    100% { transform: scaleX(1); opacity: 0; }
                }
                .order-steps .step-runner { animation: runnerMove 8s linear 1 paused; }
                @keyframes runnerMove {
                    0% { left: 0%; opacity: 0; }
                    4% { opacity: 1; }
                    82% { left: 100%; opacity: 1; }
                    90%, 100% { left: 100%; opacity: 0; }
                }
                .order-steps.steps-live .step-card,
                .order-steps.steps-live .step-badge,
                .order-steps.steps-live .step-halo,
                .order-steps.steps-live .step-runner,
                .order-steps.steps-live .step-track-fill { animation-play-state: running !important; }
                @media (prefers-reduced-motion: reduce) {
                    .order-steps .step-card,
                    .order-steps .step-card .step-badge,
                    .order-steps .step-card .step-halo,
                    .order-steps .step-runner,
                    .order-steps .step-track-fill { animation: none !important; }
                    .order-steps .step-track-fill { transform: scaleX(1); }
                }
            </style>
            <div class="order-steps relative" data-order-steps>
                <div class="pointer-events-none absolute left-[12.5%] right-[12.5%] top-[52px] hidden h-1 -translate-y-1/2 rounded-full bg-gray-200 lg:block dark:bg-white/10">
                    <span class="step-track-fill absolute inset-0 rounded-full bg-gradient-to-r from-violet-500 via-sky-500 via-emerald-500 to-orange-500"></span>
                    <span class="step-runner absolute top-1/2 h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white shadow-[0_0_0_4px_rgba(59,130,246,0.35),0_0_18px_4px_rgba(59,130,246,0.55)]"></span>
                </div>
                <div class="relative grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
                    @foreach ([
                        [
                            'Choose a product', 'Browse the catalog and pick what you need.',
                            'from-violet-400 to-purple-600', 'shadow-purple-500/30', 'bg-purple-400',
                            // Magnifying glass
                            'm21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z',
                        ],
                        [
                            'Add to cart', 'Set the quantity and add it to your cart.',
                            'from-sky-400 to-blue-600', 'shadow-blue-500/30', 'bg-blue-400',
                            // Cart
                            'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z',
                        ],
                        [
                            'Confirm order', 'Fill in your delivery details and confirm.',
                            'from-emerald-400 to-teal-600', 'shadow-emerald-500/30', 'bg-emerald-400',
                            // Clipboard check
                            'M10.125 2.25h3.75c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125h-3.75a1.125 1.125 0 0 1-1.125-1.125v-1.5c0-.621.504-1.125 1.125-1.125ZM15 5.25c1.036 0 1.875.84 1.875 1.875v11.25c0 1.035-.84 1.875-1.875 1.875H9a1.875 1.875 0 0 1-1.875-1.875V7.125C7.125 6.09 7.965 5.25 9 5.25M9.75 12.75l1.5 1.5 3-3.75',
                        ],
                        [
                            'Receive delivery', 'Track your order until it reaches you.',
                            'from-amber-400 to-orange-600', 'shadow-amber-500/30', 'bg-orange-400',
                            // Package
                            'm21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
                        ],
                    ] as [$stepTitle, $stepDescription, $stepGradient, $stepGlow, $stepHalo, $stepIcon])
                        <div class="step-card relative flex flex-col items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-5 text-center will-change-transform dark:border-gray-800 dark:bg-gray-900">
                            <div class="relative shrink-0">
                                <span class="step-halo pointer-events-none absolute inset-0 rounded-2xl {{ $stepHalo }}"></span>
                                <div class="step-badge relative grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br {{ $stepGradient }} shadow-lg {{ $stepGlow }} sm:h-16 sm:w-16">
                                    <span class="pointer-events-none absolute inset-x-1.5 top-1 h-1/3 rounded-full bg-white/40 blur-[3px]"></span>
                                    <svg class="relative h-7 w-7 text-white drop-shadow sm:h-8 sm:w-8" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $stepIcon }}" />
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $stepTitle }}</div>
                                <div class="mt-1 text-xs leading-4 text-gray-500 sm:text-sm sm:leading-5 dark:text-gray-400">{{ $stepDescription }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var steps = document.querySelector('[data-order-steps]');
                    if (! steps) return;
                    if (! ('IntersectionObserver' in window)) { steps.classList.add('steps-live'); return; }
                    new IntersectionObserver(function (entries, observer) {
                        entries.forEach(function (entry) {
                            if (entry.isIntersecting) { steps.classList.add('steps-live'); observer.disconnect(); }
                        });
                    }, { threshold: 0.35 }).observe(steps);
                });
            </script>
        </div>
    </section>

    <section class="bg-[var(--storefront-brand)]">
        <div class="mx-auto flex w-full max-w-7xl flex-col items-center gap-3 px-4 py-8 text-center sm:px-5 lg:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">{{ __('Ready to order?') }}</h2>
            <p class="max-w-md text-sm text-white/85">{{ __('Browse the full catalog and get it delivered to your door.') }}</p>
            <a class="inline-flex items-center rounded-lg bg-white px-6 py-3 text-sm font-semibold text-gray-950 transition hover:bg-white/90" href="{{ \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null) }}">
                {{ $heroCta }}
            </a>
        </div>
    </section>
@endsection
