<x-filament-widgets::widget class="ims-widget">
    @php $f = $this->figures(); @endphp

    <div class="ims-stack">
        <section class="ims-balance-card" aria-labelledby="ims-capital-at-work">
            <div class="ims-card-head">
                <div>
                    <h2 id="ims-capital-at-work" class="ims-card-title">{{ __('Capital at work now') }}</h2>
                    <p class="ims-label">{{ __('Money invested right now, after losses and money paid back') }}</p>
                </div>
                @if (\App\Filament\Resources\InvestmentRecords\InvestmentRecordResource::canAccess())
                    <a href="{{ \App\Filament\Resources\InvestmentRecords\InvestmentRecordResource::getUrl(panel: 'office') }}" class="ims-arrow-btn ims-arrow-btn-on-green" aria-label="{{ __('Open investments') }}">
                        <x-filament::icon icon="heroicon-o-arrow-up-right" class="h-5 w-5" />
                    </a>
                @endif
            </div>

            <div class="ims-balance-figure">
                <x-ims.money :amount="$f['capital']" :currency="$f['currency']" />
            </div>

            <div class="ims-balance-foot">
                <span>{{ __('Working in groups and projects') }}</span>
                <x-filament::icon icon="heroicon-o-banknotes" class="h-6 w-6" />
            </div>
        </section>

        <section class="ims-card ims-kpi" aria-labelledby="ims-came-in">
            <h2 id="ims-came-in" class="ims-kpi-label">{{ __('Came in this month') }}</h2>
            <div class="ims-kpi-row">
                <x-ims.money class="ims-kpi-value" :amount="$f['received']" :currency="$f['currency']" />
                @if ($f['change'] !== null)
                    <span @class(['ims-pill', 'ims-pill-up' => $f['change'] >= 0, 'ims-pill-down' => $f['change'] < 0])>
                        {{ $f['change'] >= 0 ? '+' : '−' }}{{ \App\Support\ImsNumbers::percent(abs($f['change'])) }}
                    </span>
                @endif
            </div>
            <p class="ims-kpi-note">
                @if ($f['change'] !== null)
                    {{ __('Compared with last month (:amount)', ['amount' => \App\Support\ImsNumbers::money($f['received_before'], $f['currency'])]) }}
                @else
                    {{ __('Confirmed money from investors') }}
                @endif
            </p>
        </section>
    </div>
</x-filament-widgets::widget>
