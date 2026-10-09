@php
    // The block's own links (Footer Builder → Custom links column), resolved like the footer menu.
    $customLinks = $resolveMenu(\App\Support\StorefrontSiteDesign::links($data['links'] ?? [], 12));
@endphp
@if ($customLinks->isNotEmpty())
    <div>
        @if (filled($data['heading'] ?? null))
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $data['heading'] }}</div>
        @endif
        <nav class="mt-3 flex flex-col gap-2" aria-label="{{ $data['heading'] ?? __('Links') }}">
            @foreach ($customLinks as $customLink)
                <a class="text-sm text-gray-600 transition hover:text-[var(--storefront-brand)] dark:text-gray-400" href="{{ $customLink['url'] }}" @if ($isCurrentUrl($customLink['url'])) aria-current="page" @endif @if ($customLink['new_tab']) target="_blank" rel="noopener" @endif>
                    {{ $customLink['label'] }}
                </a>
            @endforeach
        </nav>
    </div>
@endif
