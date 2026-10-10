@php
    use App\Support\MoneyFormatter;
@endphp

@if ($entries->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing to show yet.') }}</p>
@else
    <div class="divide-y divide-gray-200 dark:divide-white/10">
        @foreach ($entries as $entry)
            <div class="flex items-start justify-between gap-3 py-2 text-sm">
                <div>
                    <div class="font-medium">{{ $entry['label'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $entry['date']->format('d M Y') }} · {{ $entry['project'] }}</div>
                </div>
                <div @class([
                    'whitespace-nowrap font-semibold',
                    'text-success-600 dark:text-success-400' => $entry['type'] === 'profit',
                    'text-danger-600 dark:text-danger-400' => $entry['type'] === 'loss',
                ])>
                    @if ($entry['info_amount'] !== null)
                        {{ MoneyFormatter::currency($entry['info_amount'], $currency) }}
                    @else
                        {{ $entry['amount'] > 0 ? '+' : '−' }}{{ MoneyFormatter::currency(abs($entry['amount']), $currency) }}
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
