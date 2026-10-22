{{--
    The office home grid (IMS-V2/DESIGN.md §6.1). Wide screens: money | chart |
    to-do on top, latest money in | payouts below, the groups at the bottom.
    Phones and tablets (§6.2): one column — the green card, the round quick
    buttons, then the to-do list.
--}}
<x-filament-panels::page>
    @php $quick = $this->quickActions(); @endphp

    <div class="ims-home-grid ims-office-grid">
        <div class="ims-area-money">@livewire(\App\Filament\Ims\Office\Widgets\OfficeMoneyCards::class)</div>
        @if ($quick !== [])
            <nav class="ims-area-quick ims-quick-actions ims-quick-actions-office" aria-label="{{ __('Quick actions') }}">
                @foreach ($quick as $action)
                    <a href="{{ $action['url'] }}">
                        <span class="ims-quick-icon">
                            <x-filament::icon :icon="$action['icon']" class="h-6 w-6" />
                            @if ($action['count'] > 0)
                                <span class="ims-tabbar-count">{{ \App\Support\ImsNumbers::count(min($action['count'], 99)) }}</span>
                            @endif
                        </span>
                        <span>{{ $action['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        @endif
        <div class="ims-area-chart">@livewire(\App\Filament\Ims\Office\Widgets\OfficeProfitChart::class)</div>
        <div class="ims-area-todo">@livewire(\App\Filament\Ims\Office\Widgets\OwnerTodoList::class)</div>
        <div class="ims-area-latest">@livewire(\App\Filament\Ims\Office\Widgets\LatestMoneyIn::class)</div>
        <div class="ims-area-payouts">@livewire(\App\Filament\Ims\Office\Widgets\OfficePayoutsDue::class)</div>
        <div class="ims-area-groups">@livewire(\App\Filament\Ims\Office\Widgets\RunningGroups::class)</div>
    </div>
</x-filament-panels::page>
