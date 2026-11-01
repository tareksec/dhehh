@extends('storefront.account.layout')

@php
    $resellerStatusLabel = \App\Models\Customer::RESELLER_STATUSES[$customer->reseller_status] ?? null;
    $accountRoute = static fn (string $name) => isset($previewSlug)
        ? route('storefront.preview.account.'.$name, $previewSlug)
        : route('storefront.account.'.$name);
    $resellerJoinUrl = isset($previewSlug) ? null : $company->resellerJoinUrl();
    $resellerUrl = $resellerJoinUrl
        ?: \App\Support\StorefrontUrl::route('reseller.show', [], $previewSlug ?? null);
@endphp

@section('account-content')
    <div>
        <p class="text-sm font-medium text-[var(--storefront-brand)]">{{ __('Profile') }}</p>
        <h2 class="mt-1 text-2xl font-semibold tracking-tight">{{ __('Profile settings') }}</h2>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Keep your contact and delivery information up to date.') }}</p>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
        <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" aria-labelledby="profile-information-heading">
            <h3 class="text-lg font-semibold tracking-tight" id="profile-information-heading">{{ __('Personal information') }}</h3>
            <form class="mt-4 space-y-4" method="POST" action="{{ $accountRoute('profile.update') }}">
                @csrf
                @method('PATCH')
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="name">{{ __('Full name') }}</label>
                    <input class="mt-2 min-h-11 w-full rounded-xl border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-2 focus:ring-[color-mix(in_srgb,var(--storefront-brand)_20%,transparent)] dark:border-gray-700 dark:bg-gray-950" id="name" name="name" type="text" autocomplete="name" value="{{ old('name', $customer->name) }}" required>
                    @error('name') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="phone_display">{{ __('Phone number') }}</label>
                    <input class="mt-2 min-h-11 w-full rounded-xl border border-gray-200 bg-gray-50 px-4 text-sm text-gray-500 outline-none dark:border-gray-800 dark:bg-gray-800 dark:text-gray-400" id="phone_display" type="tel" value="{{ $customer->phone }}" disabled readonly>
                    <p class="mt-1.5 text-xs text-gray-400">{{ __('Contact support if your login phone needs to change.') }}</p>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="email">{{ __('Email address') }}</label>
                    <input class="mt-2 min-h-11 w-full rounded-xl border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-2 focus:ring-[color-mix(in_srgb,var(--storefront-brand)_20%,transparent)] dark:border-gray-700 dark:bg-gray-950" id="email" name="email" type="email" autocomplete="email" spellcheck="false" value="{{ old('email', $customer->email) }}">
                    @error('email') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="address">{{ __('Default delivery address') }}</label>
                    <textarea class="mt-2 w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-2 focus:ring-[color-mix(in_srgb,var(--storefront-brand)_20%,transparent)] dark:border-gray-700 dark:bg-gray-950" id="address" name="address" rows="4" autocomplete="street-address">{{ old('address', $customer->address) }}</textarea>
                    @error('address') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <button class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-[var(--storefront-brand)] px-6 text-sm font-semibold text-white transition hover:opacity-90 sm:w-auto" type="submit">{{ __('Save profile') }}</button>
            </form>
        </section>

        <div class="space-y-4">
            <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" aria-labelledby="password-heading">
                <h3 class="text-lg font-semibold tracking-tight" id="password-heading">{{ __('Security') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Use at least 8 characters for your password.') }}</p>
                <form class="mt-4 space-y-4" method="POST" action="{{ $accountRoute('password.update') }}">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="current_password">{{ __('Current password') }}</label>
                        <input class="mt-2 min-h-11 w-full rounded-xl border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-2 focus:ring-[color-mix(in_srgb,var(--storefront-brand)_20%,transparent)] dark:border-gray-700 dark:bg-gray-950" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                        @error('current_password') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="new_password">{{ __('New password') }}</label>
                        <input class="mt-2 min-h-11 w-full rounded-xl border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-2 focus:ring-[color-mix(in_srgb,var(--storefront-brand)_20%,transparent)] dark:border-gray-700 dark:bg-gray-950" id="new_password" name="password" type="password" autocomplete="new-password" minlength="8" required>
                        @error('password') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="new_password_confirmation">{{ __('Confirm new password') }}</label>
                        <input class="mt-2 min-h-11 w-full rounded-xl border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-2 focus:ring-[color-mix(in_srgb,var(--storefront-brand)_20%,transparent)] dark:border-gray-700 dark:bg-gray-950" id="new_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
                    </div>
                    <button class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-gray-300 px-6 text-sm font-semibold text-gray-900 transition hover:border-gray-950 dark:border-gray-700 dark:text-white dark:hover:border-gray-500" type="submit">{{ __('Update password') }}</button>
                </form>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" aria-labelledby="reseller-heading">
                <h3 class="text-lg font-semibold tracking-tight" id="reseller-heading">{{ __('Reseller account') }}</h3>
                @if ($resellerStatusLabel && $customer->reseller_status !== 'none')
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Current status: <span class="font-semibold text-gray-900 dark:text-white">{{ $resellerStatusLabel }}</span></p>
                @else
                    <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">{{ __('Apply for wholesale pricing and direct ordering support.') }}</p>
                    <a class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl border border-gray-300 px-4 text-sm font-semibold transition hover:border-gray-950 dark:border-gray-700 dark:hover:border-gray-500" href="{{ $resellerUrl }}">Become a reseller</a>
                @endif
            </section>
        </div>
    </div>
@endsection
