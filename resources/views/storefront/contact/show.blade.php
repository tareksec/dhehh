@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@php
    $contactEmail = $setting->contact_email ?: $company->email;
    $callNumber = $setting->phone_number ?: $company->phone;
    $mapUrl = $company->google_maps_url
        ?: ($company->address ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($company->address) : null);

    $cards = collect([
        [
            'label' => 'Email Us',
            'text' => 'Send us an email and we\'ll get back to you within 24 hours.',
            'action' => $contactEmail,
            'href' => $contactEmail ? 'mailto:'.$contactEmail : null,
            'gradient' => 'from-sky-400 to-blue-600',
            'glow' => 'shadow-blue-500/30',
            'icon' => 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75',
        ],
        [
            'label' => 'Chat on WhatsApp',
            'text' => 'Message our team directly for the fastest response.',
            'action' => $setting->whatsapp_number,
            'href' => $setting->whatsapp_number ? 'https://wa.me/'.preg_replace('/\D+/', '', $setting->whatsapp_number) : null,
            'external' => true,
            'gradient' => 'from-emerald-400 to-teal-600',
            'glow' => 'shadow-emerald-500/30',
            'icon' => 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 8.25c-.621 0-1.125-.504-1.125-1.125V16.5a9 9 0 1 1 3.213 2.06c-.499.196-1.02.354-1.559.472a19.9 19.9 0 0 1-3.29.918Z',
        ],
        [
            'label' => 'Help Center',
            'text' => 'Find quick answers to the questions we hear most.',
            'action' => $faqs->isNotEmpty() ? 'Browse FAQs' : null,
            'href' => $faqs->isNotEmpty() ? '#faq' : null,
            'gradient' => 'from-violet-400 to-purple-600',
            'glow' => 'shadow-purple-500/30',
            'icon' => 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z',
        ],
        [
            'label' => 'Call Us',
            'text' => $setting->contact_hours ?: 'Give us a call for friendly, direct support.',
            'action' => $callNumber,
            'href' => $callNumber ? 'tel:'.preg_replace('/\s+/', '', $callNumber) : null,
            'gradient' => 'from-amber-400 to-orange-600',
            'glow' => 'shadow-amber-500/30',
            'icon' => 'M2.25 6.75c0 8.284 6.716 15 15 15h1.5a2.25 2.25 0 0 0 2.25-2.25v-1.372a1 1 0 0 0-.804-.98l-4.204-.841a1 1 0 0 0-1.028.417l-.92 1.38a1 1 0 0 1-1.21.38 12.035 12.035 0 0 1-5.512-5.512 1 1 0 0 1 .38-1.21l1.38-.92a1 1 0 0 0 .417-1.028l-.84-4.204a1 1 0 0 0-.98-.804H4.5a2.25 2.25 0 0 0-2.25 2.25v.75Z',
        ],
    ])->filter(fn (array $card) => filled($card['href']));

    $ctaHref = $setting->whatsapp_number
        ? 'https://wa.me/'.preg_replace('/\D+/', '', $setting->whatsapp_number)
        : ($contactEmail ? 'mailto:'.$contactEmail : null);
    $ctaLabel = $setting->whatsapp_number ? 'Chat on WhatsApp' : 'Email us';

    // Tailwind's content scanner needs each full class name written literally
    // somewhere in this file — string-building "lg:grid-cols-{n}" at runtime
    // would never be picked up by the build.
    $cardsGridClass = match (min(4, max(1, $cards->count()))) {
        1 => 'lg:grid-cols-1',
        2 => 'lg:grid-cols-2',
        3 => 'lg:grid-cols-3',
        default => 'lg:grid-cols-4',
    };
@endphp

@section('content')
    <section class="bg-gradient-to-br from-[var(--storefront-brand)] to-gray-950 dark:to-black">
        <div class="mx-auto flex w-full max-w-4xl flex-col items-center gap-3 px-4 py-10 text-center sm:px-5 sm:py-12 lg:px-6">
            <p class="text-xs font-semibold uppercase tracking-wider text-white/70">{{ $company->name }}</p>
            <h1 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ __('Connect with Us') }}</h1>
            <p class="max-w-lg text-sm leading-6 text-white/80 sm:text-base">
                Chat with our friendly team for quick help with any questions or issues you may have.
            </p>
        </div>
    </section>

    @if ($cards->isNotEmpty())
        <section class="mx-auto w-full max-w-7xl px-4 py-7 sm:px-5 sm:py-8 lg:px-6">
            <div class="-mt-14 grid grid-cols-1 gap-4 sm:-mt-16 sm:grid-cols-2 sm:gap-5 {{ $cardsGridClass }}">
                @foreach ($cards as $card)
                    <a
                        class="flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-white/10 dark:bg-gray-900"
                        href="{{ $card['href'] }}"
                        @if ($card['external'] ?? false) target="_blank" rel="noopener" @endif
                    >
                        <div class="relative grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br {{ $card['gradient'] }} shadow-lg {{ $card['glow'] }}">
                            <span class="pointer-events-none absolute inset-x-1.5 top-1 h-1/3 rounded-full bg-white/40 blur-[3px]"></span>
                            <svg class="relative h-6 w-6 text-white drop-shadow" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $card['icon'] }}" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-base font-semibold text-gray-900 dark:text-white">{{ $card['label'] }}</div>
                            <p class="mt-1 text-sm leading-5 text-gray-500 dark:text-gray-400">{{ $card['text'] }}</p>
                        </div>
                        <div class="mt-auto text-sm font-medium text-[var(--storefront-brand)]">{{ $card['action'] }}</div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($company->address)
        <section class="border-t border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/[0.02]">
            <div class="mx-auto w-full max-w-4xl px-4 py-8 text-center sm:px-5 lg:px-6">
                <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Our Location') }}</h2>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Meet our friendly team at this location for personalized assistance.') }}</p>

                <div class="mt-5 flex flex-col items-center gap-4 rounded-lg border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
                    <div class="relative grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-rose-400 to-red-600 shadow-lg shadow-red-500/30">
                        <span class="pointer-events-none absolute inset-x-1.5 top-1 h-1/3 rounded-full bg-white/40 blur-[3px]"></span>
                        <svg class="relative h-6 w-6 text-white drop-shadow" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                        </svg>
                    </div>
                    <div class="text-sm leading-6 text-gray-700 dark:text-gray-200">{{ $company->address }}</div>
                    @if ($mapUrl)
                        <a class="text-sm font-semibold text-[var(--storefront-brand)]" href="{{ $mapUrl }}" target="_blank" rel="noopener">Find on Map</a>
                    @endif
                </div>
            </div>
        </section>
    @endif

    @if ($faqs->isNotEmpty())
        <section id="faq" class="scroll-mt-24 mx-auto w-full max-w-4xl px-4 py-8 sm:px-5 lg:px-6">
            <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Frequently Asked Questions') }}</h2>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Everything you need to know. Can&rsquo;t find the answer you&rsquo;re looking for?</p>

            <div class="mt-5 divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10" x-data="{ open: 0 }">
                @foreach ($faqs as $index => $faq)
                    @php($faqDomId = 'faq-'.$faq->getKey())
                    <div>
                        <h3>
                            <button
                                id="{{ $faqDomId }}-trigger"
                                type="button"
                                class="flex min-h-12 w-full items-center justify-between gap-4 px-6 py-4 text-left transition-colors hover:bg-gray-50 dark:hover:bg-white/5"
                                aria-controls="{{ $faqDomId }}-panel"
                                :aria-expanded="(open === {{ $index }}).toString()"
                                @click="open = (open === {{ $index }} ? null : {{ $index }})"
                            >
                                <span class="font-medium text-gray-900 dark:text-white">{{ $faq->question }}</span>
                                <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform" :class="open === {{ $index }} ? 'rotate-180' : ''" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                            </button>
                        </h3>
                        <div id="{{ $faqDomId }}-panel" role="region" aria-labelledby="{{ $faqDomId }}-trigger" x-show="open === {{ $index }}" x-cloak x-transition.opacity>
                            <p class="px-6 pb-4 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $faq->answer }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($ctaHref)
        <section class="bg-[var(--storefront-brand)]">
            <div class="mx-auto flex w-full max-w-7xl flex-col items-center gap-3 px-4 py-8 text-center sm:px-5 lg:px-6">
                <h2 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">{{ __('Still Have Questions?') }}</h2>
                <p class="max-w-md text-sm text-white/85">Can&rsquo;t find the answer you&rsquo;re looking for? Please chat to our friendly team.</p>
                <a class="inline-flex items-center rounded-lg bg-white px-6 py-3 text-sm font-semibold text-gray-950 transition hover:bg-white/90" href="{{ $ctaHref }}" target="_blank" rel="noopener">
                    {{ $ctaLabel }}
                </a>
            </div>
        </section>
    @endif
@endsection
