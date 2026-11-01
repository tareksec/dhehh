@php
    $footerBlocks = collect($setting->footerBlocks());
    $footerBottomBar = $footerBlocks->first(fn (array $block): bool => ($block['type'] ?? null) === 'bottom_bar');
    $footerGridBlocks = $footerBlocks->reject(fn (array $block): bool => ($block['type'] ?? null) === 'bottom_bar');
@endphp

<footer class="storefront-footer mb-16 mt-12 border-t border-gray-200 bg-gray-50 sm:mb-0 dark:border-white/10 dark:bg-white/[0.02]" x-reveal>
    @if ($sisterCompanies->isNotEmpty())
        <div class="border-b border-gray-200 dark:border-white/10">
            <div class="mx-auto w-full max-w-7xl px-4 py-5 sm:px-5 lg:px-6">
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ __('Our other brands') }}</div>
                <div class="mt-3 flex flex-wrap gap-3">
                    @foreach ($sisterCompanies as $sisterCompany)
                        <a class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition hover:border-[var(--storefront-brand)] hover:text-gray-950 dark:border-white/10 dark:text-gray-300 dark:hover:text-white" href="https://{{ $sisterCompany->domain }}" target="_blank" rel="noopener">
                            {{ $sisterCompany->name }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if ($footerGridBlocks->isNotEmpty())
        <div class="mx-auto grid w-full max-w-7xl gap-7 px-4 py-8 sm:px-5 md:grid-cols-3 lg:px-6">
            @foreach ($footerGridBlocks as $block)
                @includeIf('storefront.partials.footer-blocks.'.$block['type'], ['data' => $block['data'] ?? []])
            @endforeach
        </div>
    @endif

    @if ($footerBottomBar)
        @include('storefront.partials.footer-blocks.bottom_bar', ['data' => $footerBottomBar['data'] ?? []])
    @endif
</footer>
