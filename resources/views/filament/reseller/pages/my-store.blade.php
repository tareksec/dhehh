<x-filament-panels::page>
    @if ($storeUrl = $this->storeUrl())
        <x-filament::section>
            <div class="flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Your store is live at:') }}</div>
                    <div class="break-all font-medium">{{ $storeUrl }}</div>
                </div>
                <x-filament::button
                    size="sm"
                    color="gray"
                    x-on:click="window.navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($storeUrl) }}); $tooltip({{ \Illuminate\Support\Js::from(__('Copied to clipboard')) }}, { theme: $store.theme, timeout: 1500 })"
                >
                    {{ __('Copy link') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">
                {{ __('Save changes') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
