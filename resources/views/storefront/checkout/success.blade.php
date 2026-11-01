@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

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

@section('content')
    @php
        $advancePayment = $order->storefrontPayments()->latest()->first();
        // 'manual_bkash'/'manual_nagad' are legacy literal gateway values
        // from before payment methods became dashboard-managed
        // (StorefrontPaymentMethod) - kept here so historical orders still
        // display correctly. New manual payments use the generic 'manual'
        // gateway with the channel's own name via the paymentMethod relation.
        $isManualPayment = $advancePayment && in_array($advancePayment->gateway, ['manual', 'manual_bkash', 'manual_nagad'], true);
        $manualPaymentLabel = match (true) {
            ! $isManualPayment => null,
            $advancePayment->gateway === 'manual_bkash' => 'bKash',
            $advancePayment->gateway === 'manual_nagad' => 'Nagad',
            default => $advancePayment->paymentMethod?->name ?? 'Manual',
        };
        // The "pay the full order online" checkout option reuses the same
        // purpose as a partial advance (StorefrontPayment::PURPOSE_CHECKOUT_ADVANCE)
        // - amount fully covering the order total is what actually
        // distinguishes it, so it isn't mislabelled as an "advance".
        $isFullyPaidOnline = $advancePayment && ! $isManualPayment
            && (float) $advancePayment->amount >= (float) $order->total_amount - 0.01;
        $trackUrl = isset($previewSlug)
            ? route('storefront.preview.track.show', ['company' => $previewSlug, 'orderNo' => $order->order_number, 'phone' => $order->customer?->phone])
            : Illuminate\Support\Facades\URL::temporarySignedRoute('storefront.track.show', now()->addDays(7), ['orderNo' => $order->order_number]);
        $orderHistoryUrl = \App\Support\StorefrontUrl::route('account.orders', [], $previewSlug ?? null);
        $showOrderHistoryLink = isset($previewSlug) || (bool) ($setting->customer_accounts_enabled ?? true);
        $complaintUrl = \App\Support\StorefrontUrl::route('complaints.show', ['order' => $order->order_number], $previewSlug ?? null);
    @endphp

    <section class="mx-auto w-full max-w-5xl px-4 py-8 text-center sm:px-5 lg:px-6">
        <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">
            <svg class="h-8 w-8" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
        </div>
        <p class="mt-6 text-xs font-semibold uppercase tracking-wider text-[var(--storefront-brand)]">{{ __('Order submitted') }}</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">Thank you, {{ $order->customer?->name }}.</h1>
        <p class="storefront-page-copy mx-auto mt-3">Order <span class="font-semibold text-gray-950 dark:text-white">{{ $order->order_number }}</span> has been received. Our team will review and confirm it shortly.</p>

        <div class="mt-8 grid gap-4 text-left md:grid-cols-[minmax(0,1fr)_280px]">
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-white/5">
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 pb-5 dark:border-white/10">
                    <div><div class="text-xs font-medium text-gray-400">Status</div><div class="mt-1 text-base font-semibold">{{ ucfirst($order->status) }}</div></div>
                    <div class="text-right"><div class="text-xs font-medium text-gray-400">Total</div><div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::currency((float) $order->total_amount) }}</div></div>
                </div>
                <div class="mt-5 space-y-3">
                    @foreach ($order->items as $item)
                        <div class="flex justify-between gap-4 text-sm">
                            <span class="text-gray-600 dark:text-gray-300">{{ $item->product?->name }}{{ $item->variant_label ? ' ('.$item->variant_label.')' : '' }} &times; {{ $item->quantity }}</span>
                            <span class="font-semibold">{{ \App\Support\MoneyFormatter::currency((float) $order->total_amount) }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-5 space-y-2 border-t border-gray-200 pt-4 text-sm dark:border-white/10">
                    <div class="flex justify-between text-gray-600 dark:text-gray-300"><span>Subtotal</span><span>{{ \App\Support\MoneyFormatter::currency((float) $order->total_amount) }}</span></div>
                    <div class="flex justify-between text-gray-600 dark:text-gray-300"><span>Delivery charge</span><span>{{ \App\Support\MoneyFormatter::currency((float) $order->total_amount) }}</span></div>
                    <div class="flex justify-between font-semibold text-gray-950 dark:text-white"><span>Amount due on delivery</span><span>{{ \App\Support\MoneyFormatter::currency((float) $order->total_amount) }}</span></div>
                </div>
            </div>
            <aside class="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Customer & delivery') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">Name</dt><dd class="mt-1 font-medium">{{ $order->customer?->name }}</dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">Phone</dt><dd class="mt-1 font-medium">{{ $order->customer?->phone }}</dd></div>
                    @if ($order->customer?->email)<div><dt class="text-xs text-gray-500 dark:text-gray-400">Email</dt><dd class="mt-1 break-all font-medium">{{ $order->customer->email }}</dd></div>@endif
                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">Delivery area</dt><dd class="mt-1 font-medium">{{ $order->shipping_zone === 'inside' ? 'Inside Dhaka' : 'Outside Dhaka' }}</dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">Address</dt><dd class="mt-1 leading-5">{{ $order->customer?->address }}</dd></div>
                </dl>
            </aside>
        </div>

        @if ($advancePayment)
            <div class="mt-4 rounded-xl border px-5 py-4 text-left text-sm {{ $advancePayment->status === 'completed' ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-200' : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200' }}">
                <div class="flex flex-wrap justify-between gap-2 font-semibold">
                    <span>{{ $isManualPayment ? "{$manualPaymentLabel} payment" : ($isFullyPaidOnline ? 'Paid online in full' : ($advancePayment->purpose === \App\Models\StorefrontPayment::PURPOSE_NEW_CUSTOMER_DELIVERY ? 'Delivery advance payment' : 'Pre-order advance payment')) }}</span>
                    <span>{{ \App\Support\MoneyFormatter::currency((float) $order->total_amount) }} &middot; {{ \App\Models\StorefrontPayment::STATUSES[$advancePayment->status] ?? ucfirst($advancePayment->status) }}</span>
                </div>
                @if ($advancePayment->status !== 'completed')<p class="mt-1 text-xs">{{ $isManualPayment ? 'We are verifying your payment and will contact you if needed.' : 'The payment status updates automatically after gateway verification.' }}</p>@endif
            </div>
        @endif

        <div class="mt-6 grid gap-4 text-left md:grid-cols-2">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-400/20 dark:bg-emerald-400/10">
                <h2 class="font-semibold text-emerald-950 dark:text-emerald-100">{{ __('Get product and stock updates') }}</h2>
                <p class="mt-2 text-sm leading-6 text-emerald-800 dark:text-emerald-200">{{ $setting->whatsappGroupMessage() }}</p>
                <a class="mt-4 inline-flex min-h-11 items-center rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-800" href="{{ $setting->whatsappGroupUrl() }}" target="_blank" rel="noopener">Join WhatsApp group</a>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
                <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('Problem with this parcel?') }}</h2>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('Report damaged products, wrong items, or a quantity mismatch using this order ID.') }}</p>
                <a class="mt-4 inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-medium text-gray-900 transition hover:border-[var(--storefront-brand)] dark:border-white/15 dark:text-white" href="{{ $complaintUrl }}">Submit a complaint</a>
            </div>
        </div>

        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a class="inline-flex min-h-11 items-center rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90" href="{{ $trackUrl }}">Track this order</a>
            <a class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-6 py-3 text-sm font-medium text-gray-900 transition hover:border-[var(--storefront-brand)] dark:border-white/10 dark:text-white" href="{{ \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null) }}">Continue shopping</a>
        </div>
        @if ($showOrderHistoryLink)<p class="mt-5 text-sm text-gray-500 dark:text-gray-400">Want to see all your storefront orders? Visit <a class="font-medium text-[var(--storefront-brand)]" href="{{ $orderHistoryUrl }}">order history</a>.</p>@endif
    </section>
@endsection
