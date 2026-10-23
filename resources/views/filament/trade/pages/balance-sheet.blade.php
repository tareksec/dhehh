{{-- The simple balance sheet (BalanceSheet): what the business has, what it owes, what is left — today. --}}
@php
    use App\Support\ImsNumbers;
    use Carbon\CarbonImmutable;
@endphp

<x-filament-panels::page>
    <p class="trade-date">{{ __('Today, :date', ['date' => ImsNumbers::date(CarbonImmutable::now())]) }}</p>

    <div class="trade-cards">
        <section class="trade-card" aria-labelledby="trade-bs-have">
            <h2 id="trade-bs-have" class="trade-card-title">{{ __('What the business has') }}</h2>
            <ul class="trade-list">
                <li><span>{{ __('Cash in hand') }}</span><strong>{{ ImsNumbers::money($sheet['cash']) }}</strong></li>
                <li><span>{{ __('In the bank and mobile banking') }}</span><strong>{{ ImsNumbers::money($sheet['bank']) }}</strong></li>
                <li><span>{{ __('Stock') }}<small>{{ __('at buying price') }}</small></span><strong>{{ ImsNumbers::money($sheet['stock']) }}</strong></li>
                <li><span>{{ __('Buyers owe you') }}</span><strong>{{ ImsNumbers::money($sheet['receivable']) }}</strong></li>
                <li><span><strong>{{ __('Total') }}</strong></span><strong>{{ ImsNumbers::money($sheet['assets']) }}</strong></li>
            </ul>
        </section>

        <section class="trade-card" aria-labelledby="trade-bs-owe">
            <h2 id="trade-bs-owe" class="trade-card-title">{{ __('What the business owes') }}</h2>
            <ul class="trade-list">
                <li><span>{{ __('To suppliers') }}</span><strong class="trade-out">{{ ImsNumbers::money($sheet['payable']) }}</strong></li>
            </ul>
        </section>

        <section class="trade-card" aria-labelledby="trade-bs-left">
            <h2 id="trade-bs-left" class="trade-card-title">{{ __('What is left (your capital)') }}</h2>
            <p class="trade-card-big">{{ ImsNumbers::money($sheet['net']) }}</p>
            <p class="trade-card-note">{{ __('What the business has, less what it owes. Bank loans and the LC margin are not in the system yet.') }}</p>
        </section>
    </div>
</x-filament-panels::page>
