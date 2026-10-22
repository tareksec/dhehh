{{--
    The Trade Portal home: four big cards (BUILD-BRIEF.md Phase 1). A card
    whose figures could not be read ($x === null, caught by ModuleGuard) says
    so on its own; the other cards still show.
--}}
@php
    use App\Support\ImsNumbers;
    use Carbon\CarbonImmutable;
@endphp

<x-filament-panels::page>
    <p class="trade-date">{{ ImsNumbers::date(CarbonImmutable::now()) }}</p>

    <div class="trade-cards">
        {{-- Today's sales --}}
        <section class="trade-card" aria-labelledby="trade-card-sales">
            <div class="trade-card-head">
                <span class="trade-card-icon trade-tone-blue"><x-filament::icon icon="heroicon-o-banknotes" class="h-6 w-6" /></span>
                <h2 id="trade-card-sales" class="trade-card-title">{{ __("Today's sales") }}</h2>
            </div>
            @if ($sales === null)
                <p class="trade-card-error">{{ __('This card could not be loaded. Please try again later.') }}</p>
            @elseif ($sales['count'] === 0)
                <p class="trade-card-big">{{ ImsNumbers::money(0) }}</p>
                <p class="trade-card-empty">{{ __('No sale yet today.') }}</p>
            @else
                <p class="trade-card-big">{{ ImsNumbers::money($sales['total']) }}</p>
                <p class="trade-card-note">{{ __(':count sales today', ['count' => ImsNumbers::count($sales['count'])]) }}</p>
            @endif
        </section>

        {{-- Money due from customers --}}
        <section class="trade-card" aria-labelledby="trade-card-dues">
            <div class="trade-card-head">
                <span class="trade-card-icon trade-tone-amber"><x-filament::icon icon="heroicon-o-clock" class="h-6 w-6" /></span>
                <h2 id="trade-card-dues" class="trade-card-title">{{ __('Dues (to collect)') }}</h2>
            </div>
            @if ($dues === null)
                <p class="trade-card-error">{{ __('This card could not be loaded. Please try again later.') }}</p>
            @elseif ($dues['count'] === 0)
                <p class="trade-card-big">{{ ImsNumbers::money(0) }}</p>
                <p class="trade-card-empty">{{ __('No customer owes you money.') }}</p>
            @else
                <p class="trade-card-big">{{ ImsNumbers::money($dues['total']) }}</p>
                <p class="trade-card-note">{{ __(':count customers have dues', ['count' => ImsNumbers::count($dues['count'])]) }}</p>
                <ul class="trade-list">
                    @foreach ($dues['customers'] as $customer)
                        <li><span>{{ $customer['name'] }}</span><strong>{{ ImsNumbers::money($customer['due']) }}</strong></li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Low stock --}}
        <section class="trade-card" aria-labelledby="trade-card-stock">
            <div class="trade-card-head">
                <span class="trade-card-icon trade-tone-red"><x-filament::icon icon="heroicon-o-archive-box-x-mark" class="h-6 w-6" /></span>
                <h2 id="trade-card-stock" class="trade-card-title">{{ __('Low stock') }}</h2>
            </div>
            @if ($lowStock === null)
                <p class="trade-card-error">{{ __('This card could not be loaded. Please try again later.') }}</p>
            @elseif ($lowStock['count'] === 0)
                <p class="trade-card-empty">{{ __('Stock is fine. Nothing is running low.') }}</p>
            @else
                <p class="trade-card-big">{{ ImsNumbers::count($lowStock['count']) }}</p>
                <p class="trade-card-note">{{ __('items at or below their reorder level') }}</p>
                <ul class="trade-list">
                    @foreach ($lowStock['products'] as $product)
                        <li><span>{{ $product['name'] }}</span><strong>{{ __(':stock left', ['stock' => ImsNumbers::digits(ImsNumbers::lakh($product['stock'], 0))]) }}</strong></li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- LC files still open --}}
        <section class="trade-card" aria-labelledby="trade-card-lc">
            <div class="trade-card-head">
                <span class="trade-card-icon trade-tone-green"><x-filament::icon icon="heroicon-o-truck" class="h-6 w-6" /></span>
                <h2 id="trade-card-lc" class="trade-card-title">{{ __('LC files') }}</h2>
            </div>
            @if ($lcs === null)
                <p class="trade-card-error">{{ __('This card could not be loaded. Please try again later.') }}</p>
            @elseif ($lcs['count'] === 0)
                <p class="trade-card-empty">{{ __('No LC is open. Goods of every LC have arrived.') }}</p>
            @else
                <p class="trade-card-big">{{ ImsNumbers::count($lcs['count']) }}</p>
                <p class="trade-card-note">{{ __('LCs whose goods have not arrived yet') }}</p>
                <ul class="trade-list">
                    @foreach ($lcs['files'] as $file)
                        <li>
                            <span>
                                {{ __('LC :number', ['number' => $file['lc']]) }}
                                @if ($file['supplier'])<small>{{ $file['supplier'] }}</small>@endif
                            </span>
                            <strong class="trade-stage">{{ $file['stage'] }}</strong>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-filament-panels::page>
