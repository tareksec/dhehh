<x-filament-panels::page>
    @if (! $this->hasSelectedCompany())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Select a single company from the switcher above to browse its video library.') }}
            </p>
        </x-filament::section>
    @else
        {{ $this->table }}
    @endif
</x-filament-panels::page>
