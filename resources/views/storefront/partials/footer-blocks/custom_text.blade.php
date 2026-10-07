@php
    $customText = trim((string) ($data['text'] ?? ''));
    $buttonUrl = \App\Support\StorefrontSiteDesign::url($data['button_url'] ?? null);
@endphp
@if (filled($data['heading'] ?? null) || $customText !== '')
    <div>
        @if (filled($data['heading'] ?? null))
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $data['heading'] }}</div>
        @endif
        @if ($customText !== '')
            <p class="mt-3 max-w-sm text-sm leading-6 text-gray-600 dark:text-gray-400">{!! nl2br(e($customText)) !!}</p>
        @endif
        @if (filled($data['button_label'] ?? null) && $buttonUrl)
            <a class="mt-3 inline-flex rounded-lg bg-[var(--storefront-brand)] px-4 py-2 text-sm font-medium text-white" href="{{ $buttonUrl }}">{{ $data['button_label'] }}</a>
        @endif
    </div>
@endif
