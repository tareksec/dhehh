{{-- Meta Pixel listing events, shared by every theme's products.index. --}}
@push('meta-events')
    @php
        $metaTracking = app(\App\Services\StorefrontMetaTrackingService::class);
        $metaListData = [
            'content_ids' => $products->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
            'content_type' => 'product',
            'content_category' => $category?->name,
            'search_string' => ($search ?? '') !== '' ? $search : null,
        ];
    @endphp
    @if (! isset($previewSlug) && $category && $metaTracking->browserEventEnabled($setting, 'ViewCategory', request()))
        <script>fbq('trackCustom', 'ViewCategory', {{ Illuminate\Support\Js::from(array_filter($metaListData)) }}, {eventID: {{ Illuminate\Support\Js::from($metaTracking->eventId('view-category')) }}});</script>
    @endif
    @if (! isset($previewSlug) && $metaTracking->browserEventEnabled($setting, 'ViewItemList', request()))
        <script>fbq('trackCustom', 'ViewItemList', {{ Illuminate\Support\Js::from(array_filter($metaListData)) }}, {eventID: {{ Illuminate\Support\Js::from($metaTracking->eventId('view-item-list')) }}});</script>
    @endif
    @if (! isset($previewSlug) && ($search ?? '') !== '' && $metaTracking->browserEventEnabled($setting, 'Search', request()))
        <script>fbq('track', 'Search', {{ Illuminate\Support\Js::from(array_filter($metaListData)) }}, {eventID: {{ Illuminate\Support\Js::from($metaTracking->eventId('search')) }}});</script>
    @endif
@endpush
