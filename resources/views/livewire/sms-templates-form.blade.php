<div class="space-y-4">
    @if ($this->company())
        {{ $this->form }}

        {{-- No <form> here: this sits inside the Integrations page's own form. --}}
        <div>
            <x-filament::button type="button" wire:click="save">
                {{ __('Save templates') }}
            </x-filament::button>
        </div>
    @else
        <p class="text-sm">{{ __('Choose a company first: SMS templates belong to one company.') }}</p>
    @endif

    <x-filament-actions::modals />
</div>
