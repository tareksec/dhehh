<x-filament-panels::page>
    @php
        $rows = $this->rows();
        $labels = \App\Services\Crm\ToChaseService::SOURCES;
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <div class="grid gap-4 md:grid-cols-3">
                <label class="block text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('Looking back') }}</span>
                    <select wire:model.live="days" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        @foreach (\App\Services\Crm\ToChaseService::DAY_CHOICES as $choice)
                            <option value="{{ $choice }}">{{ __(':days days', ['days' => $choice]) }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="text-sm md:col-span-2">
                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('Show') }}</span>
                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2">
                        @foreach ($labels as $key => $label)
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" wire:model.live="sources" value="{{ $key }}" class="rounded border-gray-300">
                                <span>{{ __($label) }}</span>
                            </label>
                        @endforeach
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" wire:model.live="includeContacted" class="rounded border-gray-300">
                            <span>{{ __('Include people already contacted') }}</span>
                        </label>
                    </div>
                </div>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Anyone who ordered after their last sign of interest is left out. Someone you marked as contacted comes back only if they show interest again.') }}</p>
        </x-filament::section>

        <x-filament::section>
            <p class="mb-3 text-sm font-medium">{{ __(':count to chase', ['count' => $rows->count()]) }}</p>

            @forelse ($rows as $row)
                <div class="flex flex-wrap items-start gap-3 py-3 {{ ! $loop->first ? 'border-t border-gray-200 dark:border-white/10' : '' }}" wire:key="chase-{{ $row['phone'] }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">{{ $row['name'] ?: __('No name') }} <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $row['phone'] }}</span></p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ collect($row['sources'])->map(fn ($source) => __($labels[$source] ?? $source))->implode(', ') }}
                            · {{ $row['last_at']->diffForHumans() }}
                            @if ($row['detail']) · {{ $row['detail'] }} @endif
                        </p>
                        @if ($row['contacted_at'])
                            <p class="mt-0.5 text-xs text-success-600 dark:text-success-400">{{ __('Contacted :when', ['when' => $row['contacted_at']->diffForHumans()]) }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::button tag="a" href="tel:+{{ $row['phone'] }}" size="sm" color="gray" outlined>{{ __('Call') }}</x-filament::button>
                        <x-filament::button tag="a" href="https://wa.me/{{ $row['phone'] }}" target="_blank" rel="noopener" size="sm" color="gray" outlined>WhatsApp</x-filament::button>
                        @if ($this->canManage())
                            @if ($row['contacted_at'] && $row['contacted_at']->greaterThanOrEqualTo($row['last_at']))
                                <x-filament::button size="sm" color="gray" wire:click="undoContacted('{{ $row['phone'] }}')">{{ __('Undo') }}</x-filament::button>
                            @else
                                <x-filament::button size="sm" wire:click="markContacted('{{ $row['phone'] }}')">{{ __('Mark contacted') }}</x-filament::button>
                            @endif
                        @endif
                    </div>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('Nobody to chase right now.') }}</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
