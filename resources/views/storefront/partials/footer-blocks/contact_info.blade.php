<div>
    <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $data['heading'] ?? 'Contact' }}</div>
    @php
        $contactPhones = collect(preg_split('/\R/u', is_array($data['phones'] ?? null) ? implode("\n", $data['phones']) : (string) ($data['phones'] ?? '')))->map(fn ($phone) => trim((string) $phone))->filter()->take(4);
    @endphp
    @if (filled($data['address'] ?? null) || filled($data['email'] ?? null) || $contactPhones->isNotEmpty() || filled($data['trade_license'] ?? null) || ($data['show_hours'] ?? false))
        <div class="mt-3 flex flex-col items-start gap-1.5 text-sm text-gray-600 dark:text-gray-400">
            @if (filled($data['address'] ?? null))
                <div>{!! nl2br(e($data['address'])) !!}</div>
            @endif
            @if (filled($data['email'] ?? null))
                <a class="transition hover:text-[var(--storefront-brand)]" href="mailto:{{ $data['email'] }}">{{ $data['email'] }}</a>
            @endif
            @foreach ($contactPhones as $contactPhone)
                <a class="transition hover:text-[var(--storefront-brand)]" href="tel:{{ preg_replace('/[^\d+]/', '', $contactPhone) }}">{{ $contactPhone }}</a>
            @endforeach
            @if (($data['show_hours'] ?? false) && filled($setting->contact_hours))
                <div>{{ $setting->contact_hours }}</div>
            @endif
            @if (filled($data['trade_license'] ?? null))
                <div class="text-xs text-gray-500 dark:text-gray-500">{{ $data['trade_license'] }}</div>
            @endif
        </div>
    @endif
    <div class="mt-3 flex flex-col items-start gap-3">
        <a class="text-sm text-gray-600 transition hover:text-[var(--storefront-brand)] dark:text-gray-400" href="{{ \App\Support\StorefrontUrl::route('contact', [], $previewSlug ?? null) }}">
            Contact us
        </a>
        <a class="text-sm text-gray-600 transition hover:text-[var(--storefront-brand)] dark:text-gray-400" href="{{ \App\Support\StorefrontUrl::route('complaints.show', [], $previewSlug ?? null) }}">
            Product complaint
        </a>
        @if ($setting->reseller_program_enabled ?? true)
            <a class="text-sm text-gray-600 transition hover:text-[var(--storefront-brand)] dark:text-gray-400" href="{{ $resellerUrl }}">
                Become a reseller
            </a>
        @endif
        @if ($setting->whatsapp_number)
            <a class="inline-flex rounded-lg bg-[var(--storefront-brand)] px-4 py-2 text-sm font-medium text-white" href="https://wa.me/{{ preg_replace('/\D+/', '', $setting->whatsapp_number) }}" target="_blank" rel="noopener">
                Chat on WhatsApp
            </a>
        @endif
    </div>
</div>
