<x-filament-panels::page>
    <x-filament::section :heading="__('Security check (CAPTCHA)')" :description="__('Normal sign-ins never see it. It is shown only to a device the account has not signed in from before, while that account or the whole site is being attacked with password guesses. Without it, such a device simply has to wait.')">
        <div style="display: grid; gap: 1rem; max-width: 640px;">
            <label style="display: flex; align-items: center; gap: .6rem; font-size: .875rem; font-weight: 600;">
                <input type="checkbox" wire:model="settings.enabled" style="width: 1.1rem; height: 1.1rem;">
                {{ __('Switch the security check on') }}
            </label>
            <p style="font-size: .72rem; color: rgb(113 113 122); margin-top: -.6rem;">{{ __('Turn this on once both keys below are saved.') }}</p>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('Cloudflare Turnstile keys')" :description="__('Free at dash.cloudflare.com → Turnstile → Add widget. Add every domain people sign in on (the admin app, the shops, the reseller and investor portals), choose the Managed widget mode, then paste the two keys it gives you.')">
        <div style="display: grid; gap: 1rem; max-width: 640px;">
            <div>
                <label style="display:flex; align-items:center; gap:.35rem; font-size: .8rem; font-weight: 600; margin-bottom: .3rem;">
                    {{ __('Site key') }}
                </label>
                <x-filament::input.wrapper :valid="! $errors->has('settings.site_key')">
                    <x-filament::input type="text" wire:model="settings.site_key" />
                </x-filament::input.wrapper>
                @error('settings.site_key') <p style="font-size: .75rem; color: rgb(220 38 38); margin-top: .25rem;">{{ $message }}</p> @enderror
                <p style="font-size: .72rem; color: rgb(113 113 122); margin-top: .3rem;">{{ __('Public: it is part of the sign-in page.') }}</p>
            </div>
            <div>
                <label style="display:flex; align-items:center; gap:.35rem; font-size: .8rem; font-weight: 600; margin-bottom: .3rem;">
                    {{ __('Secret key') }} {{ ($settings['has_secret_key'] ?? false) ? __('(already saved — leave blank to keep it)') : '' }}
                </label>
                <x-filament::input.wrapper :valid="! $errors->has('settings.secret_key')">
                    <x-filament::input type="password" wire:model="settings.secret_key" autocomplete="off" placeholder="{{ ($settings['has_secret_key'] ?? false) ? '••••••••' : '' }}" />
                </x-filament::input.wrapper>
                @error('settings.secret_key') <p style="font-size: .75rem; color: rgb(220 38 38); margin-top: .25rem;">{{ $message }}</p> @enderror
                <p style="font-size: .72rem; color: rgb(113 113 122); margin-top: .3rem;">{{ __('Secret: stored encrypted and never shown again after saving.') }}</p>
            </div>
        </div>
    </x-filament::section>

    <div>
        <x-filament::button wire:click="save" icon="heroicon-m-check">{{ __('Save settings') }}</x-filament::button>
    </div>
</x-filament-panels::page>
