{{-- Meta Pixel ViewCart for cart.show, shared by every theme. Needs $items and $subtotal. --}}
@push('meta-events')
    @php
        $metaTracking = app(\App\Services\StorefrontMetaTrackingService::class);
    @endphp
    @if (! isset($previewSlug) && $metaTracking->browserEventEnabled($setting, 'ViewCart', request()))
        <script>
            fbq('track', 'ViewCart', {{ Illuminate\Support\Js::from([
                'content_ids' => collect($items)->map(fn ($item) => (string) $item['product']->getKey())->unique()->values()->all(),
                'content_type' => 'product',
                'currency' => 'BDT',
                'value' => round((float) $subtotal, 2),
                'num_items' => collect($items)->sum('quantity'),
            ]) }}, {eventID: {{ Illuminate\Support\Js::from($metaTracking->eventId('view-cart')) }}});
        </script>
    @endif
@endpush
