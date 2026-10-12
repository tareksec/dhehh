<x-filament-panels::page>
    @if ($this->activeStage())
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-primary-50 px-4 py-3 text-sm text-primary-700 ring-1 ring-primary-600/10 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-400/20">
            <span>{{ __('Showing :stage orders only.', ['stage' => $this->activeStageLabel()]) }}</span>
            <button type="button" wire:click="clearStage" class="font-semibold underline underline-offset-2">
                {{ __('Show all orders') }}
            </button>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
