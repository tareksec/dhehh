<x-filament-panels::page>
    <x-filament::section heading="Meta App Credentials" description="One-time setup: the Meta Developer App used for Embedded Signup. These are also copied onto every WhatsApp Business App number connected below, so their inbound webhooks verify correctly.">
        <div style="display: grid; gap: 1rem; max-width: 640px;">
            <div>
                <label style="display:block; font-size: .8rem; font-weight: 600; margin-bottom: .3rem;">Meta App ID</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="settings.app_id" placeholder="123456789012345" />
                </x-filament::input.wrapper>
                @error('settings.app_id') <p style="font-size: .72rem; color: rgb(220 38 38); margin-top: .3rem;">{{ $message }}</p> @enderror
            </div>

            <div>
                <label style="display:block; font-size: .8rem; font-weight: 600; margin-bottom: .3rem;">
                    App Secret {{ ($settings['has_app_secret'] ?? false) ? '(already saved — leave blank to keep it)' : '' }}
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="password" wire:model="settings.app_secret" placeholder="{{ ($settings['has_app_secret'] ?? false) ? '••••••••' : '' }}" />
                </x-filament::input.wrapper>
                <p style="font-size: .72rem; color: rgb(113 113 122); margin-top: .3rem;">Stored encrypted per company. Also used to verify inbound webhook signatures.</p>
                @error('settings.app_secret') <p style="font-size: .72rem; color: rgb(220 38 38); margin-top: .3rem;">{{ $message }}</p> @enderror
            </div>

            <div>
                <label style="display:block; font-size: .8rem; font-weight: 600; margin-bottom: .3rem;">Embedded Signup Configuration ID <span style="font-weight: 400; color: rgb(113 113 122);">(optional for now — add once App Review is approved)</span></label>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="settings.config_id" placeholder="From App Dashboard → WhatsApp → Embedded Signup" />
                </x-filament::input.wrapper>
                @error('settings.config_id') <p style="font-size: .72rem; color: rgb(220 38 38); margin-top: .3rem;">{{ $message }}</p> @enderror
            </div>

            <div>
                <label style="display:block; font-size: .8rem; font-weight: 600; margin-bottom: .3rem;">Webhook Verify Token</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="settings.verify_token" placeholder="A shared secret you also set in the App Dashboard's Webhooks config" />
                </x-filament::input.wrapper>
                @error('settings.verify_token') <p style="font-size: .72rem; color: rgb(220 38 38); margin-top: .3rem;">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-filament::button wire:click="save" icon="heroicon-m-check">Save settings</x-filament::button>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Connect a Number" description="Connects your existing WhatsApp Business App number the way WhatsApp Web connects a device — the phone app keeps working, and this Inbox gains the same replies plus up to ~180 days of synced history.">
        @if(! ($settings['ready'] ?? false))
            <p style="font-size: .875rem; color: rgb(161 98 7);">Save the Meta App ID, App Secret, and Configuration ID above first.</p>
        @else
            <div
                x-data="{
                    ready: false,
                    init() {
                        window.fbAsyncInit = () => {
                            FB.init({ appId: @js($settings['app_id']), autoLogAppEvents: true, xfbml: true, version: @js($settings['graph_api_version']) });
                            this.ready = true;
                        };

                        if (! document.getElementById('facebook-jssdk')) {
                            const script = document.createElement('script');
                            script.id = 'facebook-jssdk';
                            script.src = 'https://connect.facebook.net/en_US/sdk.js';
                            script.async = true;
                            script.defer = true;
                            document.body.appendChild(script);
                        }

                        window.addEventListener('message', (event) => {
                            // Meta may answer from www., web. or m.facebook.com
                            // (web.facebook.com is common in Bangladesh), so accept
                            // any https facebook.com host, not one exact origin.
                            let originHost = '';

                            try {
                                const origin = new URL(event.origin);
                                originHost = origin.protocol === 'https:' ? origin.hostname : '';
                            } catch (error) {
                                return;
                            }

                            if (! (originHost === 'facebook.com' || originHost.endsWith('.facebook.com')) || typeof event.data !== 'string') {
                                return;
                            }

                            let payload;

                            try {
                                payload = JSON.parse(event.data);
                            } catch (error) {
                                return;
                            }

                            if (payload.type !== 'WA_EMBEDDED_SIGNUP') {
                                return;
                            }

                            if (payload.event === 'FINISH' || payload.event === 'FINISH_ONLY_WABA') {
                                this._signupData = payload.data ?? {};
                            }
                        });
                    },
                    launch() {
                        this._signupData = null;

                        FB.login((response) => {
                            const code = response?.authResponse?.code;

                            if (! code) {
                                return;
                            }

                            // The popup's FINISH message can arrive just after
                            // this callback; wait up to 5 seconds for it.
                            let waited = 0;
                            const submit = () => {
                                const data = this._signupData ?? {};

                                if ((! data.waba_id || ! data.phone_number_id) && waited < 5000) {
                                    waited += 250;
                                    setTimeout(submit, 250);

                                    return;
                                }

                                $wire.completeEmbeddedSignup(
                                    code,
                                    data.waba_id ?? '',
                                    data.phone_number_id ?? '',
                                    data.business_name ?? null,
                                );
                            };

                            submit();
                        }, {
                            config_id: @js($settings['config_id']),
                            response_type: 'code',
                            override_default_response_type: true,
                            extras: { setup: {}, featureType: 'whatsapp_business_app_onboarding', sessionInfoVersion: '3' },
                        });
                    },
                }"
            >
                <x-filament::button x-on:click="launch()" x-bind:disabled="! ready" icon="heroicon-m-chat-bubble-left-right">
                    Connect via WhatsApp Business App
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
