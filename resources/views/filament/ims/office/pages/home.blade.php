{{--
    The office home grid (IMS-V2/DESIGN.md §6.1). Wide screens: money | chart |
    to-do on top, latest money in | payouts below, the groups at the bottom.
    Phones: one column with the to-do list right after the money.
--}}
<x-filament-panels::page>
    <div class="ims-home-grid ims-office-grid">
        <div class="ims-area-money">@livewire(\App\Filament\Ims\Office\Widgets\OfficeMoneyCards::class)</div>
        <div class="ims-area-chart">@livewire(\App\Filament\Ims\Office\Widgets\OfficeProfitChart::class)</div>
        <div class="ims-area-todo">@livewire(\App\Filament\Ims\Office\Widgets\OwnerTodoList::class)</div>
        <div class="ims-area-latest">@livewire(\App\Filament\Ims\Office\Widgets\LatestMoneyIn::class)</div>
        <div class="ims-area-payouts">@livewire(\App\Filament\Ims\Office\Widgets\OfficePayoutsDue::class)</div>
        <div class="ims-area-groups">@livewire(\App\Filament\Ims\Office\Widgets\RunningGroups::class)</div>
    </div>
</x-filament-panels::page>
