{{-- Phone-width tab bar of the reseller portal (ResellerPanelProvider::bottomNav()); the sidebar still holds everything else. --}}
@php
    $current = rtrim(url()->current(), '/');
    $isActive = fn (string $url, bool $exact): bool => $exact
        ? $current === rtrim($url, '/')
        : str_starts_with($current, rtrim($url, '/'));
    $iconClass = 'h-6 w-6';
@endphp
<nav
    class="reseller-bottom-nav fixed inset-x-0 bottom-0 z-20 border-t border-gray-200 bg-white/95 backdrop-blur dark:border-white/10 dark:bg-gray-900/95 lg:hidden"
    aria-label="{{ __('Portal menu') }}"
    data-reseller-bottom-nav
>
    <ul class="mx-auto grid max-w-lg grid-cols-5 items-end px-1 pt-1">
        @foreach ($items as $item)
            @php $active = $isActive($item['url'], $item['exact']); @endphp
            <li class="flex justify-center">
                @if ($item['primary'])
                    <a
                        href="{{ $item['url'] }}"
                        class="-mt-5 mb-1 flex h-14 w-14 items-center justify-center rounded-full bg-primary-500 text-white shadow-lg ring-4 ring-white dark:ring-gray-900"
                        aria-label="{{ $item['label'] }}"
                        title="{{ $item['label'] }}"
                        data-bottom-nav="{{ $item['key'] }}"
                    >
                        <x-filament::icon :icon="$item['icon']" class="h-7 w-7" />
                    </a>
                @else
                    <a
                        href="{{ $item['url'] }}"
                        @class([
                            'flex w-full flex-col items-center gap-0.5 rounded-lg px-1 py-1.5 text-[11px] font-medium leading-tight',
                            'text-primary-600 dark:text-primary-400' => $active,
                            'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => ! $active,
                        ])
                        @if ($active) aria-current="page" @endif
                        data-bottom-nav="{{ $item['key'] }}"
                    >
                        <x-filament::icon :icon="$item['icon']" :class="$iconClass" />
                        <span class="max-w-full truncate">{{ $item['label'] }}</span>
                    </a>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
