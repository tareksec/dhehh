<x-filament-panels::page>
    @if ($this->company())
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
            <p class="text-sm">{{ __('Choose a company first: investor portal settings belong to one company.') }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
