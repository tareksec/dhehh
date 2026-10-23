{{--
    The round back button before a page's heading, shown on phones only
    (theme.css), where the navy header has no menu to go back with.
--}}
@php
    $back = \App\Support\ModuleGuard::run('trade-back-button', fn (): ?string => \App\Filament\Trade\TradeNavigation::backUrl(\Livewire\Livewire::current()));
@endphp

@if ($back)
    <a href="{{ $back }}" class="trade-back" aria-label="{{ __('Back') }}">
        <x-filament::icon icon="heroicon-m-chevron-left" class="h-6 w-6" />
    </a>
@endif
