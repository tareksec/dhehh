<x-filament-panels::page>
    @php
        $steps = $this->steps();
        $done = collect($steps)->where('done', true)->count();
        $total = count($steps);
        $percent = $total > 0 ? (int) round($done / $total * 100) : 0;
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Setup progress') }}</p>
                    <p class="mt-1 text-3xl font-semibold tracking-tight">{{ __(':done of :total steps done', ['done' => $done, 'total' => $total]) }}</p>
                </div>
                <p class="text-2xl font-semibold">{{ $percent }}%</p>
            </div>
            <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                <div class="h-2 rounded-full bg-primary-600" style="width: {{ $percent }}%"></div>
            </div>
            @if ($total > 0 && $done === $total)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('Everything is ready. The AI can talk to real customers.') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            @foreach ($steps as $step)
                <div class="flex items-start gap-3 py-3 {{ ! $loop->first ? 'border-t border-gray-200 dark:border-white/10' : '' }}" wire:key="setup-step-{{ $step['key'] }}">
                    <div class="mt-0.5 shrink-0">
                        @if ($step['done'])
                            <x-filament::icon icon="heroicon-m-check-circle" class="h-6 w-6 text-success-600 dark:text-success-400" />
                        @else
                            <x-filament::icon icon="heroicon-o-minus-circle" class="h-6 w-6 text-gray-400" />
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">{{ $step['title'] }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $step['hint'] }}</p>
                        @if ($step['detail'])
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</p>
                        @endif
                    </div>
                    @if ($step['url'])
                        <x-filament::button tag="a" :href="$step['url']" size="sm" :color="$step['done'] ? 'gray' : 'primary'" :outlined="$step['done']">
                            {{ $step['done'] ? __('Open') : __('Set up') }}
                        </x-filament::button>
                    @endif
                </div>
            @endforeach
        </x-filament::section>
    </div>
</x-filament-panels::page>
