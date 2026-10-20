<x-filament-widgets::widget class="ims-widget">
    @php
        $groups = $this->groups();
        $currency = $this->currency();
        $resource = \App\Filament\Resources\InvestorGroups\InvestorGroupResource::class;
    @endphp

    <section class="ims-card" aria-labelledby="ims-groups-now">
        <div class="ims-card-head">
            <span class="ims-card-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-user-group" class="h-5 w-5" /></span>
            <div class="min-w-0 flex-1">
                <h2 id="ims-groups-now" class="ims-card-title">{{ __('Groups now') }}</h2>
                <p class="ims-kpi-note">{{ __('Open and running groups') }}</p>
            </div>
            @if ($resource::canAccess())
                <a href="{{ $resource::getUrl(panel: 'office') }}" class="ims-arrow-btn" aria-label="{{ __('Open groups') }}">
                    <x-filament::icon icon="heroicon-o-arrow-up-right" class="h-5 w-5" />
                </a>
            @endif
        </div>

        @if ($groups->isEmpty())
            <div class="ims-empty">
                <p class="font-semibold">{{ __('No group is open or running') }}</p>
                <p>{{ __('Open a group to start taking in money.') }}</p>
                @if ($resource::canAccess())
                    <a href="{{ $resource::getUrl(panel: 'office') }}" class="ims-btn ims-btn-primary">{{ __('Open groups') }}</a>
                @endif
            </div>
        @else
            <div class="ims-group-grid">
                @foreach ($groups as $row)
                    <a @if ($row['url']) href="{{ $row['url'] }}" @endif class="ims-group-tile">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="ims-muted text-sm">{{ $row['group']->group_code }}</div>
                                <div class="font-semibold">{{ $row['group']->name }}</div>
                            </div>
                            <x-filament::badge :color="$row['color']">{{ $row['label'] }}</x-filament::badge>
                        </div>
                        <x-ims.money class="ims-kpi-value" :amount="$row['money']" :currency="$currency" />
                        <div class="ims-kpi-note">{{ trans_choice(':count person in this group|:count people in this group', $row['people'], ['count' => \App\Support\ImsNumbers::count($row['people'])]) }}</div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</x-filament-widgets::widget>
