<x-filament-panels::page>
    @php
        $report = $this->report();
        $cards = [
            ['label' => __('Chats with customer messages'), 'value' => number_format($report['chats']), 'hint' => null],
            ['label' => __('Handled by the AI alone'), 'value' => $report['ai_only_percent'] === null ? '—' : $report['ai_only_percent'].'%', 'hint' => __(':count chats', ['count' => $report['ai_only']])],
            ['label' => __('AI replies'), 'value' => number_format($report['ai_replies']), 'hint' => __(':count team replies', ['count' => $report['staff_replies']])],
            ['label' => __('AI handed over'), 'value' => number_format($report['handoffs']), 'hint' => __(':count taken over by the team', ['count' => $report['takeovers']])],
            ['label' => __('Back to AI on its own'), 'value' => number_format($report['auto_returns']), 'hint' => null],
            ['label' => __('Average first reply'), 'value' => $this->minutes($report['avg_response_minutes']), 'hint' => __('Median :time', ['time' => $this->minutes($report['median_response_minutes'])])],
            ['label' => __('Late'), 'value' => $report['late_percent'] === null ? '—' : $report['late_percent'].'%', 'hint' => $report['late_percent'] === null ? __('No reply target set') : __(':count chats', ['count' => $report['late']])],
            ['label' => __('Never answered'), 'value' => number_format($report['never_answered']), 'hint' => null],
            ['label' => __('Waiting now'), 'value' => number_format($report['waiting_now']), 'hint' => $report['longest_wait_minutes'] === null ? null : __('Longest :time', ['time' => $this->minutes($report['longest_wait_minutes'])])],
        ];
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap gap-2">
            @foreach ($this->periods() as $key => $label)
                <x-filament::button
                    size="sm"
                    :color="$period === (string) $key ? 'primary' : 'gray'"
                    :outlined="$period !== (string) $key"
                    wire:click="setPeriod('{{ $key }}')"
                    wire:key="ai-report-period-{{ $key }}"
                >
                    {{ $label }}
                </x-filament::button>
            @endforeach
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($cards as $card)
                <x-filament::section>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                    <p class="mt-1 text-3xl font-semibold tracking-tight">{{ $card['value'] }}</p>
                    @if ($card['hint'])
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $card['hint'] }}</p>
                    @endif
                </x-filament::section>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section :heading="__('Why the AI handed over')">
                @forelse ($report['reasons'] as $row)
                    <div class="flex items-center justify-between gap-4 py-1.5 text-sm {{ ! $loop->first ? 'border-t border-gray-200 dark:border-white/10' : '' }}">
                        <span>{{ $this->reasonLabel($row['reason']) }}</span>
                        <span class="font-semibold">{{ number_format($row['total']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('The AI did not hand over any chat in this period.') }}</p>
                @endforelse
            </x-filament::section>

            <x-filament::section :heading="__('By channel')">
                @if ($report['channels']->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No customer messages in this period.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <th class="py-1.5 pe-3 font-medium">{{ __('Channel') }}</th>
                                    <th class="py-1.5 pe-3 font-medium text-right">{{ __('Chats') }}</th>
                                    <th class="py-1.5 pe-3 font-medium text-right">{{ __('Handed over') }}</th>
                                    <th class="py-1.5 pe-3 font-medium text-right">{{ __('Average first reply') }}</th>
                                    <th class="py-1.5 font-medium text-right">{{ __('Late') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['channels'] as $row)
                                    <tr class="border-t border-gray-200 dark:border-white/10">
                                        <td class="py-1.5 pe-3">{{ $row['name'] }} <span class="text-xs text-gray-500 dark:text-gray-400">{{ ucfirst($row['provider']) }}</span></td>
                                        <td class="py-1.5 pe-3 text-right">{{ number_format($row['chats']) }}</td>
                                        <td class="py-1.5 pe-3 text-right">{{ number_format($row['handoffs']) }}</td>
                                        <td class="py-1.5 pe-3 text-right">{{ $this->minutes($row['avg_response_minutes']) }}</td>
                                        <td class="py-1.5 text-right">{{ number_format($row['late']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('First reply time counts from the moment the AI handed the chat over until a person first answered it.') }}
        </p>
    </div>
</x-filament-panels::page>
