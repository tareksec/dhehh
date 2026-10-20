@php use App\Support\ImsNumbers; @endphp

<x-filament-panels::page>
    @php
        $s = $this->summary;
        $plan = $s['plan'];
        $figures = $s['figures'];
        $currency = $this->currency();
        $statusLabels = ['open' => __('Raising money'), 'running' => __('Running'), 'closed' => __('Finished, profit being worked out'), 'settled' => __('Settled')];
        $shipmentSteps = ['planned' => __('Planned'), 'booked' => __('Booked'), 'shipped' => __('Shipped'), 'in_transit' => __('On the way'), 'customs' => __('At customs'), 'received' => __('Arrived')];
    @endphp

    <x-filament::section>
        <div class="flex flex-wrap items-center gap-3">
            <x-filament::badge color="success">{{ $statusLabels[$plan['status']] ?? $plan['status'] }}</x-filament::badge>
            <span class="text-sm text-gray-600">{{ $plan['code'] }}</span>
            @if ($plan['start_date'])
                <span class="text-sm text-gray-600">{{ ImsNumbers::date($plan['start_date']) }} → {{ ImsNumbers::date($plan['end_date']) }}</span>
            @endif
        </div>
        @if ($plan['description'])
            <p class="mt-3 text-sm">{{ $plan['description'] }}</p>
        @endif
        <p class="mt-2 text-sm">{{ __('Investors\' share of profit and loss: :percent', ['percent' => ImsNumbers::percent($plan['investor_share_percent'])]) }}</p>
        @if ($plan['target_amount'])
            <p class="mt-1 text-sm">{{ __('Target: :amount', ['amount' => ImsNumbers::money($plan['target_amount'], $currency)]) }}</p>
        @endif
    </x-filament::section>

    @if ($figures)
        <x-filament::section :heading="__('Money in this project')" data-ims-figures>
            @if ($figures['estimated'])
                <x-filament::badge color="warning" class="mb-3">{{ __('Estimate — the project is not settled yet') }}</x-filament::badge>
            @endif
            <div class="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(min(12rem,100%),1fr))]">
                <div class="ims-balance-card">
                    <div class="ims-label text-sm">{{ __('Your share') }}</div>
                    <div class="text-2xl font-bold">{{ ImsNumbers::money($figures['my_share'], $currency, true) }}</div>
                    <div class="ims-label text-xs">{{ __('Your money here: :amount', ['amount' => ImsNumbers::money($figures['my_capital'], $currency)]) }}</div>
                </div>
                <div class="ims-card"><div class="text-sm text-gray-600">{{ __('Sales') }}</div><div class="text-xl font-bold">{{ ImsNumbers::money($figures['revenue'], $currency) }}</div></div>
                <div class="ims-card"><div class="text-sm text-gray-600">{{ __('Total cost') }}</div><div class="text-xl font-bold">{{ ImsNumbers::money($figures['costs']['total'], $currency) }}</div></div>
                <div class="ims-card"><div class="text-sm text-gray-600">{{ __('Net profit') }}</div><div class="text-xl font-bold">{{ ImsNumbers::money($figures['net_profit'], $currency, true) }}</div></div>
                <div class="ims-card"><div class="text-sm text-gray-600">{{ __('Investors\' share') }}</div><div class="text-xl font-bold">{{ ImsNumbers::money($figures['investor_pool'], $currency, true) }}</div></div>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <div><dt class="text-gray-600">{{ __('Goods bought') }}</dt><dd class="font-semibold">{{ ImsNumbers::money($figures['costs']['goods'], $currency) }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Shipping, customs and import') }}</dt><dd class="font-semibold">{{ ImsNumbers::money($figures['costs']['import'], $currency) }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Other landed costs') }}</dt><dd class="font-semibold">{{ ImsNumbers::money($figures['costs']['landed_items'], $currency) }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Local expenses') }}</dt><dd class="font-semibold">{{ ImsNumbers::money($figures['costs']['local_items'], $currency) }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Units bought') }}</dt><dd class="font-semibold">{{ ImsNumbers::count($figures['units_bought']) }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Units sold') }}</dt><dd class="font-semibold">{{ ImsNumbers::count($figures['units_sold']) }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Units in stock') }}</dt><dd class="font-semibold">{{ ImsNumbers::count($figures['units_in_stock']) }}</dd></div>
            </dl>
        </x-filament::section>
    @endif

    @if ($s['milestones'] !== [])
        <x-filament::section :heading="__('Timeline')">
            <ol class="ims-timeline">
                @foreach ($s['milestones'] as $milestone)
                    <li @class(['ims-timeline-done' => $milestone['done']])>
                        <span class="ims-timeline-mark" aria-hidden="true"></span>
                        <span class="flex flex-col">
                            <span class="font-semibold">{{ $milestone['title'] }}</span>
                            <span class="text-xs text-gray-600">
                                {{ $milestone['done'] ? __('Done on :date', ['date' => ImsNumbers::date($milestone['done_on'])]) : ($milestone['due_on'] ? __('Planned for :date', ['date' => ImsNumbers::date($milestone['due_on'])]) : __('Not done yet')) }}
                            </span>
                        </span>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    @endif

    @if ($s['shipments'] !== [])
        <x-filament::section :heading="__('Shipments')">
            <ul class="flex flex-col gap-3">
                @foreach ($s['shipments'] as $shipment)
                    <li class="ims-card flex flex-wrap items-center justify-between gap-2">
                        <span class="font-semibold">{{ $shipmentSteps[$shipment['status']] ?? $shipment['status'] }}{{ $shipment['mode'] ? ' · '.__(ucfirst($shipment['mode'])) : '' }}</span>
                        <span class="text-sm text-gray-600">
                            @if ($shipment['arrived_at'])
                                {{ __('Arrived on :date', ['date' => ImsNumbers::date($shipment['arrived_at'])]) }}
                            @elseif ($shipment['expected_at'])
                                {{ __('Expected on :date', ['date' => ImsNumbers::date($shipment['expected_at'])]) }}
                            @endif
                        </span>
                        @if ($shipment['late_days'] > 0)
                            <x-filament::badge color="warning">{{ __('Late by :days days', ['days' => ImsNumbers::count($shipment['late_days'])]) }}</x-filament::badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    @if ($s['follows'])
        <x-filament::section :heading="__('News')">
            @forelse ($s['updates'] as $update)
                <article class="ims-todo-item flex-col items-start">
                    <span class="text-xs text-gray-600">{{ ImsNumbers::date($update['published_at']) }}</span>
                    <h3 class="font-semibold">{{ $update['title'] }}</h3>
                    @if ($update['body'])
                        <p class="text-sm whitespace-pre-line">{{ $update['body'] }}</p>
                    @endif
                    @if ($update['photo_ids'] !== [])
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($update['photo_ids'] as $photoId)
                                <img src="{{ route('filament.investor.ims.update-photo', ['photo' => $photoId]) }}" alt="" class="h-24 w-24 rounded-xl object-cover" loading="lazy">
                            @endforeach
                        </div>
                    @endif
                    @if ($update['video_url'])
                        <a class="text-sm text-primary-600 underline" href="{{ $update['video_url'] }}" target="_blank" rel="noopener noreferrer">{{ __('Watch the video') }}</a>
                    @endif
                </article>
            @empty
                <p class="text-sm text-gray-600">{{ __('No news yet. The office posts news here as the project goes on.') }}</p>
            @endforelse
        </x-filament::section>
    @endif

    @if ($s['reports'] !== [])
        <x-filament::section :heading="__('Reports')">
            <ul class="flex flex-col gap-2">
                @foreach ($s['reports'] as $report)
                    <li class="flex items-center justify-between gap-2">
                        <span>{{ $report['title'] }} · <span class="text-sm text-gray-600">{{ ImsNumbers::date($report['published_at']) }}</span></span>
                        <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-arrow-down-tray" :href="route('filament.investor.ims.project-report', ['report' => $report['id']])" target="_blank">{{ __('PDF') }}</x-filament::button>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
