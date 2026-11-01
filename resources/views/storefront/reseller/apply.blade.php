@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@php
    $applyUrl = \App\Support\StorefrontUrl::route('reseller.store', [], $previewSlug ?? null);
@endphp

@section('content')
    <section class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-5 lg:px-6">
        <p class="text-xs font-semibold uppercase tracking-wider text-[var(--storefront-brand)]">{{ __('Wholesale partnership') }}</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">Become a {{ $company->name }} reseller.</h1>
        <p class="storefront-page-copy mt-3">
            Apply with your business details. After the store approves your application, you get access to wholesale pricing and direct ordering support.
        </p>

        <form class="mt-5 space-y-5 rounded-lg border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900" method="POST" action="{{ $applyUrl }}">
            @csrf
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label class="text-xs font-medium text-gray-500" for="name">{{ __('Your name') }}</label>
                    <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="name" name="name" type="text" autocomplete="name" value="{{ old('name') }}" required>
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500" for="phone">{{ __('Phone number') }}</label>
                    <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" value="{{ old('phone') }}" required>
                    @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-500" for="business_name">{{ __('Business / shop name') }}</label>
                <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="business_name" name="business_name" type="text" autocomplete="organization" value="{{ old('business_name') }}" required>
                @error('business_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-xs font-medium text-gray-500" for="note">Tell us about your business (optional)</label>
                <textarea class="mt-2 w-full rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="note" name="note" rows="3" autocomplete="off">{{ old('note') }}</textarea>
                @error('note') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <button class="rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90" type="submit">
                Submit application
            </button>
        </form>
    </section>
@endsection
