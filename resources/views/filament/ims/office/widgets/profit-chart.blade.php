<x-filament-widgets::widget class="ims-widget">
    <x-ims.bar-chart
        :bars="$this->bars()"
        :currency="$this->currency()"
        :heading="__('Profit paid to investors')"
        :link="\App\Filament\Resources\ProjectSettlements\ProjectSettlementResource::canAccess() ? \App\Filament\Resources\ProjectSettlements\ProjectSettlementResource::getUrl(panel: 'office') : null"
        :link-label="__('Open settlements')"
        :empty-text="__('No profit has been paid yet. The bars fill in as payouts are sent.')"
        class="h-full"
    />
</x-filament-widgets::widget>
