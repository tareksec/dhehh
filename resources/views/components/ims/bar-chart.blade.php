{{--
    The IMS bar chart (IMS-V2/DESIGN.md §7 BarChart): round pill bars in soft
    green, the picked month in deep green with its amount in a pill above it,
    and that amount also written out in words under the chart, so the chart is
    never the only place a number lives. A loss month is drawn in red. Tap a
    bar to read it; the "6 months / 12 months" switch shows fewer or more.

    $bars: list of ['label' => short month, 'long' => month and year, 'value' => float]
--}}
@props([
    'bars' => [],
    'currency' => 'BDT',
    'heading' => '',
    'icon' => 'heroicon-o-chart-bar',
    'link' => null,
    'linkLabel' => null,
    'emptyText' => null,
])

@php
    $bars = array_values($bars);
    $count = count($bars);
    $max = max(1.0, ...array_map(fn (array $bar): float => abs((float) $bar['value']), $bars ?: [['value' => 0]]));
    $last = $count - 1;
    // Start on the latest month that has a figure, else the latest month.
    $start = $last;
    for ($i = $last; $i >= 0; $i--) {
        if ((float) $bars[$i]['value'] != 0.0) {
            $start = $i;
            break;
        }
    }
    $hasData = collect($bars)->contains(fn (array $bar): bool => (float) $bar['value'] != 0.0);
    $chartId = 'ims-chart-'.\Illuminate\Support\Str::random(6);
@endphp

<section
    {{ $attributes->class(['ims-card ims-chart']) }}
    aria-labelledby="{{ $chartId }}"
    x-data="{ picked: {{ $start }}, range: {{ $count > 6 ? 6 : $count }} }"
>
    <header class="ims-card-head">
        <span class="ims-card-icon" aria-hidden="true"><x-filament::icon :icon="$icon" class="h-5 w-5" /></span>
        <h2 id="{{ $chartId }}" class="ims-card-title">{{ $heading }}</h2>

        @if ($count > 6)
            <div class="ims-segment" role="group" aria-label="{{ __('Time span') }}">
                <button type="button" x-on:click="range = 6; if (picked < {{ $count }} - 6) picked = {{ $last }}" x-bind:aria-pressed="range === 6" x-bind:class="range === 6 && 'ims-segment-on'">{{ __('6 months') }}</button>
                <button type="button" x-on:click="range = {{ $count }}" x-bind:aria-pressed="range === {{ $count }}" x-bind:class="range === {{ $count }} && 'ims-segment-on'">{{ __(':count months', ['count' => \App\Support\ImsNumbers::count($count)]) }}</button>
            </div>
        @endif

        @if ($link)
            <a href="{{ $link }}" class="ims-arrow-btn" aria-label="{{ $linkLabel ?? $heading }}">
                <x-filament::icon icon="heroicon-o-arrow-up-right" class="h-5 w-5" />
            </a>
        @endif
    </header>

    @if (! $hasData && $emptyText)
        <p class="ims-chart-empty">{{ $emptyText }}</p>
    @endif

    <div class="ims-chart-plot" aria-hidden="true">
        @foreach ($bars as $index => $bar)
            @php
                $value = (float) $bar['value'];
                $height = $value == 0.0 ? 4 : max(8, (int) round(100 * abs($value) / $max));
            @endphp
            <button
                type="button"
                tabindex="-1"
                class="ims-chart-col"
                x-show="{{ $index }} >= {{ $count }} - range"
                x-on:click="picked = {{ $index }}"
            >
                <span class="ims-chart-track">
                    <span
                        @class(['ims-chart-bar', 'ims-chart-bar-loss' => $value < 0])
                        x-bind:class="picked === {{ $index }} && 'ims-chart-bar-on'"
                        style="height: {{ $height }}%"
                    >
                        <span class="ims-chart-tip" x-show="picked === {{ $index }}" x-cloak>
                            <x-ims.money :amount="$value" :currency="$currency" :signed="$value < 0" />
                        </span>
                    </span>
                </span>
                <span class="ims-chart-label">{{ $bar['label'] }}</span>
            </button>
        @endforeach
    </div>

    {{-- The picked month in words, for everyone and for screen readers. --}}
    <p class="ims-chart-readout" aria-live="polite">
        @foreach ($bars as $index => $bar)
            <span x-show="picked === {{ $index }}" @if ($index !== $start) x-cloak @endif>
                {{ $bar['long'] }}: <strong><x-ims.money :amount="$bar['value']" :currency="$currency" :signed="(float) $bar['value'] < 0" /></strong>
            </span>
        @endforeach
    </p>

    @if ($link && $linkLabel)
        <span class="sr-only">{{ $linkLabel }}</span>
    @endif
</section>
