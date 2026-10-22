{{-- "Bring in old data" (ImportOldData): two cards, each with its sample file and upload button. --}}
<x-filament-panels::page>
    <div class="trade-cards">
        <section class="trade-card" aria-labelledby="trade-import-buyers">
            <div class="trade-card-head">
                <span class="trade-card-icon trade-tone-amber"><x-filament::icon icon="heroicon-o-users" class="h-6 w-6" /></span>
                <h2 id="trade-import-buyers" class="trade-card-title">{{ __('Buyers and old dues') }}</h2>
            </div>
            <p class="trade-card-note">{{ __('Columns: name, phone, address, opening_balance (the money they still owe from the old books). A buyer with the same phone is updated, not added twice.') }}</p>
            <div class="trade-actions">
                {{ $this->buyerSampleAction }}
                {{ $this->importBuyersAction }}
            </div>
        </section>

        <section class="trade-card" aria-labelledby="trade-import-items">
            <div class="trade-card-head">
                <span class="trade-card-icon trade-tone-blue"><x-filament::icon icon="heroicon-o-archive-box" class="h-6 w-6" /></span>
                <h2 id="trade-import-items" class="trade-card-title">{{ __('Items and stock') }}</h2>
            </div>
            <p class="trade-card-note">{{ __('Columns: name, unit, sale_price, cost_price, stock (how many are on the shelf now), reorder_level. The same item name brought in again is updated.') }}</p>
            <div class="trade-actions">
                {{ $this->itemSampleAction }}
                {{ $this->importItemsAction }}
            </div>
        </section>
    </div>
</x-filament-panels::page>
