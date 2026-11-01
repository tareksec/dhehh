@php
    $metaTracking = app(\App\Services\StorefrontMetaTrackingService::class);
    $showMetaConsent = ! isset($previewSlug)
        && (bool) $setting->meta_tracking_enabled
        && (bool) $setting->meta_consent_required
        && $metaTracking->browserPixelIds($setting) !== [];
    $metaConsentStatus = $showMetaConsent ? $metaTracking->consentStatus($setting, request()) : null;
    $metaConsentMessage = trim((string) $setting->meta_consent_message)
        ?: 'We use Meta measurement cookies to understand storefront activity and improve advertising. You can accept or decline, and change this choice later.';
@endphp

@if ($showMetaConsent)
    <div
        data-meta-consent-panel
        class="fixed inset-x-3 bottom-20 z-50 mx-auto max-w-2xl rounded-xl border border-gray-200 bg-white p-4 shadow-xl sm:bottom-4 dark:border-gray-700 dark:bg-gray-900"
        role="region"
        aria-label="Meta measurement privacy choices"
        @if ($metaConsentStatus !== null) hidden @endif
    >
        <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Privacy choices') }}</div>
        <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $metaConsentMessage }}</p>
        <form method="POST" action="{{ route('storefront.meta-consent.store') }}" class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            @csrf
            <input type="hidden" name="redirect_to" value="{{ url()->current() }}">
            <button type="submit" name="choice" value="denied" class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[var(--storefront-brand)] focus:ring-offset-2 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-white/5">{{ __('Decline') }}</button>
            <button type="submit" name="choice" value="granted" class="min-h-11 rounded-lg bg-[var(--storefront-brand)] px-4 text-sm font-semibold text-[var(--storefront-brand-contrast)] transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-[var(--storefront-brand)] focus:ring-offset-2">{{ __('Accept Meta measurement') }}</button>
        </form>
    </div>

    <button
        type="button"
        data-meta-consent-manage
        class="fixed bottom-20 left-3 z-40 min-h-10 rounded-full border border-gray-200 bg-white px-3 text-xs font-medium text-gray-600 shadow-sm transition hover:text-gray-950 focus:outline-none focus:ring-2 focus:ring-[var(--storefront-brand)] sm:bottom-4 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:text-white"
        @if ($metaConsentStatus === null) hidden @endif
    >
        Privacy choices
    </button>

    <script>
        (function () {
            var panel = document.querySelector('[data-meta-consent-panel]');
            var manage = document.querySelector('[data-meta-consent-manage]');
            if (! panel || ! manage) { return; }

            manage.addEventListener('click', function () {
                panel.hidden = false;
                manage.hidden = true;
                panel.querySelector('button[type="submit"]')?.focus();
            });
        })();
    </script>
@endif
