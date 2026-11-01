{{--
    Phone bottom bar of the admin panel (App\Support\AdminBottomNav): Dashboard and
    the company switch on the left, a raised "+" in the middle, Orders and Products
    on the right. "+" lists the Create pages the person may use; one opens in a
    pop-up (iframe) so the form is filled where the person already is. Hidden from
    lg up, where Filament shows the sidebar. Behaviour: bottom-nav-script.blade.php.
--}}
@php
    $current = rtrim(url()->current(), '/');
    $isOn = fn (?string $url): bool => filled($url)
        && ($current === rtrim($url, '/') || str_starts_with($current, rtrim($url, '/').'/'));

    $company = $company ?? null;
    $companyName = $company['selectedName'] ?? null;
    $entries = collect($creates)->mapWithKeys(fn (array $entry): array => [$entry['key'] => [
        'key' => $entry['key'],
        'label' => $entry['label'],
        'url' => $entry['url'],
    ]]);
    $itemClass = 'flex w-full flex-col items-center gap-0.5 rounded-lg px-1 py-1.5 text-[11px] font-medium leading-tight';
    $idleClass = 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200';
    $onClass = 'text-primary-600 dark:text-primary-400';
@endphp

<div
    class="zz-admin-bottom-nav lg:hidden"
    data-admin-bottom-nav
    x-data="zzQuickCreate(@js(['frameName' => $frameName, 'refreshPaths' => $refreshPaths]))"
    x-on:keydown.escape.window="sheet = null"
>
    <nav
        class="fixed inset-x-0 bottom-0 z-20 border-t border-gray-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur dark:border-white/10 dark:bg-gray-900/95"
        aria-label="{{ __('Quick menu') }}"
    >
        <ul class="mx-auto grid max-w-lg grid-cols-5 items-end px-1 pt-1">
            <li class="flex justify-center">
                <a
                    href="{{ $dashboard['url'] }}"
                    @class([$itemClass, $onClass => $current === rtrim($dashboard['url'], '/'), $idleClass => $current !== rtrim($dashboard['url'], '/')])
                    @if ($current === rtrim($dashboard['url'], '/')) aria-current="page" @endif
                    data-bottom-nav="dashboard"
                >
                    <x-filament::icon :icon="$dashboard['icon']" class="h-6 w-6" />
                    <span class="max-w-full truncate">{{ $dashboard['label'] }}</span>
                </a>
            </li>

            <li class="flex justify-center">
                @if ($company)
                    <button
                        type="button"
                        @disabled(! $company['canSwitch'])
                        x-on:click="toggle('company')"
                        x-bind:aria-expanded="sheet === 'company'"
                        aria-haspopup="dialog"
                        aria-label="{{ __('Current company') }}: {{ $companyName }}"
                        @class([$itemClass, 'cursor-default opacity-70' => ! $company['canSwitch']])
                        x-bind:class="sheet === 'company' ? '{{ $onClass }}' : '{{ $idleClass }}'"
                        data-bottom-nav="company"
                    >
                        <x-filament::icon icon="heroicon-o-building-office-2" class="h-6 w-6" />
                        <span class="max-w-full truncate">{{ $companyName }}</span>
                    </button>
                @endif
            </li>

            <li class="flex justify-center">
                @if ($creates !== [])
                    <button
                        type="button"
                        x-on:click="toggle('create')"
                        x-bind:aria-expanded="sheet === 'create'"
                        aria-haspopup="dialog"
                        aria-label="{{ __('Add new') }}"
                        title="{{ __('Add new') }}"
                        class="-mt-5 mb-1 flex h-14 w-14 items-center justify-center rounded-full bg-primary-500 text-white shadow-lg ring-4 ring-white transition-transform duration-200 dark:ring-gray-900"
                        x-bind:class="sheet === 'create' ? 'rotate-45' : ''"
                        data-bottom-nav="create"
                    >
                        <x-filament::icon icon="heroicon-o-plus" class="h-7 w-7" />
                    </button>
                @endif
            </li>

            <li class="flex justify-center">
                @if ($orders)
                    <a
                        href="{{ $orders['url'] }}"
                        @class([$itemClass, $onClass => $isOn($orders['url']), $idleClass => ! $isOn($orders['url'])])
                        @if ($isOn($orders['url'])) aria-current="page" @endif
                        data-bottom-nav="orders"
                    >
                        <x-filament::icon :icon="$orders['icon']" class="h-6 w-6" />
                        <span class="max-w-full truncate">{{ $orders['label'] }}</span>
                    </a>
                @endif
            </li>

            <li class="flex justify-center">
                @if ($products)
                    <a
                        href="{{ $products['url'] }}"
                        @class([$itemClass, $onClass => $isOn($products['url']), $idleClass => ! $isOn($products['url'])])
                        @if ($isOn($products['url'])) aria-current="page" @endif
                        data-bottom-nav="products"
                    >
                        <x-filament::icon :icon="$products['icon']" class="h-6 w-6" />
                        <span class="max-w-full truncate">{{ $products['label'] }}</span>
                    </a>
                @endif
            </li>
        </ul>
    </nav>

    <div
        class="fixed inset-0 z-40 bg-gray-950/40"
        x-show="sheet !== null"
        x-transition.opacity
        x-on:click="sheet = null"
        aria-hidden="true"
        x-cloak
    ></div>

    {{-- "+": the Create pages --}}
    @if ($creates !== [])
        <section
            class="fixed inset-x-0 bottom-0 z-50 mx-auto max-w-lg rounded-t-2xl bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-2xl dark:bg-gray-900"
            role="dialog"
            aria-modal="true"
            aria-labelledby="zz-bottom-nav-create-title"
            x-show="sheet === 'create'"
            x-trap="sheet === 'create'"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-full"
            x-transition:enter-end="translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-y-0"
            x-transition:leave-end="translate-y-full"
            x-cloak
        >
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 id="zz-bottom-nav-create-title" class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Add new') }}</h2>
                <button type="button" class="rounded-full p-1.5 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/10" x-on:click="sheet = null" aria-label="{{ __('Close') }}">
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                </button>
            </div>

            <ul class="grid gap-2">
                @foreach ($creates as $entry)
                    <li>
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl bg-gray-50 px-3 py-3 text-start text-sm font-medium text-gray-950 hover:bg-gray-100 dark:bg-white/5 dark:text-white dark:hover:bg-white/10"
                            x-on:click="choose({{ \Illuminate\Support\Js::from($entries[$entry['key']]) }})"
                            data-quick-create="{{ $entry['key'] }}"
                        >
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-500/10 text-primary-600 dark:text-primary-400">
                                <x-filament::icon :icon="$entry['icon']" class="h-5 w-5" />
                            </span>
                            <span>{{ $entry['label'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- The company switch (same POST as the header drop-down) --}}
    @if ($company && $company['canSwitch'])
        <section
            class="fixed inset-x-0 bottom-0 z-50 mx-auto max-h-[80dvh] max-w-lg overflow-y-auto rounded-t-2xl bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-2xl dark:bg-gray-900"
            role="dialog"
            aria-modal="true"
            aria-labelledby="zz-bottom-nav-company-title"
            x-show="sheet === 'company'"
            x-trap="sheet === 'company'"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-full"
            x-transition:enter-end="translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-y-0"
            x-transition:leave-end="translate-y-full"
            x-cloak
        >
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 id="zz-bottom-nav-company-title" class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Choose company') }}</h2>
                <button type="button" class="rounded-full p-1.5 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/10" x-on:click="sheet = null" aria-label="{{ __('Close') }}">
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                </button>
            </div>

            <ul class="grid gap-2">
                @if ($company['canSelectAll'])
                    <li>
                        <form method="POST" action="{{ route('admin.company.switch') }}">
                            @csrf
                            <input type="hidden" name="company_id" value="all">
                            <button
                                type="submit"
                                @class([
                                    'flex w-full items-center gap-3 rounded-xl px-3 py-3 text-start text-sm font-medium',
                                    'bg-primary-500/10 text-primary-700 dark:text-primary-300' => $company['selected'] === 'all',
                                    'bg-gray-50 text-gray-950 hover:bg-gray-100 dark:bg-white/5 dark:text-white dark:hover:bg-white/10' => $company['selected'] !== 'all',
                                ])
                                @if ($company['selected'] === 'all') aria-current="true" @endif
                            >
                                <x-filament::icon :icon="$company['selected'] === 'all' ? 'heroicon-o-check-circle' : 'heroicon-o-squares-2x2'" class="h-5 w-5 shrink-0" />
                                <span>{{ __('All Companies') }}</span>
                            </button>
                        </form>
                    </li>
                @endif

                @foreach ($company['companies'] as $option)
                    @php $selected = (string) $company['selected'] === (string) $option->getKey(); @endphp
                    <li>
                        <form method="POST" action="{{ route('admin.company.switch') }}">
                            @csrf
                            <input type="hidden" name="company_id" value="{{ $option->getKey() }}">
                            <button
                                type="submit"
                                @class([
                                    'flex w-full items-center gap-3 rounded-xl px-3 py-3 text-start text-sm font-medium',
                                    'bg-primary-500/10 text-primary-700 dark:text-primary-300' => $selected,
                                    'bg-gray-50 text-gray-950 hover:bg-gray-100 dark:bg-white/5 dark:text-white dark:hover:bg-white/10' => ! $selected,
                                ])
                                @if ($selected) aria-current="true" @endif
                            >
                                <x-filament::icon :icon="$selected ? 'heroicon-o-check-circle' : 'heroicon-o-building-office-2'" class="h-5 w-5 shrink-0" />
                                <span class="truncate">{{ $option->name }}</span>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- The pop-up: the Create page of the chosen entry, in an iframe named by the script --}}
    @if ($creates !== [])
        <div
            class="fixed inset-0 z-[60] flex items-stretch justify-center sm:items-center sm:p-6"
            role="dialog"
            aria-modal="true"
            x-bind:aria-label="frame ? frame.label : ''"
            x-show="open"
            x-trap.noscroll="open"
            x-transition.opacity
            x-cloak
        >
            <div class="absolute inset-0 bg-gray-950/50" aria-hidden="true"></div>

            <div class="relative flex h-full w-full flex-col overflow-hidden bg-white shadow-2xl dark:bg-gray-900 sm:h-[90dvh] sm:max-w-3xl sm:rounded-2xl">
                <header class="flex items-center gap-3 border-b border-gray-200 px-4 py-3 pt-[max(0.75rem,env(safe-area-inset-top))] dark:border-white/10">
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-base font-semibold text-gray-950 dark:text-white" x-text="frame ? frame.label : ''"></h2>
                        @if ($companyName)
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $companyName }}</p>
                        @endif
                    </div>
                    <button type="button" class="rounded-full p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/10" x-on:click="close()" aria-label="{{ __('Close') }}">
                        <x-filament::icon icon="heroicon-m-x-mark" class="h-6 w-6" />
                    </button>
                </header>

                <div class="relative min-h-0 flex-1">
                    <template x-if="frame">
                        <iframe
                            name="{{ $frameName }}"
                            x-bind:src="frame.url"
                            x-bind:title="frame.label"
                            x-on:load="loading = false"
                            class="h-full w-full border-0 bg-white dark:bg-gray-900"
                        ></iframe>
                    </template>

                    <div class="absolute inset-0 flex items-center justify-center bg-white dark:bg-gray-900" x-show="loading" x-cloak>
                        <x-filament::loading-indicator class="h-8 w-8 text-primary-500" />
                        <span class="sr-only">{{ __('Loading…') }}</span>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
