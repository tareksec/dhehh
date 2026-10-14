@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@section('content')
    @php
        $page = \App\Support\StorefrontTrackPage::make($company, $setting, $order, $previewSlug ?? null);
        $money = fn (float $amount) => \App\Support\MoneyFormatter::currency($amount, $page->currency);
        $homeUrl = \App\Support\StorefrontUrl::home($previewSlug ?? null);
        $helpline = filled($setting->marketplace_helpline) ? trim((string) $setting->marketplace_helpline) : (filled($setting->phone_number) ? trim((string) $setting->phone_number) : null);
        $helplineHref = $helpline ? 'tel:'.preg_replace('/[^\d+]/', '', $helpline) : null;
        $stateText = [
            \App\Support\StorefrontTrackPage::STATE_DONE => __('Completed'),
            \App\Support\StorefrontTrackPage::STATE_CURRENT => __('Current step'),
            \App\Support\StorefrontTrackPage::STATE_TODO => __('Not reached yet'),
        ];
    @endphp

    <div class="mp-shell py-4 sm:py-6">
        <nav class="mp-breadcrumb" aria-label="{{ __('Breadcrumb') }}">
            <ol>
                <li><a href="{{ $homeUrl }}">{{ __('Home') }}</a></li>
                <li aria-current="page">{{ __('Track order') }}</li>
            </ol>
        </nav>

        <h1 class="mp-page-title mt-3">{{ __('Track your order') }}</h1>
        <p class="mt-1 text-sm mp-muted">{{ __('Enter your order number to see the latest order, delivery, and courier information from :store.', ['store' => $company->name]) }}</p>

        <form class="mp-panel mt-5" method="GET" action="{{ $page->lookupUrl }}" aria-labelledby="mp-track-lookup" data-track-form>
            <h2 id="mp-track-lookup" class="sr-only">{{ __('Find your order') }}</h2>
            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                <label class="mp-field">
                    <span class="mp-field-label">{{ __('Order number') }}</span>
                    <input id="track-order-number" class="mp-input" name="order_number" autocomplete="off" spellcheck="false" required value="{{ $orderNumber }}" placeholder="{{ __('Example: :number', ['number' => $company->invoice_prefix.'-'.now()->format('Ymd').'-001']) }}" @if (! empty($notFound)) aria-invalid="true" aria-describedby="track-not-found" @endif>
                </label>
                <label class="mp-field">
                    <span class="mp-field-label">{{ __('Phone number used on the order') }}</span>
                    <input id="track-phone" class="mp-input" type="tel" inputmode="tel" name="phone" autocomplete="tel" value="{{ $phone }}" placeholder="01XXXXXXXXX" @if (! empty($notFound)) aria-invalid="true" aria-describedby="track-not-found" @endif>
                </label>
                <button class="mp-button mp-button-accent mp-button-lg" type="submit">{{ __('Track order') }}</button>
            </div>
            <p class="mt-3 text-xs mp-muted">{{ __('For your privacy, enter the phone number used when placing the order to view its details.') }}</p>

            @if (! empty($notFound))
                <div id="track-not-found" class="mp-note is-danger mt-4" role="alert" data-track-not-found>
                    {{ __("We couldn't find an order matching that order number and phone number. Please check both and try again.") }}
                </div>
            @endif
        </form>

        @if (! $order)
            @if (empty($notFound))
                <div class="mp-empty mt-5">
                    <p class="text-base font-semibold">{{ __('Enter an order number') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-sm mp-muted">{{ __('After checkout, use the order number from the success page or invoice to track status here.') }}</p>
                </div>
            @endif
        @else
            <section class="mp-panel mt-5" aria-labelledby="mp-track-order" data-track-result>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs mp-muted">{{ __('Order number') }}</p>
                        <h2 id="mp-track-order" class="mt-0.5 text-xl font-bold" data-track-order-number>{{ $order->order_number }}</h2>
                        @if ($page->orderDate)
                            <p class="mt-0.5 text-sm mp-muted">{{ __('Placed on :date', ['date' => $page->orderDate]) }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 sm:text-end">
                        <span class="mp-stock-pill {{ $page->exceptionMessage ? 'is-out' : ($page->isDelivered ? 'is-in' : 'is-preorder') }} text-xs" data-track-status>{{ $page->statusLabel }}</span>
                        <div>
                            <p class="text-xs mp-muted">{{ __('Order total') }}</p>
                            <p class="text-xl font-extrabold" data-track-total>{{ $money($page->total) }}</p>
                        </div>
                    </div>
                </div>

                @if ($page->exceptionMessage)
                    <div class="mp-note is-danger mt-5" role="status" data-track-exception>{{ $page->exceptionMessage }}</div>
                @elseif ($page->isDelivered)
                    <div class="mp-note is-success mt-5" role="status" data-track-delivered>{{ __('Your order has been delivered.') }}</div>
                @endif

                <ol class="mp-track-steps mt-6" aria-label="{{ __('Order progress') }}">
                    @foreach ($page->steps as $step)
                        <li class="mp-track-step is-{{ $step['state'] }}" data-track-step="{{ $step['key'] }}" data-state="{{ $step['state'] }}" @if ($step['state'] === \App\Support\StorefrontTrackPage::STATE_CURRENT) aria-current="step" @endif>
                            <span class="mp-track-dot" aria-hidden="true">
                                @if ($step['state'] === \App\Support\StorefrontTrackPage::STATE_DONE)
                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                @else
                                    {{ $loop->iteration }}
                                @endif
                            </span>
                            <span class="mp-track-label">
                                {{ $step['label'] }}
                                <span class="sr-only">({{ $stateText[$step['state']] }})</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            </section>

            <div class="mt-4 lg:grid lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start lg:gap-6">
                <div class="grid gap-4">
                    @if ($page->hasDeliveryUpdate || $page->courier)
                        <section class="mp-panel" aria-labelledby="mp-track-delivery">
                            <h2 id="mp-track-delivery" class="text-base font-bold">{{ __('Delivery update') }}</h2>
                            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs mp-muted">{{ __('Delivery') }}</dt>
                                    <dd class="mt-0.5 font-semibold" data-track-delivery-status>{{ $page->deliveryStatusLabel }}</dd>
                                </div>
                                @if ($page->courier)
                                    @if ($page->courier['provider'])
                                        <div>
                                            <dt class="text-xs mp-muted">{{ __('Courier') }}</dt>
                                            <dd class="mt-0.5 font-semibold">{{ $page->courier['provider'] }}</dd>
                                        </div>
                                    @endif
                                    <div>
                                        <dt class="text-xs mp-muted">{{ __('Tracking ID') }}</dt>
                                        <dd class="mt-0.5 font-semibold break-all" data-track-courier-id>{{ $page->courier['trackingId'] ?: __('Pending') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs mp-muted">{{ __('Booking status') }}</dt>
                                        <dd class="mt-0.5 font-semibold">{{ $page->courier['statusLabel'] }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </section>
                    @endif

                    @if ($trackingUpdates->isNotEmpty())
                        <section class="mp-panel" aria-labelledby="mp-track-updates" data-track-updates>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 id="mp-track-updates" class="text-base font-bold">{{ __('Tracking Updates') }}</h2>
                                <span class="text-xs mp-muted">{{ trans_choice(':count update|:count updates', $trackingUpdates->count(), ['count' => $trackingUpdates->count()]) }}</span>
                            </div>
                            <ol class="mp-timeline mt-4">
                                @foreach ($trackingUpdates as $update)
                                    <li class="{{ $loop->first ? 'is-latest' : '' }}">
                                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold">
                                                    {{ $update['title'] }}
                                                    @if ($loop->first)
                                                        <span class="mp-stock-pill is-in ms-1 text-[0.65rem]">{{ __('Latest') }}</span>
                                                    @endif
                                                </p>
                                                <p class="mt-0.5 text-sm mp-muted">{{ $update['message'] }}</p>
                                            </div>
                                            @if ($update['time'])
                                                <time class="shrink-0 text-xs mp-muted" datetime="{{ $update['time']->toIso8601String() }}">{{ $update['time']->translatedFormat('d M Y, h:i A') }}</time>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endif

                    <section class="mp-panel" aria-labelledby="mp-track-items">
                        <h2 id="mp-track-items" class="text-base font-bold">{{ __('Order items') }}</h2>
                        <ul class="mp-summary-items !max-h-none">
                            @foreach ($page->items as $item)
                                <li data-track-item>
                                    <span class="mp-summary-thumb" aria-hidden="true">
                                        @if ($item['imageUrl'])
                                            <img src="{{ $item['imageUrl'] }}" alt="" width="96" height="96" loading="lazy" decoding="async">
                                        @else
                                            <span>{{ mb_substr($item['name'], 0, 1) }}</span>
                                        @endif
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold">{{ $item['name'] }}</span>
                                        @if ($item['variant'])
                                            <span class="block text-xs mp-muted">{{ $item['variant'] }}</span>
                                        @endif
                                        <span class="block text-xs mp-muted">{{ $money($item['unitPrice']) }} &times; {{ $item['quantity'] }}</span>
                                    </span>
                                    <span class="shrink-0 text-sm font-bold">{{ $money($item['lineTotal']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                </div>

                <aside class="mt-4 grid gap-4 lg:sticky lg:top-24 lg:mt-0" aria-label="{{ __('Order summary') }}">
                    <section class="mp-panel" aria-labelledby="mp-track-summary">
                        <h2 id="mp-track-summary" class="text-base font-bold">{{ __('Order summary') }}</h2>
                        <dl class="mp-summary-rows">
                            @if ($page->showsBreakdown)
                                <div><dt>{{ __('Subtotal') }}</dt><dd>{{ $money($page->subtotal) }}</dd></div>
                                @if ($page->discount > 0)
                                    <div><dt>{{ __('Discount') }}</dt><dd>- {{ $money($page->discount) }}</dd></div>
                                @endif
                                @if ($page->vat > 0)
                                    <div><dt>{{ $page->vatLabel }}</dt><dd>{{ $money($page->vat) }}</dd></div>
                                @endif
                                <div><dt>{{ __('Delivery charge') }}</dt><dd>{{ $money($page->shippingFee) }}</dd></div>
                                @if ($page->packagingFee > 0)
                                    <div><dt>{{ __('Packaging fee') }}</dt><dd>{{ $money($page->packagingFee) }}</dd></div>
                                @endif
                            @endif
                            <div class="is-total"><dt>{{ __('Total') }}</dt><dd>{{ $money($page->total) }}</dd></div>
                            @if ($page->showsBreakdown)
                                @if ($page->paid > 0)
                                    <div class="mp-muted"><dt>{{ __('Paid') }}</dt><dd>{{ $money($page->paid) }}</dd></div>
                                @endif
                                <div class="font-bold"><dt>{{ __('Amount due on delivery') }}</dt><dd data-track-due>{{ $money($page->due) }}</dd></div>
                            @endif
                        </dl>
                    </section>

                    @if (! $page->exceptionMessage && ! $page->isDelivered && ($page->areaLabel || $page->deliveryTime))
                        <section class="mp-panel" aria-labelledby="mp-track-area">
                            <h2 id="mp-track-area" class="text-base font-bold">{{ __('Delivery details') }}</h2>
                            <dl class="mt-3 grid gap-3 text-sm">
                                @if ($page->areaLabel)
                                    <div><dt class="text-xs mp-muted">{{ __('Delivery area') }}</dt><dd class="mt-0.5 font-semibold">{{ $page->areaLabel }}</dd></div>
                                @endif
                                @if ($page->deliveryTime)
                                    <div data-track-delivery-time><dt class="text-xs mp-muted">{{ __('Delivery time') }}</dt><dd class="mt-0.5 font-semibold">{{ $page->deliveryTime }}</dd></div>
                                @endif
                            </dl>
                        </section>
                    @endif

                    <section class="mp-panel" aria-labelledby="mp-track-help">
                        <h2 id="mp-track-help" class="text-base font-bold">{{ __('Need help with this order?') }}</h2>
                        <p class="mt-2 text-sm mp-muted">{{ __('Report damaged products, wrong items, or a quantity mismatch using this order ID.') }}</p>
                        <div class="mt-4 grid gap-2">
                            <a class="mp-button mp-button-outline" href="{{ $page->complaintUrl }}">{{ __('Submit a complaint') }}</a>
                            @if ($helplineHref)
                                <a class="mp-button mp-button-outline" href="{{ $helplineHref }}">{{ __('Call :phone', ['phone' => $helpline]) }}</a>
                            @endif
                            <a class="mp-button mp-button-accent" href="{{ $page->productsUrl }}">{{ __('Continue shopping') }}</a>
                        </div>
                    </section>
                </aside>
            </div>
        @endif
    </div>
@endsection
