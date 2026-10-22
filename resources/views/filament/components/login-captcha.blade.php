@php
    $siteKey = \App\Support\LoginCaptchaSettings::siteKey();
@endphp

@if ($siteKey)
    {{-- Cloudflare Turnstile. Shown only after the sign-in gate asked for it; wire:ignore keeps the widget through Livewire re-renders. --}}
    <div
        wire:ignore
        x-data="{
            widget: null,
            async mount() {
                if (! window.turnstile) {
                    window.__loginCaptchaLoader ??= new Promise((resolve, reject) => {
                        const script = document.createElement('script')
                        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
                        script.async = true
                        script.onload = resolve
                        script.onerror = reject
                        document.head.appendChild(script)
                    })

                    await window.__loginCaptchaLoader
                }

                this.widget = window.turnstile.render(this.$refs.box, {
                    sitekey: @js($siteKey),
                    callback: (token) => { this.$wire.captchaToken = token },
                    'expired-callback': () => { this.$wire.captchaToken = null },
                    'error-callback': () => { this.$wire.captchaToken = null },
                })
            },
        }"
        x-init="mount()"
        x-on:login-captcha-reset.window="if (window.turnstile && widget !== null) window.turnstile.reset(widget)"
        class="flex flex-col items-center gap-2"
    >
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Please complete the security check below to sign in.') }}</p>
        <div x-ref="box"></div>
    </div>
@endif
