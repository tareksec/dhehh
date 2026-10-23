{{--
    The Trade Portal on phones: a floating tab bar at the bottom (the daily
    pages of TradeNavigation, then "Menu"); the open page is a navy pill with
    its name. "Menu" opens a sheet from the bottom with the other pages under
    their headings (TradeNavigation::GROUPS), the language switch, the company
    list and Sign out.
    Hidden from lg up, where the top menu is. If building it fails, nothing is
    drawn and Filament's own menu button stays.
--}}
@php
    use App\Filament\Trade\TradeNavigation;
    use App\Support\ModuleGuard;

    $nav = filament()->auth()->check()
        ? ModuleGuard::run('trade-mobile-nav', function (): array {
            $items = TradeNavigation::items();

            return [
                'tabs' => array_values(array_filter($items, fn (array $item): bool => $item['tab'])),
                'groups' => TradeNavigation::groups($items),
                'moreActive' => collect($items)->contains(fn (array $item): bool => ! $item['tab'] && $item['active']),
                'companies' => TradeNavigation::companies(),
            ];
        })
        : null;
@endphp

@if ($nav)
    <div class="trade-mobile" x-data="{ sheet: false }" x-on:keydown.escape.window="sheet = false">
        <nav class="trade-tabbar" aria-label="{{ __('Quick menu') }}">
            @foreach ($nav['tabs'] as $tab)
                <a href="{{ $tab['url'] }}" class="trade-tabbar-item" @if ($tab['active']) aria-current="page" @endif>
                    <x-filament::icon :icon="$tab['icon']" class="trade-tabbar-icon" />
                    <span>{{ $tab['label'] }}</span>
                </a>
            @endforeach

            <button type="button" class="trade-tabbar-item" x-on:click="sheet = true" x-bind:aria-expanded="sheet" @if ($nav['moreActive']) aria-current="page" @endif>
                <x-filament::icon icon="heroicon-o-squares-2x2" class="trade-tabbar-icon" />
                <span>{{ __('Menu') }}</span>
            </button>
        </nav>

        <div class="trade-sheet-veil" x-show="sheet" x-transition.opacity x-on:click="sheet = false" x-cloak></div>

        <section class="trade-sheet" role="dialog" aria-modal="true" aria-labelledby="trade-sheet-title" x-show="sheet" x-trap="sheet" x-transition:enter="trade-sheet-move" x-transition:enter-start="trade-sheet-off" x-transition:leave="trade-sheet-move" x-transition:leave-end="trade-sheet-off" x-cloak>
            <div class="trade-sheet-head">
                <h2 id="trade-sheet-title" class="trade-sheet-title">{{ __('Menu') }}</h2>
                <button type="button" class="trade-round-btn" x-on:click="sheet = false" aria-label="{{ __('Close') }}"><x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" /></button>
            </div>

            @foreach ($nav['groups'] as $group)
                <div class="trade-sheet-group">
                    <p class="trade-sheet-group-title">{{ $group['label'] }}</p>
                    <div class="trade-tiles">
                        @foreach ($group['items'] as $item)
                            <a href="{{ $item['url'] }}" @class(['trade-tile', 'trade-tile-on' => $item['active']]) @if ($item['active']) aria-current="page" @endif>
                                <span class="trade-tile-icon"><x-filament::icon :icon="$item['icon']" class="h-6 w-6" /></span>
                                <span>{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @include('filament.trade.language-switch', ['sheet' => true])

            @if ($nav['companies'] !== [])
                <nav class="trade-companies" aria-label="{{ __('Choose company') }}">
                    <p class="trade-sheet-group-title">{{ __('Choose company') }}</p>
                    @foreach ($nav['companies'] as $company)
                        <a href="{{ $company['url'] }}" @if ($company['current']) aria-current="true" @endif>
                            <x-filament::icon :icon="$company['current'] ? 'heroicon-o-check-circle' : 'heroicon-o-building-office-2'" class="h-5 w-5" />
                            <span>{{ $company['name'] }}</span>
                        </a>
                    @endforeach
                </nav>
            @endif

            <form method="post" action="{{ filament()->getLogoutUrl() }}" class="trade-signout">
                @csrf
                <button type="submit" class="trade-btn-ghost">
                    <x-filament::icon icon="heroicon-o-arrow-right-start-on-rectangle" class="h-5 w-5" />
                    {{ __('Sign out') }}
                </button>
            </form>
        </section>
    </div>
@endif
