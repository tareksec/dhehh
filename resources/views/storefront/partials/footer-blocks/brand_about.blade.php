@php
    $showLogo = (bool) ($data['show_logo'] ?? false);
    $logoUrl = $showLogo ? \App\Support\CompanyMedia::publicUrl($setting->logo, $company) : null;
    $logoDarkUrl = $showLogo ? \App\Support\CompanyMedia::publicUrl($setting->logo_dark, $company) : null;
@endphp
<div>
    @if ($logoUrl)
        <img class="mb-3 h-8 w-auto {{ $logoDarkUrl ? 'dark:hidden' : '' }}" src="{{ $logoUrl }}" alt="{{ $company->name }}">
        @if ($logoDarkUrl)
            <img class="mb-3 hidden h-8 w-auto dark:block" src="{{ $logoDarkUrl }}" alt="{{ $company->name }}">
        @endif
    @else
        <div class="text-lg font-semibold tracking-tight">{{ $company->name }}</div>
    @endif
    @if (filled($data['tagline'] ?? null))
        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $data['tagline'] }}</div>
    @endif
    <p class="mt-3 max-w-sm text-sm leading-6 text-gray-500 dark:text-gray-400">
        {{ filled($data['text'] ?? null) ? $data['text'] : __('Browse curated products, place direct orders, and track storefront purchases from :company.', ['company' => $company->name]) }}
    </p>
</div>
