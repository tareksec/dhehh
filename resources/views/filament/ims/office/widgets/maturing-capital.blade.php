<x-filament-widgets::widget>
    @php $rows = $this->rows(); @endphp

    <x-filament::section :heading="__('Matured or maturing in 30 days')" icon="heroicon-o-calendar-days">
        @if ($rows === [])
            <p class="text-sm text-gray-600">{{ __('Nothing matures in the next 30 days.') }}</p>
        @else
            <ul>
                @foreach ($rows as $row)
                    <li class="ims-todo-item">
                        <span class="min-w-0">
                            <span class="font-semibold">{{ $row['name'] }}</span>
                            <span class="text-sm text-gray-600"> · {{ $row['place'] }}</span>
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="font-semibold">{{ $row['amount'] }}</span>
                            <x-filament::badge :color="$row['overdue'] ? 'danger' : ($row['soon'] ? 'warning' : 'gray')">
                                {{ $row['overdue'] ? __('Due since :date', ['date' => $row['date']]) : __('Matures :date', ['date' => $row['date']]) }}
                            </x-filament::badge>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
