{{-- One link tab of the admin bottom bar (admin-bottom-navigation.blade.php). --}}
<a
    href="{{ $tab['url'] }}"
    wire:navigate
    @class([
        'flex w-full flex-col items-center gap-0.5 rounded-lg px-0.5 py-1.5 text-[10px] font-medium leading-tight min-[360px]:text-[11px]',
        'text-primary-600 dark:text-primary-400' => $active,
        'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => ! $active,
    ])
    @if ($active) aria-current="page" @endif
    data-bottom-nav="{{ $key }}"
>
    <x-filament::icon :icon="$tab['icon']" class="h-6 w-6" />
    <span class="max-w-full truncate">{{ $tab['label'] }}</span>
</a>
