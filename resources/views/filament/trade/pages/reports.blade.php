{{-- The reports list (Reports): one tile per report. --}}
<x-filament-panels::page>
    <div class="trade-quick trade-reports">
        @foreach ($reports as $report)
            <a href="{{ $report['url'] }}" class="trade-quick-btn">
                <span class="trade-tile-icon"><x-filament::icon :icon="$report['icon']" class="h-6 w-6" /></span>
                <span>{{ $report['title'] }}</span>
            </a>
        @endforeach
    </div>
</x-filament-panels::page>
