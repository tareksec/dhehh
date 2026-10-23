{{-- The reports list (Reports): one row per report, each with its icon and an arrow. --}}
<x-filament-panels::page>
    <nav class="trade-links trade-reports" aria-label="{{ __('Reports') }}">
        @foreach ($reports as $report)
            <a href="{{ $report['url'] }}" class="trade-link-row">
                <span class="trade-tile-icon"><x-filament::icon :icon="$report['icon']" class="h-6 w-6" /></span>
                <span class="trade-link-label">{{ $report['title'] }}</span>
                <x-filament::icon icon="heroicon-m-chevron-right" class="trade-link-arrow" />
            </a>
        @endforeach
    </nav>
</x-filament-panels::page>
