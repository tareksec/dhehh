{{--
    Money lines in the IMS style (IMS-V2/DESIGN.md §7 TransactionList): a round
    icon for the kind of line, what it was and where, the date, and the amount —
    money in green with "+", money out with "−". Used on the portal home and
    the Statement page.
--}}
@php
    $kinds = [
        'deposit' => ['heroicon-o-arrow-down-tray', 'ims-tx-icon-in'],
        'profit' => ['heroicon-o-arrow-trending-up', 'ims-tx-icon-in'],
        'reinvest' => ['heroicon-o-arrow-path', 'ims-tx-icon-neutral'],
        'payout' => ['heroicon-o-banknotes', 'ims-tx-icon-out'],
        'withdrawal' => ['heroicon-o-arrow-up-tray', 'ims-tx-icon-out'],
        'capital_return' => ['heroicon-o-arrow-uturn-left', 'ims-tx-icon-out'],
        'loss' => ['heroicon-o-arrow-trending-down', 'ims-tx-icon-loss'],
    ];
@endphp

@if ($entries->isEmpty())
    <div class="ims-empty">
        <x-filament::icon icon="heroicon-o-document-text" class="h-10 w-10" />
        <p>{{ __('Nothing to show yet.') }}</p>
    </div>
@else
    <ul class="ims-tx-list">
        @foreach ($entries as $entry)
            @php [$icon, $tone] = $kinds[$entry['type']] ?? ['heroicon-o-document-text', 'ims-tx-icon-neutral']; @endphp
            <li class="ims-tx">
                <span class="ims-tx-icon {{ $tone }}" aria-hidden="true"><x-filament::icon :icon="$icon" class="h-5 w-5" /></span>
                <span class="ims-tx-text">
                    <span class="ims-tx-label">{{ $entry['label'] }}</span>
                    <span class="ims-tx-meta">{{ $entry['project'] }}@if (($named ?? false) && filled($entry['company_name'] ?? null)) · {{ $entry['company_name'] }}@endif</span>
                </span>
                <span class="ims-tx-side">
                    <span @class([
                        'ims-tx-amount',
                        'ims-tx-amount-in' => $entry['info_amount'] === null && $entry['amount'] > 0,
                        'ims-tx-amount-loss' => $entry['type'] === 'loss',
                    ])>
                        @if ($entry['info_amount'] !== null)
                            <x-ims.money :amount="$entry['info_amount']" :currency="$entry['currency'] ?? $currency" />
                        @else
                            <x-ims.money :amount="$entry['amount']" :currency="$entry['currency'] ?? $currency" :signed="true" />
                        @endif
                    </span>
                    <span class="ims-tx-meta">{{ \App\Support\ImsNumbers::date($entry['date']) }}</span>
                </span>
            </li>
        @endforeach
    </ul>
@endif
