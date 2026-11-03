@php
    $bannerSlides = ($slides ?? collect())->values();
    $bannerCount = $bannerSlides->count();
    $bannerProductIds = $bannerSlides->pluck('product_id')->filter()->unique();
    $bannerProductSlugs = $bannerProductIds->isNotEmpty()
        ? \App\Models\Product::withoutGlobalScopes()->whereIn('id', $bannerProductIds)->pluck('slug', 'id')
        : collect();
    $bannerPreviewSlug = $previewSlug ?? null;
    $bannerLink = function ($slide) use ($bannerProductSlugs, $bannerPreviewSlug) {
        if ($slide->cta_url) {
            return $slide->cta_url;
        }

        if (! $slide->product_id || ! $bannerProductSlugs->has($slide->product_id)) {
            return null;
        }

        $productSlug = $bannerProductSlugs->get($slide->product_id);

        return $bannerPreviewSlug
            ? route('storefront.preview.products.show', [$bannerPreviewSlug, $productSlug])
            : route('storefront.products.show', $productSlug);
    };
    $renderedBannerSlides = $bannerCount > 1
        ? collect([$bannerSlides->last()])->concat($bannerSlides)->push($bannerSlides->first())
        : $bannerSlides;
    // Owner-controlled per display type from Storefront -> Hero Slides. Both
    // default true, so the pagination keeps showing exactly as before unless
    // turned off. The hide happens in CSS (per breakpoint) rather than by
    // skipping the markup, so one rendered page works at every width.
    $bannerNavDesktop = ($setting ?? null)?->showsBannerPagination('desktop') ?? true;
    $bannerNavMobile = ($setting ?? null)?->showsBannerPagination('mobile') ?? true;
@endphp

@if ($bannerSlides->isNotEmpty())
    <h1 class="sr-only">{{ $company->name }}</h1>
    <section
        @class([
            'storefront-image-banner relative w-full overflow-hidden bg-gray-100 dark:bg-gray-950',
            'storefront-image-banner-hide-nav-desktop' => ! $bannerNavDesktop,
            'storefront-image-banner-hide-nav-mobile' => ! $bannerNavMobile,
        ])
        aria-label="{{ __('Storefront banners') }}"
        aria-roledescription="carousel"
        x-data="{
            count: {{ $bannerCount }},
            active: 0,
            position: {{ $bannerCount > 1 ? 1 : 0 }},
            animate: true,
            timer: null,
            paused: false,
            reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            start() {
                if (this.count < 2 || this.reduced || this.paused) { return; }
                this.stop();
                this.timer = setInterval(() => this.next(), 5000);
            },
            stop() {
                if (this.timer) { clearInterval(this.timer); this.timer = null; }
            },
            restart() {
                this.stop();
                this.start();
            },
            next() {
                if (this.count < 2) { return; }
                this.animate = true;
                this.position += 1;
                this.active = (this.active + 1) % this.count;
            },
            go(index) {
                this.animate = true;
                this.active = index;
                this.position = index + 1;
                this.restart();
            },
            toggle() {
                this.paused = ! this.paused;
                this.paused ? this.stop() : this.start();
            },
            finishTransition() {
                if (this.count < 2 || this.position !== this.count + 1) { return; }
                this.animate = false;
                this.position = 1;
                requestAnimationFrame(() => requestAnimationFrame(() => { this.animate = true; }));
            }
        }"
        x-init="start()"
        @mouseenter="stop()"
        @mouseleave="start()"
        @focusin="stop()"
        @focusout="start()"
    >
        <div
            class="flex h-full w-full"
            style="transform: translate3d(-{{ $bannerCount > 1 ? 100 : 0 }}%, 0, 0);"
            :style="`transform: translate3d(-${position * 100}%, 0, 0); transition: ${animate && ! reduced ? 'transform 700ms cubic-bezier(0.22, 1, 0.36, 1)' : 'none'}`"
            @transitionend.self="finishTransition()"
        >
            @foreach ($renderedBannerSlides as $renderedIndex => $slide)
                @php
                    $slideHref = $bannerLink($slide);
                    $isInitialSlide = $bannerCount > 1 ? $renderedIndex === 1 : $renderedIndex === 0;
                @endphp
                <div class="relative h-full w-full shrink-0">
                    @if ($slideHref)
                        <a class="block h-full w-full" href="{{ $slideHref }}" aria-label="{{ __('Open banner promotion') }}">
                    @endif
                    <picture class="block h-full w-full">
                        @if ($slide->image_mobile)
                            <source media="(max-width: 639px)" srcset="{{ \App\Support\CompanyMedia::publicUrl($slide->image_mobile, $company) }}">
                        @endif
                        <img
                            @class(['block h-full w-full', 'storefront-image-banner-fit' => $slide->fit_to_frame])
                            src="{{ \App\Support\CompanyMedia::publicUrl($slide->image, $company) }}"
                            alt="{{ $slideHref ? $company->name.' promotion' : '' }}"
                            width="1920"
                            height="640"
                            decoding="async"
                            @if ($isInitialSlide) fetchpriority="high" loading="eager" @else loading="lazy" @endif
                        >
                    </picture>
                    @if ($slideHref)
                        </a>
                    @endif

                    @if ($slide->heading || $slide->subheading || $slide->cta_label)
                        {{-- Only rendered when at least one field is filled — slides with
                             none of these fields keep showing as image-only, unchanged. --}}
                        <div class="pointer-events-none absolute inset-0 flex items-center">
                            <div class="mx-auto w-full max-w-7xl px-4 sm:px-5 lg:px-6">
                                <div class="max-w-md rounded-lg bg-black/35 p-4 backdrop-blur-sm sm:p-6">
                                    @if ($slide->heading)
                                        <h2 class="text-xl font-semibold text-white sm:text-3xl">{{ $slide->heading }}</h2>
                                    @endif
                                    @if ($slide->subheading)
                                        <p class="mt-2 text-sm text-white/90 sm:text-base">{{ $slide->subheading }}</p>
                                    @endif
                                    @if ($slide->cta_label)
                                        <span class="pointer-events-auto mt-4 inline-flex items-center rounded-lg bg-[var(--storefront-brand)] px-5 py-2.5 text-sm font-medium text-white">
                                            {{ $slide->cta_label }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($bannerCount > 1)
            <div class="storefront-image-banner-nav absolute inset-x-0 bottom-0 flex justify-center pb-1 sm:pb-2">
                <div class="flex items-center rounded-full bg-black/25 p-0.5 backdrop-blur-sm">
                    @foreach ($bannerSlides as $index => $slide)
                        <button
                            type="button"
                            @click="go({{ $index }})"
                            class="grid h-11 w-11 place-items-center rounded-full"
                            aria-label="Go to banner {{ $index + 1 }}"
                            :aria-pressed="(active === {{ $index }}).toString()"
                        >
                            <span
                                :class="active === {{ $index }} ? 'w-6 bg-white' : 'w-2 bg-white/55'"
                                class="h-2 rounded-full transition-[width,background-color] duration-200 motion-reduce:transition-none"
                                aria-hidden="true"
                            ></span>
                        </button>
                    @endforeach
                    <button
                        type="button"
                        x-show="! reduced"
                        x-cloak
                        @click="toggle()"
                        class="grid h-11 w-11 place-items-center rounded-full text-white transition hover:bg-white/15"
                        :aria-label="paused ? @js(__('Play banners')) : @js(__('Pause banners'))"
                        :aria-pressed="paused.toString()"
                    >
                        <svg x-show="! paused" class="h-4 w-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M6.75 5.25a.75.75 0 0 1 .75-.75h1.5a.75.75 0 0 1 .75.75v13.5a.75.75 0 0 1-.75.75H7.5a.75.75 0 0 1-.75-.75V5.25Zm7.5 0a.75.75 0 0 1 .75-.75h1.5a.75.75 0 0 1 .75.75v13.5a.75.75 0 0 1-.75.75H15a.75.75 0 0 1-.75-.75V5.25Z"/></svg>
                        <svg x-show="paused" x-cloak class="h-4 w-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M8.25 5.35v13.3c0 .92 1.02 1.47 1.79.97l10.23-6.65a1.15 1.15 0 0 0 0-1.94L10.04 4.38a1.15 1.15 0 0 0-1.79.97Z"/></svg>
                    </button>
                </div>
            </div>
        @endif
    </section>
@endif
