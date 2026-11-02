@php
    $socialLinks = collect($setting->social_links ?? [])->filter(fn ($link) => filled($link['url'] ?? null))->values();
@endphp
@if ($socialLinks->isNotEmpty())
    <div>
        <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $data['heading'] ?? __('Follow us') }}</div>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($socialLinks as $link)
                <a class="grid h-9 w-9 place-items-center rounded-full border border-gray-200 text-gray-500 transition hover:border-[var(--storefront-brand)] hover:text-[var(--storefront-brand)] dark:border-white/10 dark:text-gray-400" href="{{ $link['url'] }}" target="_blank" rel="noopener" aria-label="{{ \App\Models\StorefrontSetting::SOCIAL_PLATFORMS[$link['platform'] ?? ''] ?? __('Social link') }}">
                    @include('storefront.partials.social-icon', ['platform' => $link['platform'] ?? null])
                </a>
            @endforeach
        </div>
    </div>
@endif
