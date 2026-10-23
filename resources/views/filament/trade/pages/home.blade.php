{{--
    The Trade Portal home, laid out like a phone app (owner's reference,
    2026-10-23): a greeting, today's sales on a big navy card, "At a glance"
    with dues / cash and bank / low stock / open LCs, the daily jobs as round
    buttons, then short lists of who owes money, what is running low and the
    open LC files. A figure that could not be read ($x === null, caught by
    ModuleGuard) says so on its own; the rest still shows.
--}}
@php
    use App\Support\ImsNumbers;
    use Carbon\CarbonImmutable;

    $error = __('This card could not be loaded. Please try again later.');
@endphp

<x-filament-panels::page>
    <div class="trade-home">
        <header class="trade-hello">
            <p class="trade-hello-greet">{{ $greeting }},</p>
            <h1 class="trade-hello-name">{{ $name }}</h1>
        </header>

        <div class="trade-home-top">
            {{-- Today's sales --}}
            <section class="trade-hero" aria-labelledby="trade-card-sales">
                <x-filament::icon icon="heroicon-o-banknotes" class="trade-hero-art" aria-hidden="true" />
                <h2 id="trade-card-sales" class="trade-hero-label">{{ __("Today's sales") }}</h2>
                @if ($sales === null)
                    <p class="trade-hero-note">{{ $error }}</p>
                @else
                    <p class="trade-hero-big">{{ ImsNumbers::money($sales['total'] ?? 0) }}</p>
                    <p class="trade-hero-note">{{ $sales['count'] === 0 ? __('No sale yet today.') : __(':count sales today', ['count' => ImsNumbers::count($sales['count'])]) }}</p>
                @endif
                <p class="trade-hero-date">{{ ImsNumbers::date(CarbonImmutable::now()) }}</p>

                <div class="trade-hero-actions">
                    @if ($links['newSale'])
                        <a href="{{ $links['newSale'] }}" class="trade-hero-btn">
                            <x-filament::icon icon="heroicon-m-plus" class="h-5 w-5" />
                            {{ __('New challan') }}
                        </a>
                    @endif
                    @if ($links['sales'])
                        <a href="{{ $links['sales'] }}" class="trade-go trade-go-light" aria-label="{{ __('See all') }}: {{ __("Today's sales") }}">
                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-5 w-5" />
                        </a>
                    @endif
                </div>
            </section>

            {{-- The other figures, side by side --}}
            <section class="trade-glance" aria-labelledby="trade-glance-title">
                <h2 id="trade-glance-title" class="trade-section-title">{{ __('At a glance') }}</h2>
                <div class="trade-glance-grid">
                    <a @if ($links['dues']) href="{{ $links['dues'] }}" @endif class="trade-glance-item">
                        <span class="trade-glance-head"><span>{{ __('Dues (to collect)') }}</span><x-filament::icon icon="heroicon-o-clock" class="trade-glance-icon trade-tone-amber" /></span>
                        @if ($dues === null)
                            <small>{{ $error }}</small>
                        @else
                            <strong>{{ ImsNumbers::money($dues['total'] ?? 0) }}</strong>
                            <small>{{ $dues['count'] === 0 ? __('No customer owes you money.') : __(':count customers have dues', ['count' => ImsNumbers::count($dues['count'])]) }}</small>
                        @endif
                    </a>

                    @if ($links['money'])
                        <a href="{{ $links['money'] }}" class="trade-glance-item">
                            <span class="trade-glance-head"><span>{{ __('Cash and bank now') }}</span><x-filament::icon icon="heroicon-o-wallet" class="trade-glance-icon trade-tone-green" /></span>
                            @if ($money === null)
                                <small>{{ $error }}</small>
                            @elseif ($money['accounts'] === 0)
                                <small>{{ __('Add your cash box and bank accounts first, with the money in each today.') }}</small>
                            @else
                                <strong>{{ ImsNumbers::money($money['cash'] + $money['bank']) }}</strong>
                                <small>{{ __('Cash in hand') }} {{ ImsNumbers::money($money['cash']) }}</small>
                            @endif
                        </a>
                    @endif

                    <a @if ($links['stock']) href="{{ $links['stock'] }}" @endif class="trade-glance-item">
                        <span class="trade-glance-head"><span>{{ __('Low stock') }}</span><x-filament::icon icon="heroicon-o-archive-box-x-mark" class="trade-glance-icon trade-tone-red" /></span>
                        @if ($lowStock === null)
                            <small>{{ $error }}</small>
                        @else
                            <strong>{{ ImsNumbers::count($lowStock['count']) }}</strong>
                            <small>{{ $lowStock['count'] === 0 ? __('Stock is fine. Nothing is running low.') : __('items at or below their reorder level') }}</small>
                        @endif
                    </a>

                    <a @if ($links['lcs']) href="{{ $links['lcs'] }}" @endif class="trade-glance-item">
                        <span class="trade-glance-head"><span>{{ __('LC files') }}</span><x-filament::icon icon="heroicon-o-truck" class="trade-glance-icon trade-tone-blue" /></span>
                        @if ($lcs === null)
                            <small>{{ $error }}</small>
                        @else
                            <strong>{{ ImsNumbers::count($lcs['count']) }}</strong>
                            <small>{{ $lcs['count'] === 0 ? __('No LC is open. Goods of every LC have arrived.') : __('LCs whose goods have not arrived yet') }}</small>
                        @endif
                    </a>
                </div>
            </section>
        </div>

        {{-- The daily jobs --}}
        @if ($quick !== [])
            <section class="trade-panel" aria-labelledby="trade-quick-title">
                <h2 id="trade-quick-title" class="trade-section-title">{{ __('Quick jobs') }}</h2>
                <nav class="trade-quick" aria-labelledby="trade-quick-title">
                    @foreach ($quick as $action)
                        <a href="{{ $action['url'] }}" class="trade-quick-btn">
                            <span class="trade-quick-icon"><x-filament::icon :icon="$action['icon']" class="h-6 w-6" /></span>
                            <span>{{ $action['label'] }}</span>
                        </a>
                    @endforeach
                </nav>
            </section>
        @endif

        <div class="trade-cards">
            {{-- Who owes money --}}
            @if ($dues !== null && $dues['count'] > 0)
                <section class="trade-card" aria-labelledby="trade-card-dues">
                    <div class="trade-card-head">
                        <h2 id="trade-card-dues" class="trade-card-title">{{ __('Dues (to collect)') }}</h2>
                        @if ($links['dues'])<a href="{{ $links['dues'] }}" class="trade-card-link">{{ __('See all') }}<x-filament::icon icon="heroicon-m-arrow-right" class="h-4 w-4" /></a>@endif
                    </div>
                    <ul class="trade-list">
                        @foreach ($dues['customers'] as $customer)
                            <li><span>{{ $customer['name'] }}</span><strong class="trade-due">{{ ImsNumbers::money($customer['due']) }}</strong></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- What is running low --}}
            @if ($lowStock !== null && $lowStock['count'] > 0)
                <section class="trade-card" aria-labelledby="trade-card-stock">
                    <div class="trade-card-head">
                        <h2 id="trade-card-stock" class="trade-card-title">{{ __('Low stock') }}</h2>
                        @if ($links['stock'])<a href="{{ $links['stock'] }}" class="trade-card-link">{{ __('See all') }}<x-filament::icon icon="heroicon-m-arrow-right" class="h-4 w-4" /></a>@endif
                    </div>
                    <ul class="trade-list">
                        @foreach ($lowStock['products'] as $product)
                            <li><span>{{ $product['name'] }}</span><strong class="trade-out">{{ __(':stock left', ['stock' => ImsNumbers::digits(ImsNumbers::lakh($product['stock'], 0))]) }}</strong></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- LC files still open --}}
            @if ($lcs !== null && $lcs['count'] > 0)
                <section class="trade-card" aria-labelledby="trade-card-lc">
                    <div class="trade-card-head">
                        <h2 id="trade-card-lc" class="trade-card-title">{{ __('LC files') }}</h2>
                        @if ($links['lcs'])<a href="{{ $links['lcs'] }}" class="trade-card-link">{{ __('See all') }}<x-filament::icon icon="heroicon-m-arrow-right" class="h-4 w-4" /></a>@endif
                    </div>
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
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
