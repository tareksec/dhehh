<x-filament-panels::page>
    {{ $this->details }}

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">
                {{ __('Change password') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
