<div>
    <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $data['heading'] ?? 'Contact' }}</div>
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
