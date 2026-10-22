<x-filament-widgets::widget class="ims-widget">
    @php
        $rows = $this->rows();
        $currency = $this->currency();
        $records = \App\Filament\Resources\InvestmentRecords\InvestmentRecordResource::class;
        $pending = \App\Filament\Ims\Office\Pages\PendingMoney::class;
    @endphp

    <section class="ims-card" aria-labelledby="ims-latest-money">
        <div class="ims-card-head">
            <div class="min-w-0 flex-1">
                <h2 id="ims-latest-money" class="ims-card-title">{{ __('Latest money in') }}</h2>
                <p class="ims-kpi-note">{{ __('The newest money from investors') }}</p>
            </div>
            @if ($records::canAccess())
                <a href="{{ $records::getUrl(panel: 'office') }}" class="ims-arrow-btn" aria-label="{{ __('Open investments') }}">
                    <x-filament::icon icon="heroicon-o-arrow-up-right" class="h-5 w-5" />
                </a>
            @endif
        </div>

        @if ($rows === [])
            <div class="ims-empty">
                <p>{{ __('No money has been recorded yet.') }}</p>
                @if ($records::canAccess())
                    <a href="{{ $records::getUrl(panel: 'office') }}" class="ims-btn ims-btn-ghost">{{ __('Open investments') }}</a>
                @endif
            </div>
        @else
            <table class="ims-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Name') }}</th>
                        <th scope="col">{{ __('Date') }}</th>
                        <th scope="col">{{ __('Where') }}</th>
                        <th scope="col">{{ __('Status') }}</th>
                        <th scope="col" class="ims-num">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td data-label="{{ __('Name') }}">
                                <span class="ims-person">
                                    <x-ims.avatar :name="$row['name']" size="sm" />
                                    <span class="ims-person-name">{{ $row['name'] }}</span>
                                </span>
                            </td>
                            <td data-label="{{ __('Date') }}" class="ims-nowrap">{{ \App\Support\ImsNumbers::date($row['date']) }}</td>
                            <td data-label="{{ __('Where') }}" class="ims-muted">{{ $row['where'] }}</td>
                            <td data-label="{{ __('Status') }}">
                                @if ($row['waiting'])
                                    @if ($pending::canAccess())
                                        <a href="{{ $pending::getUrl(panel: 'office') }}" class="ims-status ims-status-warning"><span class="ims-status-dot" aria-hidden="true"></span>{{ __('Waiting for the money') }}</a>
                                    @else
                                        <span class="ims-status ims-status-warning"><span class="ims-status-dot" aria-hidden="true"></span>{{ __('Waiting for the money') }}</span>
                                    @endif
                                @else
                                    <span class="ims-status ims-status-success"><span class="ims-status-dot" aria-hidden="true"></span>{{ __('Received') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('Amount') }}" class="ims-num"><x-ims.money :amount="$row['amount']" :currency="$currency" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-filament-widgets::widget>
