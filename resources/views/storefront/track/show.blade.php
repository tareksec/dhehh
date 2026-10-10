@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@php
    $page = \App\Support\StorefrontTrackPage::make($company, $setting, $order, $previewSlug ?? null);
    $currency = $page->currency;
    $trackIndexUrl = $page->lookupUrl;
    $booking = $page->courier;
    $orderStatusLabel = $order ? (__(App\Models\Order::STATUSES[$order->status] ?? ucfirst((string) $order->status))) : null;
    $deliveryStatus = $page->deliveryStatus;
    $deliveryStatusLabel = $page->deliveryStatusLabel;
    $hasDeliveryUpdate = $order && $page->hasDeliveryUpdate;
    $orderSteps = [
        ['key' => 'submitted', 'label' => __('Order submitted'), 'done' => (bool) $order],
        ['key' => 'confirmed', 'label' => __('Admin confirmed'), 'done' => $order && in_array($order->status, ['confirmed', 'completed'], true)],
    ];
@endphp

@section('content')
    <section class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-5 lg:px-6">
        <div class="grid gap-6 lg:grid-cols-[0.8fr_1.2fr]">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-[var(--storefront-brand)]">{{ __('Order tracking') }}</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Track your storefront order.') }}</h1>
                <p class="storefront-page-copy mt-3">
                    {{ __('Enter your order number to see the latest order, delivery, and courier information from :store.', ['store' => $company->name]) }}
                </p>

                <form class="mt-5 rounded-lg border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900" method="GET" action="{{ $trackIndexUrl }}">
                    <label class="text-xs font-medium text-gray-500" for="order_number">{{ __('Order number') }}</label>
                    <input
                        class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950"
                        id="order_number"
                        name="order_number"
                        placeholder="{{ __('Example: :number', ['number' => $company->invoice_prefix.'-'.now()->format('Ymd').'-001']) }}"
                        type="text"
                        value="{{ $orderNumber }}"
                    >

                    <label class="mt-4 block text-xs font-medium text-gray-500" for="phone">{{ __('Phone number used on the order') }}</label>
                    <div class="mt-2 flex flex-col gap-3 sm:flex-row">
                        <input
                            class="min-h-11 flex-1 rounded-lg border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/10 dark:bg-gray-950"
                            id="phone"
                            name="phone"
                            placeholder="01XXXXXXXXX"
                            type="tel"
                            inputmode="tel"
                            autocomplete="tel"
                            value="{{ $phone }}"
                        >
                        <button class="rounded-lg bg-[var(--storefront-brand)] px-6 py-2.5 text-sm font-medium text-white transition hover:opacity-90" type="submit">
                            {{ __('Track order') }}
                        </button>
                    </div>
                    <p class="mt-3 text-xs leading-5 text-gray-400">{{ __('For your privacy, enter the phone number used when placing the order to view its details.') }}</p>

                    @if (! empty($notFound))
                        <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200">
                            {{ __("We couldn't find an order matching that order number and phone number. Please check both and try again.") }}
                        </p>
                    @endif
                </form>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-white/5">
                @if (! $order)
                    <div class="grid min-h-80 place-items-center text-center">
                        <div>
                            <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-gray-100 text-xl text-gray-500 dark:bg-white/10 dark:text-gray-300">#</div>
                            <h2 class="mt-5 text-xl font-semibold tracking-tight">{{ __('Enter an order number') }}</h2>
                            <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-gray-500 dark:text-gray-400">
                                {{ __('After checkout, use the order number from the success page or invoice to track status here.') }}
                            </p>
                        </div>
                    </div>
                @else
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 pb-5 dark:border-white/10">
                        <div>
                            <div class="text-xs font-medium text-gray-400">{{ __('Order number') }}</div>
                            <h2 class="mt-1 text-xl font-semibold tracking-tight">{{ $order->order_number }}</h2>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ optional($order->order_date)->format('d M Y') }}</p>
                        </div>
                        <div class="text-right">
                            @if ($order->showsResellerPricesToBuyer())
                                {{-- A reseller's customer only ever sees the reseller's prices. --}}
                                <div class="text-xs font-medium text-gray-400">{{ __('Order total') }}</div>
                                <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::currency($order->buyerTotal(), $currency) }}</div>
                            @else
                                <div class="text-xs font-medium text-gray-400">{{ __('Total due') }}</div>
                                <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::currency((float) $order->due_amount, $currency) }}</div>
                            @endif
                        </div>
                    </div>

                    <div class="mt-6 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-950">
                            <div class="text-xs font-medium text-gray-400">{{ __('Order status') }}</div>
                            <div class="mt-1 text-base font-semibold">{{ $orderStatusLabel }}</div>
                        </div>
                        @if ($hasDeliveryUpdate)
                            <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-950">
                                <div class="text-xs font-medium text-gray-400">{{ __('Delivery') }}</div>
                                <div class="mt-1 text-base font-semibold">{{ $deliveryStatusLabel }}</div>
                            </div>
                        @endif
                        @if ($booking)
                            <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-950">
                                <div class="text-xs font-medium text-gray-400">{{ __('Courier') }}</div>
                                <div class="mt-1 text-base font-semibold">{{ $booking['provider'] ?? __('Booked') }}</div>
                            </div>
                        @endif
                    </div>

                    <div class="mt-8">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400">{{ __('Order progress') }}</h3>
                        <div class="mt-3 space-y-2">
                            @foreach ($orderSteps as $step)
                                <div class="flex items-center gap-3 rounded-lg border px-4 py-3 {{ $step['done'] ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-200' : 'border-gray-200 bg-gray-50 text-gray-500 dark:border-white/10 dark:bg-gray-950 dark:text-gray-400' }}">
                                    <span class="grid h-7 w-7 place-items-center rounded-full {{ $step['done'] ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-500 dark:bg-white/10' }}">
                                        {{ $step['done'] ? '✓' : '•' }}
                                    </span>
                                    <span class="text-sm font-medium">{{ $step['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if ($hasDeliveryUpdate)
                        <div class="mt-8">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400">{{ __('Delivery update') }}</h3>
                            <div class="mt-3 flex items-center gap-3 rounded-lg border border-[var(--storefront-brand)] bg-[var(--storefront-brand)]/10 px-4 py-3 text-gray-950 dark:text-white">
                                <span class="grid h-7 w-7 place-items-center rounded-full bg-[var(--storefront-brand)] text-white">●</span>
                                <span class="text-sm font-medium">{{ $deliveryStatusLabel }}</span>
                            </div>
                        </div>
                    @endif

                    @if ($booking)
                        <div class="mt-8 rounded-xl border border-gray-200 p-5 dark:border-white/10">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400">{{ __('Courier details') }}</h3>
                            <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <span class="block font-medium text-gray-900 dark:text-white">{{ __('Tracking ID') }}</span>
                                    <span class="text-gray-600 dark:text-gray-300">{{ $booking['trackingId'] ?: __('Pending') }}</span>
                                </div>
                                <div>
                                    <span class="block font-medium text-gray-900 dark:text-white">{{ __('Booking status') }}</span>
                                    <span class="text-gray-600 dark:text-gray-300">{{ $booking['statusLabel'] }}</span>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($trackingUpdates->isNotEmpty())
                        <div class="mt-10 rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-white/5 sm:p-6">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-5 dark:border-white/10">
                                <h3 class="text-lg font-semibold tracking-tight">{{ __('Tracking Updates') }}</h3>
                                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                    {{ trans_choice(':count update|:count updates', $trackingUpdates->count(), ['count' => $trackingUpdates->count()]) }}
                                </span>
                            </div>

                            <div class="mt-6">
                                <div class="space-y-3">
                                    @foreach ($trackingUpdates as $update)
                                        @php
                                            $isLatest = $loop->first;
                                            $isDelivery = $update['type'] === 'delivery';
                                        @endphp
                                        <div class="relative grid gap-3 sm:grid-cols-[2.25rem_1fr]">
                                            <div class="relative hidden justify-center sm:flex">
                                                @unless ($loop->last)
                                                    <span class="absolute left-1/2 top-10 h-[calc(100%+0.75rem)] w-px -translate-x-1/2 bg-gray-200 dark:bg-white/10"></span>
                                                @endunless
                                                <span class="relative z-10 mt-4 grid h-8 w-8 place-items-center rounded-full border-4 border-white text-sm dark:border-gray-950 {{ $isDelivery ? 'bg-emerald-500 text-white' : 'bg-sky-500 text-white' }}">
                                                    {{ $isDelivery ? '✓' : '↗' }}
                                                </span>
                                            </div>

                                            <article class="rounded-lg border p-4 transition {{ $isLatest ? 'border-[var(--storefront-brand)] bg-[var(--storefront-brand)]/5' : 'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-950' }}">
                                                <div class="flex gap-3 sm:gap-4">
                                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm sm:hidden {{ $isDelivery ? 'bg-emerald-500 text-white' : 'bg-sky-500 text-white' }}">
                                                        {{ $isDelivery ? '✓' : '↗' }}
                                                    </span>

                                                    <div class="min-w-0 flex-1">
                                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                                        <div>
                                                            <h4 class="font-semibold leading-6">{{ $update['title'] }}</h4>
                                                            <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $update['message'] }}</p>
                                                        </div>

                                                        <div class="flex shrink-0 items-center gap-2">
                                                            @if ($isLatest)
                                                                <span class="rounded-full bg-[var(--storefront-brand)] px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">{{ __('Latest') }}</span>
                                                            @endif
                                                            <time class="rounded-lg bg-white px-3 py-2 text-right text-xs font-medium text-gray-500 shadow-sm dark:bg-white/10 dark:text-gray-300" datetime="{{ $update['time']->toIso8601String() }}">
                                                                <span class="block">{{ $update['time']->format('M d, Y') }}</span>
                                                                <span class="block text-[var(--storefront-brand)]">{{ $update['time']->format('h:i A') }}</span>
                                                            </time>
                                                        </div>
                                                    </div>
                                                </div>
                                                </div>
                                            </article>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="mt-8">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400">{{ __('Order items') }}</h3>
                        <div class="mt-3 space-y-3">
                            @foreach ($page->items as $item)
                                <div class="flex justify-between gap-4 rounded-lg bg-gray-50 p-4 text-sm dark:bg-gray-950">
                                    <div>
                                        <div class="font-medium">{{ $item['name'] }}</div>
                                        <div class="mt-1 text-gray-500">{{ __('Qty :count', ['count' => $item['quantity']]) }}</div>
                                    </div>
                                    <div class="font-semibold">{{ \App\Support\MoneyFormatter::currency($item['lineTotal'], $currency) }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
