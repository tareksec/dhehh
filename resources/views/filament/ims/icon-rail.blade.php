{{--
    The menu rail on the left of wide screens (IMS-V2/DESIGN.md §6.1), shared by
    the IMS office and the investor portal: small round groups of icons, the
    open page in a green circle. So a beginner knows what each icon is, the
    rail opens with the names when the mouse is over it, and the button at its
    top keeps it open (remembered in this browser). "Sign out" sits at the
    bottom. Hidden below lg, where the bottom tab bar or the menu drawer (both
    with names) take over.
--}}
@php
    /** @var array{groups: list<list<array{label: string, icon: string, url: string, active: bool}>>, bottom: list<array{label: string, icon: string, url: string, active: bool}>} $rail */
@endphp

@if (filament()->auth()->check())
    {{-- Before the page draws, so a pinned menu does not jump open after loading. --}}
    <script>try { if (localStorage.getItem('ims-rail-pinned') === '1') document.documentElement.classList.add('ims-rail-pinned') } catch (e) {}</script>
    <nav
        class="ims-rail"
        aria-label="{{ __('Shortcuts') }}"
        x-data="{
            pinned: (() => { try { return localStorage.getItem('ims-rail-pinned') === '1' } catch (e) { return false } })(),
            hovered: false,
            hoverTimeout: null,
            onEnter() {
                if (this.hoverTimeout) {
                    clearTimeout(this.hoverTimeout);
                    this.hoverTimeout = null;
                }
                this.hovered = true;
            },
            onLeave() {
                this.hoverTimeout = setTimeout(() => {
                    this.hovered = false;
                }, 180);
            },
            toggle() {
                this.pinned = ! this.pinned;
                try { localStorage.setItem('ims-rail-pinned', this.pinned ? '1' : '0'); } catch (e) {}
            },
        }"
        x-on:mouseenter="onEnter()"
        x-on:mouseleave="onLeave()"
        x-effect="
            document.documentElement.classList.toggle('ims-rail-pinned', pinned);
            document.documentElement.classList.toggle('ims-rail-hovered', hovered);
        "
    >
        <div class="ims-rail-top">
            <button
                type="button"
                class="ims-rail-btn ims-rail-toggle"
                x-on:click="toggle()"
                x-bind:aria-expanded="pinned"
                x-bind:title="pinned ? '{{ __('Close the menu') }}' : '{{ __('Keep the menu open') }}'"
            >
                <x-filament::icon icon="heroicon-o-bars-3" class="ims-rail-icon" x-show="! pinned" />
                <x-filament::icon icon="heroicon-o-chevron-double-left" class="ims-rail-icon" x-show="pinned" x-cloak />
                <span class="ims-rail-label" x-show="! pinned">{{ __('Keep the menu open') }}</span>
                <span class="ims-rail-label" x-show="pinned" x-cloak>{{ __('Close the menu') }}</span>
            </button>
        </div>

        <div class="ims-rail-body">
            @foreach ($rail['groups'] as $group)
                <ul class="ims-rail-group">
                    @foreach ($group as $item)
                        <li>
                            <a
                                href="{{ $item['url'] }}"
                                @class(['ims-rail-btn', 'ims-rail-btn-active' => $item['active']])
                                @if ($item['active']) aria-current="page" @endif
                                title="{{ $item['label'] }}"
                            >
                                <x-filament::icon :icon="$item['icon']" class="ims-rail-icon" />
                                <span class="ims-rail-label">{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </div>

        <ul class="ims-rail-group ims-rail-bottom">
            @foreach ($rail['bottom'] as $item)
                <li>
                    <a
                        href="{{ $item['url'] }}"
                        @class(['ims-rail-btn', 'ims-rail-btn-active' => $item['active']])
                        @if ($item['active']) aria-current="page" @endif
                        title="{{ $item['label'] }}"
                    >
                        <x-filament::icon :icon="$item['icon']" class="ims-rail-icon" />
                        <span class="ims-rail-label">{{ $item['label'] }}</span>
                    </a>
                </li>
            @endforeach
            <li>
                <form method="post" action="{{ filament()->getLogoutUrl() }}">
                    @csrf
                    <button type="submit" class="ims-rail-btn" title="{{ __('Sign out') }}">
                        <x-filament::icon icon="heroicon-o-arrow-right-start-on-rectangle" class="ims-rail-icon" />
                        <span class="ims-rail-label">{{ __('Sign out') }}</span>
                    </button>
                </form>
            </li>
        </ul>
    </nav>
@endif
