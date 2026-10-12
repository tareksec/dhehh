{{-- Meta Pixel ViewContent, shared by every theme's products.show. --}}
@push('meta-events')
    @php
        $metaTracking = app(\App\Services\StorefrontMetaTrackingService::class);
    @endphp
    @if (! isset($previewSlug) && $metaTracking->browserEventEnabled($setting, 'ViewContent', request()))
        <script>
            fbq('track', 'ViewContent', {{ Illuminate\Support\Js::from([
                'content_ids' => [(string) $product->getKey()],
                'content_name' => $product->name,
                'content_type' => 'product',
                'currency' => 'BDT',
                'value' => round((float) $product->selling_price, 2),
            ]) }}, {eventID: {{ Illuminate\Support\Js::from($metaTracking->eventId('view-content')) }}});
        </script>
    @endif
@endpush
