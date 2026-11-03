{{-- Phone-width bottom bar of the admin panel (App\Livewire\AdminBottomNavigation); the sidebar still holds everything else. --}}
{{-- The root <div> must be the first thing drawn, with the condition inside it: Livewire takes the first element of the output as the component's root, and an @if around everything makes it pick the <nav>, leaving the popups outside the component. --}}
<div class="zz-bottom-nav-root" data-admin-bottom-nav-root>
    @unless ($hidden)
        {{-- wire:ignore: nothing in the bar changes on a Livewire update (the active tab is fixed per page, the popups are drawn below), and morphing it while a popup saves and the page navigates made Alpine lose the "+" menu's state. The wire:click on the menu still works. --}}
        <nav
            wire:ignore
            class="zz-bottom-nav fixed inset-x-0 bottom-0 z-20 border-t border-gray-200 bg-white/95 backdrop-blur dark:border-white/10 dark:bg-gray-900/95 lg:hidden"
            aria-label="{{ __('Quick navigation') }}"
            data-admin-bottom-nav
        >
            {{-- Columns are set in theme.css (.zz-bottom-nav-grid): the company column is wider so a company name fits on two lines, and the two sides stay equal so the "+" stays in the middle. Cells start at the top so the icons line up when the name takes two lines. --}}
            <ul class="zz-bottom-nav-grid mx-auto grid max-w-lg items-start px-1 pt-1">
                {{-- Left: Dashboard and the company switch. --}}
                <li class="flex justify-center">
                    @include('filament.partials.admin-bottom-navigation-tab', ['key' => 'dashboard', 'tab' => $tabs['dashboard'], 'active' => $activeTab === 'dashboard'])
                </li>

                <li class="flex justify-center">
                    @if ($company)
                        @php
                            $selectedIsAll = $company['selected'] === 'all';
                            $selectedLabel = $selectedIsAll ? __('All Companies') : $company['selectedName'];
                        @endphp

                        {{-- The tab is narrow and near the left edge: shift keeps the list on screen. --}}
                        <x-filament::dropdown
                            placement="top-start"
                            width="xs"
                            :shift="true"
                            :size-padding="8"
                            teleport
                            class="w-full"
                            wire:key="admin-bottom-nav-company"
                        >
                            <x-slot name="trigger">
                                <button
                                    type="button"
                                    class="flex w-full flex-col items-center gap-0.5 rounded-lg px-0.5 py-1.5 text-[10px] font-medium leading-tight min-[360px]:text-[11px] text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                                    aria-label="{{ __('Switch company') }}: {{ $selectedLabel }}"
                                    title="{{ $selectedLabel }}"
                                    data-bottom-nav="company"
                                >
                                    <x-filament::icon icon="heroicon-o-building-office-2" class="h-6 w-6" />
                                    {{-- Up to two lines, then an ellipsis; the full name is also the button's title and in the list it opens. --}}
                                    <span class="line-clamp-2 max-w-full break-words text-center" data-bottom-nav-company-label>{{ $selectedLabel }}</span>
                                </button>
                            </x-slot>

                            <x-filament::dropdown.list>
                                @if ($company['isSuperAdmin'])
                                    <form method="POST" action="{{ route('admin.company.switch') }}">
                                        @csrf
                                        <input type="hidden" name="company_id" value="all">

                                        <x-filament::dropdown.list.item
                                            type="submit"
                                            :color="$selectedIsAll ? 'primary' : 'gray'"
                                            class="{{ $selectedIsAll ? 'fi-selected' : '' }}"
                                        >
                                            {{ __('All Companies') }}
                                        </x-filament::dropdown.list.item>
                                    </form>
                                @endif

                                @foreach ($company['companies'] as $option)
                                    @php $isSelected = $company['selected'] === (string) $option->getKey(); @endphp

                                    <form method="POST" action="{{ route('admin.company.switch') }}">
                                        @csrf
                                        <input type="hidden" name="company_id" value="{{ $option->getKey() }}">

                                        <x-filament::dropdown.list.item
                                            type="submit"
                                            :color="$isSelected ? 'primary' : 'gray'"
                                            class="{{ $isSelected ? 'fi-selected' : '' }}"
                                        >
                                            {{ $option->name }}
                                        </x-filament::dropdown.list.item>
                                    </form>
                                @endforeach
                            </x-filament::dropdown.list>
                        </x-filament::dropdown>
                    @endif
                </li>

                {{-- Middle: the raised "+" and its quick-create menu. --}}
                <li class="relative flex justify-center self-end" x-data="{ quickOpen: false }" x-on:click.outside="quickOpen = false" x-on:keydown.escape.window="quickOpen = false">
                    @if ($quickActions !== [])
                        <button
                            type="button"
                            class="-mt-5 mb-1 flex h-14 w-14 items-center justify-center rounded-full bg-primary-500 text-white shadow-lg ring-4 ring-white transition active:scale-95 dark:ring-gray-900"
                            aria-label="{{ __('Quick create') }}"
                            aria-haspopup="menu"
                            x-bind:aria-expanded="quickOpen"
                            x-on:click="quickOpen = ! quickOpen"
                            data-bottom-nav="quick-create"
                        >
                            <x-filament::icon
                                icon="heroicon-o-plus"
                                class="h-7 w-7 transition-transform duration-200"
                                x-bind:class="{ 'rotate-45': quickOpen }"
                            />
                        </button>

                        <div
                            x-cloak
                            x-show="quickOpen"
                            x-transition.opacity.duration.150ms
                            class="absolute bottom-full left-1/2 mb-4 w-64 -translate-x-1/2 rounded-xl bg-white p-1.5 shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                            role="menu"
                            data-quick-create-menu
                        >
                            @foreach ($quickActions as $quickAction)
                                <button
                                    type="button"
                                    role="menuitem"
                                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-start text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                                    wire:click="mountAction('{{ $quickAction['name'] }}')"
                                    x-on:click="quickOpen = false"
                                    data-quick-create="{{ $quickAction['name'] }}"
                                >
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                                        <x-filament::icon :icon="$quickAction['icon']" class="h-5 w-5" />
                                    </span>
                                    <span>{{ $quickAction['label'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </li>

                {{-- Right: Orders and Products. --}}
                @foreach (['orders', 'products'] as $key)
                    <li class="flex justify-center">
                        @if ($tabs[$key]['visible'])
                            @include('filament.partials.admin-bottom-navigation-tab', ['key' => $key, 'tab' => $tabs[$key], 'active' => $activeTab === $key])
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>

        {{-- The create popups. Outside the <nav>: its backdrop blur would otherwise pin the fixed popups to the bar. --}}
        <x-filament-actions::modals />
    @endunless
</div>
