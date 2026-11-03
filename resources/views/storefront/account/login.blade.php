@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@php
    $accountRoute = static fn (string $name) => isset($previewSlug)
        ? route('storefront.preview.account.'.$name, $previewSlug)
        : route('storefront.account.'.$name);
    $loginStoreUrl = $accountRoute('login.store');
    $registerStoreUrl = $accountRoute('register.store');
    $forgotUrl = $accountRoute('forgot-password');
    $otpUrl = $accountRoute('otp');
@endphp

@section('content')
    <section class="mx-auto w-full max-w-md px-4 py-8 sm:px-5 lg:px-6">
        <div
            x-data="{ tab: '{{ $activeTab }}' }"
            x-init="$nextTick(() => (tab === 'login' ? $refs.loginFirst : $refs.registerFirst)?.focus())"
        >
            <div class="grid grid-cols-2 gap-2 rounded-lg border border-gray-200 bg-gray-50 p-1 dark:border-white/10 dark:bg-gray-900" role="group" aria-label="{{ __('Log in or create an account') }}">
                <button
                    type="button"
                    :aria-pressed="(tab === 'login').toString()"
                    @click="tab = 'login'; $nextTick(() => $refs.loginFirst?.focus())"
                    class="rounded-lg py-2.5 text-sm font-medium transition"
                    :class="tab === 'login' ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400'"
                >
                    {{ __('Log in') }}
                </button>
                <button
                    type="button"
                    :aria-pressed="(tab === 'register').toString()"
                    @click="tab = 'register'; $nextTick(() => $refs.registerFirst?.focus())"
                    class="rounded-lg py-2.5 text-sm font-medium transition"
                    :class="tab === 'register' ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400'"
                >
                    {{ __('Create account') }}
                </button>
            </div>

            <div x-show="tab === 'login'" x-cloak class="mt-5">
                <h1 class="text-2xl font-semibold tracking-tight">{{ __('Welcome back') }}</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Log in with your phone number or email.') }}</p>

                <form class="mt-5 space-y-5 rounded-lg border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900" method="POST" action="{{ $loginStoreUrl }}">
                    @csrf
                    <div>
                        <label class="text-xs font-medium text-gray-500" for="identifier">{{ __('Phone number or email') }}</label>
                        <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="identifier" name="identifier" type="text" autocomplete="username" value="{{ old('identifier') }}" required x-ref="loginFirst">
                        @error('identifier') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-medium text-gray-500" for="password">{{ __('Password') }}</label>
                            <a class="text-xs font-medium text-[var(--storefront-brand)]" href="{{ $forgotUrl }}">{{ __('Forgot password?') }}</a>
                        </div>
                        <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="password" name="password" type="password" autocomplete="current-password" required>
                        @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <input class="rounded border-gray-300 text-[var(--storefront-brand)] focus:ring-[var(--storefront-brand)]" type="checkbox" name="remember" value="1">
                        {{ __('Keep me logged in') }}
                    </label>
                    {{-- Only after the server asked for it (an unknown device while the account or site is under attack). --}}
                    @if (session('captcha_required') && ($loginCaptchaKey = \App\Support\LoginCaptchaSettings::siteKey()))
                        <div>
                            <p class="mb-2 text-xs text-gray-500">{{ __('Please complete the security check below to sign in.') }}</p>
                            <div class="cf-turnstile" data-sitekey="{{ $loginCaptchaKey }}"></div>
                            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                        </div>
                    @endif
                    <button class="w-full rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90 [touch-action:manipulation]" type="submit">
                        {{ __('Log in') }}
                    </button>
                    <div class="flex items-center gap-3" aria-hidden="true">
                        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
                        <span class="text-xs text-gray-400">{{ __('or') }}</span>
                        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
                    </div>
                    <a class="flex min-h-11 w-full items-center justify-center rounded-lg border border-gray-300 px-6 py-3 text-sm font-medium text-gray-800 transition hover:border-[var(--storefront-brand)] hover:text-[var(--storefront-brand)] dark:border-white/15 dark:text-gray-100" href="{{ $otpUrl }}">
                        {{ __('Log in with an OTP') }}
                    </a>
                </form>
            </div>

            <div x-show="tab === 'register'" x-cloak class="mt-5">
                <h1 class="text-2xl font-semibold tracking-tight">{{ __('Create your account') }}</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('See your order history and check out faster next time.') }}</p>

                <form class="mt-5 space-y-5 rounded-lg border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900" method="POST" action="{{ $registerStoreUrl }}">
                    @csrf
                    <div>
                        <label class="text-xs font-medium text-gray-500" for="name">{{ __('Your name') }}</label>
                        <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="name" name="name" type="text" autocomplete="name" value="{{ old('name') }}" required x-ref="registerFirst">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500" for="phone">{{ __('Phone number') }}</label>
                        <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" value="{{ old('phone') }}" required>
                        @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500" for="email">{{ __('Email (optional)') }}</label>
                        <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="email" name="email" type="email" autocomplete="email" spellcheck="false" value="{{ old('email') }}">
                        @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500" for="address">{{ __('Delivery address (optional)') }}</label>
                        <textarea class="mt-2 w-full rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="address" name="address" rows="2" autocomplete="street-address">{{ old('address') }}</textarea>
                        @error('address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500" for="reg_password">{{ __('Password') }}</label>
                        <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="reg_password" name="password" type="password" autocomplete="new-password" minlength="8" required aria-describedby="reg_password_hint">
                        <p class="mt-1 text-xs text-gray-400" id="reg_password_hint">{{ __('At least 8 characters.') }}</p>
                        @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500" for="password_confirmation">{{ __('Confirm password') }}</label>
                        <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
                    </div>
                    <button class="w-full rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90 [touch-action:manipulation]" type="submit">
                        {{ __('Create account') }}
                    </button>
                </form>
            </div>
        </div>
    </section>
@endsection
