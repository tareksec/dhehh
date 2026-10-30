<x-filament-panels::page>
    @if (! $this->hasSelectedCompany())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Select a single company from the switcher above to set up its social connection.') }}
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-medium">{{ __('Social queue worker') }}</dt>
                    <dd class="text-gray-600 dark:text-gray-300">
                        @if ($this->workerSeen())
                            {{ __('Running (seen in the last 15 minutes).') }}
                        @else
                            {{ __('Not seen yet. Publishing cannot be switched on until it is running.') }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="font-medium">{{ __('Last successful call to Meta') }}</dt>
                    <dd class="text-gray-600 dark:text-gray-300">{{ $this->lastSuccessAt() ?? __('Never') }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}

            <x-filament::button type="submit">{{ __('Save') }}</x-filament::button>
        </form>

        <x-filament::section :heading="__('The second worker')" collapsible collapsed>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('Publishing runs on its own queue so a long publish is never handed to two workers. Add a second worker in Coolify with the same image and this start command:') }}
            </p>
            <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-100 p-3 text-xs dark:bg-white/5">php artisan queue:work social --queue=social --timeout=900 --tries=1 --sleep=1 --max-time=3600</pre>
        </x-filament::section>
    @endif
</x-filament-panels::page>
