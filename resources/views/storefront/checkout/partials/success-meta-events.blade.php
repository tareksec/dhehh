{{-- Meta Pixel Purchase for checkout.success, shared by every theme. Needs $setting and $order. --}}
@push('meta-events')
    @php
        $metaTracking = app(\App\Services\StorefrontMetaTrackingService::class);
    @endphp
    @if (! isset($previewSlug) && $metaTracking->shouldFireBrowserPurchase($setting, $order, request()))
        <script>
            fbq(
                'track',
                'Purchase',
                {{ Illuminate\Support\Js::from($metaTracking->purchasePayload($order)) }},
                {eventID: {{ Illuminate\Support\Js::from($metaTracking->purchaseEventId($order)) }}}
            );
        </script>
    @endif
@endpush
