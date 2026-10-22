{{--
    The office on phones and tablets (IMS-V2/DESIGN.md Â§6.2, owner request
    2026-10-22): a tab bar at the bottom like the owner's reference apps â€”
    Home Â· Investors Â· a round green "+" Â· To do Â· Menu. "+", "To do" and
    "Menu" open a sheet from the bottom: things to add, the owner's to-do list,
    and every page as big tiles (with the company and language switches).
    Hidden from lg up, where the top menu and the icon rail are. If building it
    fails, nothing is drawn and the menu button in the top bar stays.
--}}
@php
    use App\Filament\Ims\Office\OfficeNavigation;
    use App\Support\ImsNumbers;
    use App\Support\ModuleGuard;

    $nav = filament()->auth()->check()
        ? ModuleGuard::run('ims-office-mobile-nav', fn (): array => OfficeNavigation::mobile())
        : null;
    $todoTotal = $nav ? array_sum(array_column($nav['todo'], 'count')) : 0;
    $onTab = $nav ? collect($nav['tabs'])->contains('active', true) : false;
@endphp

@if ($nav)
    <div
        class="ims-mobile"
        x-data="{ sheet: null }"
        x-on:keydown.escape.window="sheet = null"
    >
        <nav class="ims-tabbar" aria-label="{{ __('Quick menu') }}">
            @foreach ($nav['tabs'] as $tab)
                <a href="{{ $tab['url'] }}" class="ims-tabbar-item" @if ($tab['active']) aria-current="page" @endif>
                    <x-filament::icon :icon="$tab['icon']" class="ims-tabbar-icon" />
                    <span>{{ $tab['label'] }}</span>
                </a>
            @endforeach

            @if ($nav['add'] !== [])
                <span class="ims-tabbar-fab-slot">
                    <button type="button" class="ims-tabbar-fab" x-on:click="sheet = 'add'" aria-label="{{ __('Add new') }}" title="{{ __('Add new') }}" x-bind:aria-expanded="sheet === 'add'">
                        <x-filament::icon icon="heroicon-m-plus" class="h-7 w-7" />
                    </button>
                </span>
            @endif

            <button type="button" class="ims-tabbar-item" x-on:click="sheet = 'todo'" x-bind:aria-expanded="sheet === 'todo'">
                <span class="ims-tabbar-icon-wrap">
                    <x-filament::icon icon="heroicon-o-clipboard-document-check" class="ims-tabbar-icon" />
                    @if ($todoTotal > 0)
                        <span class="ims-tabbar-count">{{ ImsNumbers::count(min($todoTotal, 99)) }}</span>
                    @endif
                </span>
                <span>{{ __('To do') }}</span>
            </button>

            <button type="button" class="ims-tabbar-item" x-on:click="sheet = 'menu'" x-bind:aria-expanded="sheet === 'menu'" @if (! $onTab) aria-current="page" @endif>
                <x-filament::icon icon="heroicon-o-squares-2x2" class="ims-tabbar-icon" />
                <span>{{ __('Menu') }}</span>
            </button>
        </nav>

        {{-- The sheets: a dark veil over the page, the white sheet slides up from the bottom. --}}
        <div class="ims-sheet-veil" x-show="sheet !== null" x-transition.opacity x-on:click="sheet = null" x-cloak></div>

        @if ($nav['add'] !== [])
            <section class="ims-sheet" role="dialog" aria-modal="true" aria-labelledby="ims-sheet-add-title" x-show="sheet === 'add'" x-trap="sheet === 'add'" x-transition:enter="ims-sheet-move" x-transition:enter-start="ims-sheet-off" x-transition:leave="ims-sheet-move" x-transition:leave-end="ims-sheet-off" x-cloak>
                <div class="ims-sheet-head">
                    <h2 id="ims-sheet-add-title" class="ims-sheet-title">{{ __('Add new') }}</h2>
                    <button type="button" class="ims-round-btn" x-on:click="sheet = null" aria-label="{{ __('Close') }}"><x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" /></button>
                </div>
                <div class="ims-sheet-tiles ims-sheet-tiles-2">
                    @foreach ($nav['add'] as $item)
                        <a href="{{ $item['url'] }}" class="ims-sheet-tile">
                            <span class="ims-sheet-tile-icon"><x-filament::icon :icon="$item['icon']" class="h-6 w-6" /></span>
                            <span class="ims-sheet-tile-label">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="ims-sheet" role="dialog" aria-modal="true" aria-labelledby="ims-sheet-todo-title" x-show="sheet === 'todo'" x-trap="sheet === 'todo'" x-transition:enter="ims-sheet-move" x-transition:enter-start="ims-sheet-off" x-transition:leave="ims-sheet-move" x-transition:leave-end="ims-sheet-off" x-cloak>
            <div class="ims-sheet-head">
                <div>
                    <h2 id="ims-sheet-todo-title" class="ims-sheet-title">{{ __('What to do today') }}</h2>
                    <p class="ims-kpi-note">{{ __('The most urgent first') }}</p>
                </div>
                <button type="button" class="ims-round-btn" x-on:click="sheet = null" aria-label="{{ __('Close') }}"><x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" /></button>
            </div>
            @if ($nav['todo'] === [])
                <div class="ims-empty">
                    <x-filament::icon icon="heroicon-o-check-badge" class="h-10 w-10 text-(--ims-success)" />
                    <p>{{ __('Nothing is waiting for you today.') }}</p>
                </div>
            @else
                <ul class="ims-todo">
                    @foreach ($nav['todo'] as $item)
                        <li class="ims-todo-item">
                            <a @if ($item['url']) href="{{ $item['url'] }}" @endif class="ims-todo-link">
                                <span @class(['ims-count', 'ims-count-'.$item['tone']])>{{ ImsNumbers::count($item['count']) }}</span>
                                <span class="ims-todo-label">{{ $item['label'] }}</span>
                                @if ($item['url'])
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="ims-todo-chevron" />
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="ims-sheet ims-sheet-tall" role="dialog" aria-modal="true" aria-labelledby="ims-sheet-menu-title" x-show="sheet === 'menu'" x-trap="sheet === 'menu'" x-transition:enter="ims-sheet-move" x-transition:enter-start="ims-sheet-off" x-transition:leave="ims-sheet-move" x-transition:leave-end="ims-sheet-off" x-cloak>
            <div class="ims-sheet-head">
                <h2 id="ims-sheet-menu-title" class="ims-sheet-title">{{ __('Menu') }}</h2>
                <button type="button" class="ims-round-btn" x-on:click="sheet = null" aria-label="{{ __('Close') }}"><x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" /></button>
            </div>

            <div class="ims-sheet-body">
                <div class="ims-sheet-settings">
                    @include('filament.ims.office.language-switch', ['drawer' => true])
                    @include('filament.ims.theme-toggle')
                </div>

                {{-- Home is a tab of its own, so the menu starts with the groups. --}}
                @foreach (array_filter($nav['menu'], fn (array $section): bool => $section['group'] !== null) as $section)
                    <h3 class="ims-sheet-group">{{ $section['group'] }}</h3>
                    <div class="ims-sheet-tiles">
                        @foreach ($section['items'] as $item)
                            <a href="{{ $item['url'] }}" @class(['ims-sheet-tile', 'ims-sheet-tile-on' => $item['active']]) @if ($item['active']) aria-current="page" @endif>
                                <span class="ims-sheet-tile-icon">
                                    <x-filament::icon :icon="$item['icon']" class="h-6 w-6" />
                                    @if (filled($item['badge']))
                                        <span class="ims-tabbar-count">{{ $item['badge'] }}</span>
                                    @endif
                                </span>
                                <span class="ims-sheet-tile-label">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endforeach

                @include('filament.ims.office.company-picker', ['drawer' => true])

                <form method="post" action="{{ filament()->getLogoutUrl() }}" class="ims-sheet-signout">
                    @csrf
                    <button type="submit" class="ims-btn ims-btn-ghost w-full">
                        <x-filament::icon icon="heroicon-o-arrow-right-start-on-rectangle" class="h-5 w-5" />
                        {{ __('Sign out') }}
                    </button>
                </form>
            </div>
        </section>
    </div>
@endif
