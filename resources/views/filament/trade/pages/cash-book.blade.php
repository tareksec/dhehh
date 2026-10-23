{{-- The Trade Portal cash book (CashBook): Day / Month switch, a date with back / forward, then the book. --}}
@php
    use App\Services\TradeCashBookService;
    use App\Support\ImsNumbers;

    $kindLabels = [
        TradeCashBookService::KIND_SALE => __('Sales received'),
        TradeCashBookService::KIND_OLD_DUE => __('Old dues received'),
        TradeCashBookService::KIND_SUPPLIER => __('Paid to suppliers'),
        TradeCashBookService::KIND_COST => __('Costs'),
        TradeCashBookService::KIND_OTHER => __('Other'),
    ];
@endphp

<x-filament-panels::page>
    @if (! $hasAccounts)
        <section class="trade-card">
            <p class="trade-card-empty">{{ __('Add your cash box and bank accounts first, with the money in each today.') }}</p>
            @if ($accountsUrl)
                <div class="trade-actions"><x-filament::button tag="a" :href="$accountsUrl" icon="heroicon-m-plus">{{ __('Cash and bank accounts') }}</x-filament::button></div>
            @endif
        </section>
    @else
        <div class="trade-toolbar">
            <nav class="trade-language" aria-label="{{ __('Show') }}">
                <a href="#" wire:click.prevent="showMode('day')" @if ($mode === 'day') aria-current="true" @endif>{{ __('Day') }}</a>
                <a href="#" wire:click.prevent="showMode('month')" @if ($mode === 'month') aria-current="true" @endif>{{ __('Month') }}</a>
            </nav>
            <div class="trade-stepper">
                <button type="button" class="trade-round-btn" wire:click="step(-1)" aria-label="{{ __('Back') }}"><x-filament::icon icon="heroicon-m-chevron-left" class="h-5 w-5" /></button>
                @if ($mode === 'day')
                    <input type="date" class="trade-date-input" wire:model.live="date" max="{{ now()->toDateString() }}" aria-label="{{ __('Date') }}">
                @else
                    <strong class="trade-month">{{ ImsNumbers::digits($month['start']->translatedFormat('F Y')) }}</strong>
                @endif
                <button type="button" class="trade-round-btn" wire:click="step(1)" aria-label="{{ __('Forward') }}" @disabled($atToday)><x-filament::icon icon="heroicon-m-chevron-right" class="h-5 w-5" /></button>
            </div>
        </div>

        @if ($book)
            <section class="trade-card" aria-label="{{ __('Cash book') }}">
                <ul class="trade-list">
                    <li><span>{{ __('Money at the start of the day') }}</span><strong>{{ ImsNumbers::money($book['opening']) }}</strong></li>
                    @forelse ($book['lines'] as $line)
                        <li @class(['trade-line-move' => $line['kind'] === TradeCashBookService::KIND_MOVE])>
                            <span>
                                {{ $line['label'] }}
                                <small>{{ collect([$line['detail'], $line['account']])->filter()->implode(' · ') }}</small>
                            </span>
                            <strong @class(['trade-in' => $line['direction'] === 'in', 'trade-out' => $line['direction'] === 'out'])>
                                {{ $line['direction'] === 'in' ? '+' : '−' }} {{ ImsNumbers::money($line['amount']) }}
                            </strong>
                        </li>
                    @empty
                        <li><span class="trade-card-empty">{{ __('Nothing was written on this day.') }}</span></li>
                    @endforelse
                    <li><span>{{ __('Money in') }}</span><strong class="trade-in">{{ ImsNumbers::money($book['in']) }}</strong></li>
                    <li><span>{{ __('Money out') }}</span><strong class="trade-out">{{ ImsNumbers::money($book['out']) }}</strong></li>
                    <li><span><strong>{{ __('Money at the end of the day') }}</strong></span><strong class="trade-card-big-sm">{{ ImsNumbers::money($book['closing']) }}</strong></li>
                </ul>
            </section>

            <section class="trade-card" aria-labelledby="trade-cash-accounts">
                <h2 id="trade-cash-accounts" class="trade-card-title">{{ __('In each account at the end of the day') }}</h2>
                <ul class="trade-list">
                    @foreach ($book['accounts'] as $account)
                        <li><span>{{ $account['name'] }}</span><strong>{{ ImsNumbers::money($account['balance']) }}</strong></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($month)
            <section class="trade-card" aria-label="{{ __('Month') }}">
                <ul class="trade-list">
                    <li><span>{{ __('Money at the start of the month') }}</span><strong>{{ ImsNumbers::money($month['opening']) }}</strong></li>
                    @foreach ($kindLabels as $kind => $label)
                        @isset($month['kinds'][$kind])
                            <li>
                                <span>{{ $label }}</span>
                                <strong>
                                    @if ($month['kinds'][$kind]['in'] > 0)<span class="trade-in">+ {{ ImsNumbers::money($month['kinds'][$kind]['in']) }}</span>@endif
                                    @if ($month['kinds'][$kind]['out'] > 0)<span class="trade-out">− {{ ImsNumbers::money($month['kinds'][$kind]['out']) }}</span>@endif
                                </strong>
                            </li>
                        @endisset
                    @endforeach
                    <li><span>{{ __('Money in') }}</span><strong class="trade-in">{{ ImsNumbers::money($month['in']) }}</strong></li>
                    <li><span>{{ __('Money out') }}</span><strong class="trade-out">{{ ImsNumbers::money($month['out']) }}</strong></li>
                    <li><span><strong>{{ __('Money at the end of the month') }}</strong></span><strong class="trade-card-big-sm">{{ ImsNumbers::money($month['closing']) }}</strong></li>
                </ul>
            </section>

            @if ($month['costs'] !== [])
                <section class="trade-card" aria-labelledby="trade-month-costs">
                    <h2 id="trade-month-costs" class="trade-card-title">{{ __('Costs by kind') }}</h2>
                    <ul class="trade-list">
                        @foreach ($month['costs'] as $name => $amount)
                            <li><span>{{ $name }}</span><strong>{{ ImsNumbers::money($amount) }}</strong></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="trade-card" aria-labelledby="trade-month-days">
                <h2 id="trade-month-days" class="trade-card-title">{{ __('Day by day') }}</h2>
                @if ($month['days'] === [])
                    <p class="trade-card-empty">{{ __('Nothing was written in this month.') }}</p>
                @else
                    <ul class="trade-list">
                        @foreach ($month['days'] as $day)
                            <li>
                                <span><a href="#" wire:click.prevent="openDay('{{ $day['date']->toDateString() }}')" class="trade-card-link">{{ ImsNumbers::date($day['date']) }}</a></span>
                                <strong><span class="trade-in">+ {{ ImsNumbers::money($day['in']) }}</span> <span class="trade-out">− {{ ImsNumbers::money($day['out']) }}</span></strong>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    @endif
</x-filament-panels::page>
