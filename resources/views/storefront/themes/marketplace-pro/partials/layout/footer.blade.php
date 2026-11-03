{{--
    Marketplace Pro footer: the same admin-managed Footer Builder blocks as
    the default footer (Storefront Settings → Footer). It follows the visitor's
    colour mode: a light surface in light mode and the theme's dark navy
    surface in dark mode (see `.mp-footer` in app.css), so the light and dark
    logos each show in their own mode.
--}}
@php
    $footerBlocks = collect($setting->footerBlocks());
    $footerBottomBar = $footerBlocks->first(fn (array $block): bool => ($block['type'] ?? null) === 'bottom_bar');
    $footerGridBlocks = $footerBlocks->reject(fn (array $block): bool => ($block['type'] ?? null) === 'bottom_bar');
@endphp

<footer class="storefront-footer mp-footer mb-16 mt-12 lg:mb-0">
    <a class="mp-footer-top block bg-gray-100 py-3 text-center text-sm font-semibold text-gray-700 transition hover:bg-gray-200 dark:bg-white/10 dark:text-white dark:hover:bg-white/15" href="#main-content">{{ __('Back to top') }}</a>

    @if ($sisterCompanies->isNotEmpty())
        <div class="border-b border-gray-200 dark:border-white/10">
            <div class="mp-shell py-5">
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-white/50">{{ __('Our other brands') }}</div>
                <div class="mt-3 flex flex-wrap gap-3">
                    @foreach ($sisterCompanies as $sisterCompany)
                        <a class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition hover:border-[var(--storefront-accent)] hover:text-gray-950 dark:border-white/15 dark:text-white/80 dark:hover:text-white" href="https://{{ $sisterCompany->domain }}" target="_blank" rel="noopener">{{ $sisterCompany->name }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if ($footerGridBlocks->isNotEmpty())
        <div class="mp-shell grid gap-7 py-9 md:grid-cols-3">
            @foreach ($footerGridBlocks as $block)
                @includeIf('storefront.partials.footer-blocks.'.$block['type'], ['data' => $block['data'] ?? []])
            @endforeach
        </div>
    @endif

    @if ($footerBottomBar)
        @include('storefront.partials.footer-blocks.bottom_bar', ['data' => $footerBottomBar['data'] ?? []])
    @endif
</footer>
