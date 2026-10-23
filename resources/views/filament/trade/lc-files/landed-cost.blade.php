{{-- The landed cost worksheet (LandedCost): goods in Taka, the cost lines, the total and per unit, per item. --}}
@php
    use App\Models\LandedCostLine;
    use App\Support\ImsNumbers;

    $file = $this->file();
@endphp

<x-filament-panels::page>
    <div class="trade-cards">
        <section class="trade-card" aria-labelledby="trade-lc-total">
            <h2 id="trade-lc-total" class="trade-card-title">{{ __('Landed cost per unit') }}</h2>
            <p class="trade-card-big">{{ ImsNumbers::money($sheet['per_unit']) }}</p>
            <p class="trade-card-note">{{ __('Total :total for :quantity units', ['total' => ImsNumbers::money($sheet['total']), 'quantity' => ImsNumbers::count($sheet['quantity'])]) }}</p>
            @if ($file->landed_cost_applied_at)
                <p class="trade-card-note">{{ __('Applied to the buying price on :date', ['date' => ImsNumbers::date($file->landed_cost_applied_at)]) }}</p>
            @endif
        </section>

        <section class="trade-card" aria-labelledby="trade-lc-sheet">
            <h2 id="trade-lc-sheet" class="trade-card-title">{{ __('Worksheet') }}</h2>
            <ul class="trade-list">
                <li>
                    <span>
                        {{ __('Goods (CFR)') }}
                        <small>
                            @if ($sheet['goods_from'] === 'rate')
                                $ {{ number_format($sheet['goods_usd'], 2) }} × {{ rtrim(rtrim(number_format($sheet['rate'], 4, '.', ''), '0'), '.') }}
                            @else
                                {{ __('From the purchase lines. Set the bank rate to use the LC value.') }}
                            @endif
                        </small>
                    </span>
                    <strong>{{ ImsNumbers::money($sheet['goods_bdt']) }}</strong>
                </li>
                @foreach ($sheet['lines'] as $line)
                    <li>
                        <span>
                            {{ $line->displayLabel() }}
                            <small>{{ collect([LandedCostLine::sourceLabel($line->source), $line->paid_on ? ImsNumbers::date($line->paid_on) : null, $line->note])->filter()->implode(' · ') }}</small>
                            <span>{{ ($this->deleteLineAction)(['line' => $line->getKey()]) }}</span>
                        </span>
                        <strong>{{ ImsNumbers::money($line->amount) }}</strong>
                    </li>
                @endforeach
                @if ($sheet['lines'] === [])
                    <li><span class="trade-card-empty">{{ __('No cost yet. Add duty, insurance, C&F, truck fare and the others as the figures come.') }}</span></li>
                @endif
                <li><span>{{ __('Costs') }}</span><strong>{{ ImsNumbers::money($sheet['costs']) }}</strong></li>
                <li><span><strong>{{ __('Total landed cost') }}</strong></span><strong class="trade-card-big-sm">{{ ImsNumbers::money($sheet['total']) }}</strong></li>
            </ul>
        </section>

        @if (count($sheet['items']) > 0)
            <section class="trade-card" aria-labelledby="trade-lc-items">
                <h2 id="trade-lc-items" class="trade-card-title">{{ __('Per item') }}</h2>
                <ul class="trade-list">
                    @foreach ($sheet['items'] as $item)
                        <li>
                            <span>
                                {{ $item['product'] }}
                                <small>{{ __(':quantity units · :share% of the total · buying price now :current', ['quantity' => ImsNumbers::count($item['quantity']), 'share' => ImsNumbers::digits((string) $item['share']), 'current' => ImsNumbers::money($item['current_cost'])]) }}</small>
                            </span>
                            <strong>{{ ImsNumbers::money($item['landed_unit']) }}</strong>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-filament-panels::page>
