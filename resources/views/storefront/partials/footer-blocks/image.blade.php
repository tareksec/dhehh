@php
    $footerImageUrl = filled($data['image'] ?? null) ? \App\Support\CompanyMedia::publicUrl($data['image'], $company) : null;
    $footerImageLink = \App\Support\StorefrontSiteDesign::url($data['url'] ?? null);
@endphp
@if ($footerImageUrl)
    <div>
        @if (filled($data['heading'] ?? null))
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $data['heading'] }}</div>
        @endif
        @if ($footerImageLink)
            <a class="mt-3 inline-block" href="{{ $footerImageLink }}" rel="noopener">
                <img class="max-h-24 w-auto" src="{{ $footerImageUrl }}" alt="{{ $data['alt'] ?? '' }}" loading="lazy">
            </a>
        @else
            <img class="mt-3 max-h-24 w-auto" src="{{ $footerImageUrl }}" alt="{{ $data['alt'] ?? '' }}" loading="lazy">
        @endif
    </div>
@endif
