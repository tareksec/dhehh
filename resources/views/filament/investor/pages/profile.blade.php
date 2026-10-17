<x-filament-panels::page>
    {{ $this->details }}

    @if ($this->isStaffPreview())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('A staff preview cannot change a password.') }}</p>
    @else
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}

            <div>
                <x-filament::button type="submit">
                    {{ __('Change password') }}
                </x-filament::button>
            </div>
        </form>
    @endif
</x-filament-panels::page>
