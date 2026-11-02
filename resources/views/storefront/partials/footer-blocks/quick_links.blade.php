@if ($footerMenu->isNotEmpty())
    <div>
        <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $data['heading'] ?? __('Quick links') }}</div>
        <nav class="mt-3 flex flex-col gap-2" aria-label="{{ __('Footer menu') }}">
            @foreach ($footerMenu as $menuItem)
                <a class="text-sm text-gray-600 transition hover:text-[var(--storefront-brand)] dark:text-gray-400" href="{{ $menuItem['url'] }}" @if ($isCurrentUrl($menuItem['url'])) aria-current="page" @endif @if ($menuItem['new_tab']) target="_blank" rel="noopener" @endif>
                    {{ $menuItem['label'] }}
                </a>
            @endforeach
        </nav>
    </div>
@elseif ($footerPages->isNotEmpty())
    <div>
        <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $data['heading'] ?? __('Pages') }}</div>
        <nav class="mt-3 flex flex-col gap-2" aria-label="{{ __('Storefront pages') }}">
            @foreach ($footerPages as $footerPage)
                <a class="text-sm text-gray-600 transition hover:text-[var(--storefront-brand)] dark:text-gray-400" href="{{ $pageUrl($footerPage->slug) }}" @if ($isCurrentUrl($pageUrl($footerPage->slug))) aria-current="page" @endif>
                    {{ $footerPage->title }}
                </a>
            @endforeach
        </nav>
    </div>
@endif
