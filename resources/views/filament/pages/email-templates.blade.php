<x-filament-panels::page>
    @if ($this->company())
        @unless ($this->emailReady())
            <x-filament::section>
                <p class="text-sm text-warning-600 dark:text-warning-400">{{ __('No email account is set up for this company yet, so no email can leave the server. Set it up in Settings → Integrations → Email Setup.') }}</p>
            </x-filament::section>
        @endunless

        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}

            <div>
                <x-filament::button type="submit">
                    {{ __('Save changes') }}
                </x-filament::button>
            </div>
        </form>
    @else
        <x-filament::section>
            <p class="text-sm">{{ __('Choose a company first: email templates belong to one company.') }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
