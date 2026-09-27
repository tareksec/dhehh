<x-filament-panels::page>
    @if (! $this->hasSelectedCompany())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Select a single company from the switcher above to configure Smart Scan.') }}
            </p>
        </x-filament::section>
    @else
        <form wire:submit="save" id="expense-scan-settings-form" class="space-y-6">
            {{ $this->form }}
        </form>
    @endif
</x-filament-panels::page>
