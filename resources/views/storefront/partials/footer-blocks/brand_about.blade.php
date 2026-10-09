@php
    $logoUrl = ($data['show_logo'] ?? false) ? \App\Support\CompanyMedia::publicUrl($setting->logo, $company) : null;
@endphp
<div>
    @if ($logoUrl)
        <img class="mb-3 h-8 w-auto" src="{{ $logoUrl }}" alt="{{ $company->name }}">
    @else
        <div class="text-lg font-semibold tracking-tight">{{ $company->name }}</div>
    @endif
    @if (filled($data['tagline'] ?? null))
        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $data['tagline'] }}</div>
    @endif
    <p class="mt-3 max-w-sm text-sm leading-6 text-gray-500 dark:text-gray-400">
        {{ filled($data['text'] ?? null) ? $data['text'] : 'Browse curated products, place direct orders, and track storefront purchases from '.$company->name.'.' }}
    </p>
</div>
