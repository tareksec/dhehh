@php
    use App\Support\MoneyFormatter;

    $rows = $this->rows();
    $totals = $this->totals($rows);
    $figures = [
        'capital_at_work' => __('Capital at work'),
        'total_invested' => __('Total invested'),
        'profit_received' => __('Profit received'),
        'profit_pending' => __('Profit still to be paid'),
        'commission_due' => __('Commission due'),
    ];
    $shown = fn (array $row, string $key): bool => $key === 'commission_due' ? $row['partner'] : $row['investor'];
@endphp

<x-filament-panels::page>
    {{-- All businesses added up, one card per currency. --}}
    @foreach ($totals as $currency => $sum)
        <x-filament::section :heading="count($totals) > 1 ? __('Total') . ' (' . $currency . ')' : __('Total')">
            <dl class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                @foreach ($figures as $key => $label)
                    @if ($key !== 'commission_due' || $sum['commission_due'] > 0)
                        <div>
                            <dt class="text-xs text-gray-500">{{ $label }}</dt>
                            <dd class="text-lg font-semibold">{{ MoneyFormatter::currency($sum[$key], $currency) }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </x-filament::section>
    @endforeach

    {{-- One card per business. --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($rows as $row)
            <x-filament::section>
                <x-slot name="heading">
                    {{ $row['name'] }}
                    @if ($row['current'])
                        <x-filament::badge color="success" class="ms-2 inline-flex">{{ __('Open now') }}</x-filament::badge>
                    @endif
                </x-slot>

                <dl class="grid grid-cols-2 gap-4">
                    @foreach ($figures as $key => $label)
                        @if ($shown($row, $key))
                            <div>
                                <dt class="text-xs text-gray-500">{{ $label }}</dt>
                                <dd class="font-semibold">{{ MoneyFormatter::currency($row[$key], $row['currency']) }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>

                @unless ($row['current'])
                    <form method="POST" action="{{ route('filament.investor.switch-business', ['company' => $row['company_id']]) }}" class="mt-4">
                        @csrf
                        <x-filament::button type="submit" icon="heroicon-o-arrow-right-circle">
                            {{ __('Open this business') }}
                        </x-filament::button>
                    </form>
                @endunless
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
