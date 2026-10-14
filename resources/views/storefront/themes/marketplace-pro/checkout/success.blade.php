@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@include('storefront.checkout.partials.success-meta-events')

@section('content')
    @php
        $success = \App\Support\StorefrontOrderSuccessPage::make($order, $setting, $previewSlug ?? null);
        $payment = $success->payment;
        $money = fn (float $amount) => \App\Support\MoneyFormatter::currency($amount);
        $customer = $order->customer;
        $areaLabel = $order->shipping_zone === 'inside' ? __('Inside Dhaka') : __('Outside Dhaka');
        $deliveryTime = $order->shipping_zone ? $setting->deliveryTimeFor($order->shipping_zone) : null;
        $paymentTitle = match (true) {
            $success->isManualPayment => __(':method payment', ['method' => $success->manualPaymentLabel]),
            $success->isFullyPaidOnline => __('Paid online in full'),
            $payment?->purpose === \App\Models\StorefrontPayment::PURPOSE_NEW_CUSTOMER_DELIVERY => __('Delivery advance payment'),
            default => __('Pre-order advance payment'),
        };
        $paymentDone = $payment?->status === \App\Models\StorefrontPayment::STATUS_COMPLETED;
    @endphp

    <div class="mp-shell py-6 sm:py-10">
        <div class="mx-auto max-w-3xl text-center">
            <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-[color-mix(in_srgb,#1a7f37_14%,transparent)] text-[#1a7f37]">
                <svg class="h-8 w-8" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
            </div>
            <p class="mt-5 text-xs font-bold uppercase tracking-wider text-[var(--storefront-accent)]">{{ __('Order placed') }}</p>
            <h1 class="mp-page-title mt-2">{{ __('Thank you, :name.', ['name' => $customer?->name]) }}</h1>
            <p class="mt-3 text-sm mp-muted">
                {{ __('Order :number has been received. Our team will review and confirm it shortly.', ['number' => $order->order_number]) }}
            </p>
        </div>

        <div class="mx-auto mt-8 grid max-w-5xl gap-4 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
            <section class="mp-panel" aria-labelledby="mp-success-order">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--storefront-border)] pb-4">
                    <div>
                        <h2 id="mp-success-order" class="text-base font-bold">{{ __('Order details') }}</h2>
                        <p class="mt-0.5 text-xs mp-muted">{{ __('Order') }} <span class="font-bold text-[var(--storefront-text)]" data-order-number>{{ $order->order_number }}</span></p>
                    </div>
                    <span class="mp-stock-pill is-preorder text-xs" data-order-status>{{ $success->statusLabel() }}</span>
                </div>

                <ul class="mp-summary-items !max-h-none">
                    @foreach ($order->items as $item)
                        @php $itemImage = $item->product?->image ? \App\Support\CompanyMedia::publicUrl($item->product->image, $company) : null; @endphp
                        <li>
                            <span class="mp-summary-thumb" aria-hidden="true">
                                @if ($itemImage)
                                    <img src="{{ $itemImage }}" alt="" width="96" height="96" loading="lazy" decoding="async">
                                @else
                                    <span>{{ mb_substr((string) $item->product?->name, 0, 1) }}</span>
                                @endif
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">{{ $item->product?->name }}</span>
                                @if ($item->variant_label)
                                    <span class="block text-xs mp-muted">{{ $item->variant_label }}</span>
                                @endif
                                <span class="block text-xs mp-muted">{{ $money((float) $item->unit_price) }} &times; {{ $item->quantity }}</span>
                            </span>
                            <span class="shrink-0 text-sm font-bold">{{ $money((float) $item->subtotal) }}</span>
                        </li>
                    @endforeach
                </ul>

                <dl class="mp-summary-rows">
                    <div><dt>{{ __('Subtotal') }}</dt><dd>{{ $money($success->subtotal) }}</dd></div>
                    @if ($success->discount > 0)
                        <div><dt>{{ __('Discount') }}</dt><dd>- {{ $money($success->discount) }}</dd></div>
                    @endif
                    @if ($success->vat > 0)
                        <div><dt>{{ $setting->vatLabel() }}</dt><dd>{{ $money($success->vat) }}</dd></div>
                    @endif
                    <div><dt>{{ __('Delivery charge') }}</dt><dd>{{ $money($success->shippingFee) }}</dd></div>
                    @if ($success->packagingFee > 0)
                        <div><dt>{{ __('Packaging fee') }}</dt><dd>{{ $money($success->packagingFee) }}</dd></div>
                    @endif
                    <div class="is-total"><dt>{{ __('Total') }}</dt><dd data-order-total>{{ $money($success->total) }}</dd></div>
                    @if ($success->paid > 0)
                        <div class="mp-muted"><dt>{{ __('Paid') }}</dt><dd>{{ $money($success->paid) }}</dd></div>
                    @endif
                    <div class="font-bold"><dt>{{ __('Amount due on delivery') }}</dt><dd data-order-due>{{ $money($success->due) }}</dd></div>
                </dl>
            </section>

            <aside class="mp-panel" aria-labelledby="mp-success-delivery">
                <h2 id="mp-success-delivery" class="text-base font-bold">{{ __('Delivery details') }}</h2>
                <dl class="mt-4 grid gap-3 text-sm">
                    <div><dt class="text-xs mp-muted">{{ __('Name') }}</dt><dd class="mt-0.5 font-semibold">{{ $customer?->name }}</dd></div>
                    <div><dt class="text-xs mp-muted">{{ __('Phone') }}</dt><dd class="mt-0.5 font-semibold">{{ $customer?->phone }}</dd></div>
                    @if ($customer?->email)
                        <div><dt class="text-xs mp-muted">{{ __('Email') }}</dt><dd class="mt-0.5 break-all font-semibold">{{ $customer->email }}</dd></div>
                    @endif
                    <div><dt class="text-xs mp-muted">{{ __('Delivery area') }}</dt><dd class="mt-0.5 font-semibold">{{ $areaLabel }}</dd></div>
                    @if ($deliveryTime)
                        <div data-order-delivery-time><dt class="text-xs mp-muted">{{ __('Delivery time') }}</dt><dd class="mt-0.5 font-semibold">{{ $deliveryTime }}</dd></div>
                    @endif
                    <div><dt class="text-xs mp-muted">{{ __('Address') }}</dt><dd class="mt-0.5 leading-5">{{ $customer?->address }}</dd></div>
                </dl>
            </aside>
        </div>

        @if ($payment)
            <div class="mp-note {{ $paymentDone ? '' : 'is-neutral' }} mx-auto mt-4 max-w-5xl" data-order-payment>
                <div class="flex flex-wrap justify-between gap-2 font-bold">
                    <span>{{ $paymentTitle }}</span>
                    <span>{{ $money((float) $payment->amount) }} &middot; {{ __(\App\Models\StorefrontPayment::STATUSES[$payment->status] ?? ucfirst($payment->status)) }}</span>
                </div>
                @unless ($paymentDone)
                    <p class="mt-1 text-xs mp-muted">{{ $success->isManualPayment ? __('We are verifying your payment and will contact you if needed.') : __('The payment status updates automatically after gateway verification.') }}</p>
                @endunless
            </div>
        @endif

        <div class="mx-auto mt-4 grid max-w-5xl gap-4 md:grid-cols-2">
            <section class="mp-panel">
                <h2 class="text-base font-bold">{{ __('Get product and stock updates') }}</h2>
                <p class="mt-2 text-sm mp-muted">{{ $setting->whatsappGroupMessage() }}</p>
                <a class="mp-button mp-button-outline mt-4" href="{{ $setting->whatsappGroupUrl() }}" target="_blank" rel="noopener">{{ __('Join WhatsApp group') }}</a>
            </section>
            <section class="mp-panel">
                <h2 class="text-base font-bold">{{ __('Problem with this parcel?') }}</h2>
                <p class="mt-2 text-sm mp-muted">{{ __('Report damaged products, wrong items, or a quantity mismatch using this order ID.') }}</p>
                <a class="mp-button mp-button-outline mt-4" href="{{ $success->complaintUrl }}">{{ __('Submit a complaint') }}</a>
            </section>
        </div>

        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a class="mp-button mp-button-accent mp-button-lg" href="{{ $success->trackUrl }}">{{ __('Track this order') }}</a>
            <a class="mp-button mp-button-outline mp-button-lg" href="{{ $success->productsUrl }}">{{ __('Continue shopping') }}</a>
        </div>
        @if ($success->showOrderHistoryLink)
            <p class="mt-5 text-center text-sm mp-muted">
                {{ __('Want to see all your orders?') }}
                <a class="font-semibold text-[var(--storefront-brand)] hover:underline" href="{{ $success->orderHistoryUrl }}">{{ __('View order history') }}</a>
            </p>
        @endif
    </div>
@endsection
