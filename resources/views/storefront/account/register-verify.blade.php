@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@php
    $accountRoute = static fn (string $name) => isset($previewSlug)
        ? route('storefront.preview.account.'.$name, $previewSlug)
        : route('storefront.account.'.$name);
    $verifyUrl = $accountRoute('register.verify.store');
    $resendUrl = $accountRoute('register.resend');
    $registerUrl = $accountRoute('register');
@endphp

@section('content')
    <section class="mx-auto w-full max-w-md px-4 py-8 sm:px-5 lg:px-6">
        <p class="text-xs font-semibold uppercase tracking-wider text-[var(--storefront-brand)]">{{ __('Verify your phone') }}</p>
        <h1 class="mt-3 text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Enter your 6-digit code') }}</h1>
        <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400">
            {{ __('We sent the code by SMS to :phone. It expires in :minutes minutes.', ['phone' => $maskedIdentifier, 'minutes' => \App\Services\CustomerAccountService::REGISTRATION_CODE_MINUTES]) }}
        </p>

        <form class="mt-5 space-y-5 rounded-lg border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900" method="POST" action="{{ $verifyUrl }}">
            @csrf
            <div>
                <label class="text-xs font-medium text-gray-500" for="register-code">{{ __('Verification code') }}</label>
                <input class="mt-2 min-h-12 w-full rounded-lg border border-gray-300 bg-white px-4 text-center font-mono text-xl tracking-[0.35em] outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="register-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required autofocus @error('code') aria-invalid="true" aria-describedby="register-code-error" @enderror>
                @error('code') <p class="mt-2 text-center text-xs text-red-600" id="register-code-error">{{ $message }}</p> @enderror
            </div>
            <button class="w-full rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90 [touch-action:manipulation]" type="submit">
                {{ __('Verify and create account') }}
            </button>
        </form>

        <form class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400" method="POST" action="{{ $resendUrl }}">
            @csrf
            {{ __('Didn\'t receive it?') }}
            <button class="font-medium text-[var(--storefront-brand)] [touch-action:manipulation]" type="submit">{{ __('Send a new code') }}</button>
            · <a class="font-medium text-[var(--storefront-brand)]" href="{{ $registerUrl }}">{{ __('Change details') }}</a>
        </form>
    </section>
@endsection
