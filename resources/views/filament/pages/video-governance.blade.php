<x-filament-panels::page>
    @if (! $this->hasSelectedCompany())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Select a single company from the switcher above to manage its Video Generation governance.') }}
            </p>
        </x-filament::section>
    @else
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}
        </form>

        @php($usage = $this->usage())

        <x-filament::section
            icon="heroicon-o-banknotes"
            :heading="__('This month\'s usage')"
            :description="__('Calendar month to date. Estimated cost uses each provider profile\'s admin-set approximate cost per video.')"
        >
            <div class="grid grid-cols-2 gap-4 sm:max-w-md">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Videos generated') }}</p>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($usage['videos']) }}</p>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Estimated spend') }}</p>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($usage['cost'], 2) }}</p>
                </div>
            </div>

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                @foreach ([
                    ['rows' => $usage['by_user'], 'heading' => __('By person'), 'column' => __('Person'), 'key' => 'name'],
                    ['rows' => $usage['by_provider'], 'heading' => __('By provider'), 'column' => __('Provider'), 'key' => 'label'],
                ] as $table)
                    <div>
                        <h4 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ $table['heading'] }}</h4>
                        @if (empty($table['rows']))
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No videos yet this month.') }}</p>
                        @else
                            <table class="w-full text-sm">
                                <thead class="text-xs text-gray-500 dark:text-gray-400">
                                    <tr>
                                        <th class="py-1 text-left font-medium">{{ $table['column'] }}</th>
                                        <th class="py-1 text-right font-medium">{{ __('Videos') }}</th>
                                        <th class="py-1 text-right font-medium">{{ __('Est. cost') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($table['rows'] as $row)
                                        <tr class="border-t border-gray-100 dark:border-white/5">
                                            <td class="py-1.5">{{ $row[$table['key']] }}</td>
                                            <td class="py-1.5 text-right">{{ number_format($row['videos']) }}</td>
                                            <td class="py-1.5 text-right">{{ number_format($row['cost'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
