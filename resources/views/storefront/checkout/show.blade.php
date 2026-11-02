@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@include('storefront.checkout.partials.checkout-meta-events')

@section('content')
    @php
        $page = \App\Support\StorefrontCheckoutPage::make(
            $setting,
            $paymentMethods,
            (float) ($advanceDue ?? 0),
            (bool) ($onlinePaymentAvailable ?? false),
            $insideQuote,
            $outsideQuote,
            $previewSlug ?? null,
            old('payment_method'),
        );
        // $paymentMethods is the dashboard-managed, company-scoped list from
        // CheckoutController::activePaymentMethods() — any number of manual
        // (send-money/bank-transfer) channels, plus at most one cod row and
        // one online_gateway row.
        $hasPaymentPath = $page->hasPaymentPath;
        $manualPaymentMethods = $page->manualPaymentMethods;
        $onlineGatewayMethod = $page->onlineGatewayMethod;
        $defaultPaymentMethod = $page->defaultPaymentMethod;
        $checkoutBlocked = $page->blocked;
        $checkoutBlockDescription = $page->blockDescription;
        $actualWeight = $page->actualWeight;
        $billedWeight = $page->billedWeight;
        $loggedInCustomer = $page->customer;
    @endphp

    <section class="border-b border-gray-200 dark:border-white/10">
        <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-5 lg:px-6">
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Delivery details') }}</h1>
            <p class="storefront-page-copy mt-2">
                {{ __('Submit your storefront order. The ERP team will review and confirm it before stock is deducted.') }}
            </p>
        </div>
    </section>

    <section
        class="mx-auto grid w-full max-w-7xl gap-5 px-4 py-6 sm:px-5 lg:grid-cols-[minmax(0,1fr)_340px] lg:px-6"
        x-data="@include('storefront.checkout.partials.checkout-alpine-state')"
    >
        <form id="checkout-form" class="rounded-lg border border-gray-200 bg-white p-4 sm:p-5 dark:border-gray-800 dark:bg-gray-900" method="POST" action="{{ \App\Support\StorefrontUrl::route('checkout.store', [], $previewSlug ?? null) }}" @if ($errors->any()) aria-describedby="checkout-errors" @endif>
            @csrf

            @if ($errors->any())
                <div id="checkout-errors" class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200" role="alert" tabindex="-1" data-checkout-errors>
                    <p class="font-semibold">{{ __('Please review the highlighted checkout details.') }}</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Full name') }}</span>
                    <input id="checkout-name" class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-950 outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" name="name" autocomplete="name" value="{{ old('name', $loggedInCustomer->name ?? '') }}" required @error('name') aria-invalid="true" aria-describedby="checkout-name-error" @enderror>
                    @error('name') <span id="checkout-name-error" class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Phone number') }}</span>
                    <input id="checkout-phone" class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-950 outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" type="tel" inputmode="tel" name="phone" autocomplete="tel" value="{{ old('phone', $loggedInCustomer->phone ?? '') }}" required @error('phone') aria-invalid="true" aria-describedby="checkout-phone-error" @enderror>
                    @error('phone') <span id="checkout-phone-error" class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Email address') }} <span class="text-gray-400">{{ __('(optional)') }}</span></span>
                    <input id="checkout-email" class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-950 outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" type="email" name="email" autocomplete="email" spellcheck="false" value="{{ old('email', $loggedInCustomer->email ?? '') }}" @error('email') aria-invalid="true" aria-describedby="checkout-email-error" @enderror>
                    @error('email') <span id="checkout-email-error" class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Delivery address') }}</span>
                    <textarea id="checkout-address" class="mt-2 min-h-28 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-950 outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" name="address" autocomplete="street-address" required x-model.debounce.300ms="address" @error('address') aria-invalid="true" aria-describedby="checkout-address-error" @enderror>{{ old('address', $loggedInCustomer->address ?? '') }}</textarea>
                    @error('address') <span id="checkout-address-error" class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                    <span class="mt-2 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400" aria-live="polite">
                        <svg class="h-4 w-4 shrink-0 text-[var(--storefront-brand)]" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s6-4.35 6-11a6 6 0 1 0-12 0c0 6.65 6 11 6 11Z"/><circle cx="12" cy="10" r="2"/></svg>
                        <span x-show="area === null">{{ __('Enter the full address to calculate delivery automatically.') }}</span>
                        <span x-show="area !== null" x-cloak>{{ __('Detected delivery area:') }} <strong x-text="area === 'inside' ? 'Inside Dhaka' : 'Outside Dhaka'"></strong></span>
                    </span>
                </label>

                @if ($setting->new_customer_delivery_advance_enabled ?? true)
                    <div class="rounded-xl border border-[var(--storefront-brand)]/30 bg-[var(--storefront-brand)]/5 p-4 sm:col-span-2" role="note">
                        <div class="flex gap-3">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-[var(--storefront-brand)]" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                            <div>
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('New customer delivery advance') }}</h2>
                                <p class="mt-1 whitespace-pre-line text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $setting->customerAdvanceMessage() }}</p>
                                @if ($onlinePaymentAvailable ?? false)
                                    <p class="mt-2 text-xs font-medium text-[var(--storefront-brand)]">{{ __('If this phone number is not in our customer list, the secure payment page will collect only the calculated delivery charge before the order is created.') }}</p>
                                @else
                                    <p class="mt-2 text-xs font-medium text-red-600 dark:text-red-400">{{ __('Online delivery-charge payment is temporarily unavailable. Existing customers can still use the payment methods below.') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                @if ($setting->risk_payment_enabled ?? false)
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 sm:col-span-2 dark:border-white/10 dark:bg-white/5" role="note">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Verified advance eligibility') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $setting->riskPaymentCustomerMessage() }}</p>
                    </div>
                @endif

                <fieldset
                    id="checkout-payment-method"
                    class="block sm:col-span-2"
                    @if ($errors->has('payment_method') || $errors->has('payment'))
                        aria-invalid="true"
                        aria-describedby="checkout-payment-errors"
                    @endif
                >
                    <legend class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Payment method') }}</legend>
                    <div class="mt-2 space-y-3">
                        @foreach ($paymentMethods as $paymentMethod)
                            @php $paymentMethodValue = $paymentMethod->paymentValue(); @endphp
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 text-sm transition" :class="method === {{ Illuminate\Support\Js::from($paymentMethodValue) }} ? 'border-[var(--storefront-brand)] ring-1 ring-[var(--storefront-brand)]' : 'border-gray-300 dark:border-white/15'">
                                <input id="payment-method-{{ $paymentMethod->getKey() }}" type="radio" name="payment_method" value="{{ $paymentMethodValue }}" x-model="method" class="h-4 w-4" required @checked($defaultPaymentMethod === $paymentMethodValue)>
                                <span class="font-medium text-gray-900 dark:text-white">{{ $paymentMethod->name }}</span>
                            </label>
                        @endforeach

                        @if ($manualPaymentMethods->isNotEmpty())
                            <div x-show="method.startsWith('manual:')" x-cloak class="ml-1 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
                                @foreach ($manualPaymentMethods as $manualMethod)
                                    <div id="manual-payment-instructions-{{ $manualMethod->getKey() }}" x-show="method === {{ Illuminate\Support\Js::from($manualMethod->paymentValue()) }}">
                                        @if ($manualMethod->account_number)
                                            <p class="text-gray-700 dark:text-gray-200">{{ __('Send Money to') }} <span class="font-semibold">{{ $manualMethod->account_number }}</span></p>
                                        @endif
                                        @if ($manualMethod->instructions)
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $manualMethod->instructions }}</p>
                                        @endif
                                    </div>
                                @endforeach
                                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Your sender number') }}</span>
                                        <input id="checkout-sender-number" class="mt-1 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" type="tel" inputmode="tel" name="sender_number" autocomplete="tel" value="{{ old('sender_number') }}" x-bind:required="method.startsWith('manual:')" @error('sender_number') aria-invalid="true" aria-describedby="checkout-sender-number-error" @enderror>
                                        @error('sender_number') <span id="checkout-sender-number-error" class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Transaction ID') }}</span>
                                        <input id="checkout-transaction-id" class="mt-1 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" name="trx_id" autocomplete="off" spellcheck="false" value="{{ old('trx_id') }}" x-bind:required="method.startsWith('manual:')" @error('trx_id') aria-invalid="true" aria-describedby="checkout-transaction-id-error" @enderror>
                                        @error('trx_id') <span id="checkout-transaction-id-error" class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                </div>
                            </div>
                        @endif

                        @if ($onlineGatewayMethod)
                            <div id="online-gateway-payment-instructions" x-show="method === {{ Illuminate\Support\Js::from($onlineGatewayMethod->paymentValue()) }}" x-cloak class="ml-1 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
                                <p class="text-gray-700 dark:text-gray-200">{{ __("You'll be redirected to a secure payment page to pay the full order amount online.") }}</p>
                                @if ($onlineGatewayMethod->instructions)
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $onlineGatewayMethod->instructions }}</p>
                                @endif
                            </div>
                        @endif
                    </div>
                    @unless ($hasPaymentPath)
                        <p id="checkout-payment-unavailable" class="mt-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" role="alert">
                            {{ __('No payment method is available right now. Please contact the store before placing this order.') }}
                        </p>
                    @endunless
                    @if ($errors->has('payment_method') || $errors->has('payment'))
                        <div id="checkout-payment-errors" class="mt-2 space-y-1 text-sm text-red-600 dark:text-red-400" role="alert">
                            @error('payment_method') <p>{{ $message }}</p> @enderror
                            @error('payment') <p>{{ $message }}</p> @enderror
                        </div>
                    @endif
                </fieldset>

                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Order note') }} <span class="text-gray-400">{{ __('(optional)') }}</span></span>
                    <textarea id="checkout-note" class="mt-2 min-h-24 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-950 outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" name="note" autocomplete="off" @error('note') aria-invalid="true" aria-describedby="checkout-note-error" @enderror>{{ old('note') }}</textarea>
                    @error('note') <span id="checkout-note-error" class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>
        </form>

        <aside class="h-fit rounded-lg border border-gray-200 bg-white p-5 lg:sticky lg:top-28 dark:border-gray-800 dark:bg-gray-900">
            <h2 class="text-lg font-semibold">{{ __('Order summary') }}</h2>
            <div class="mt-5 space-y-3">
                @foreach ($items as $item)
                    <div class="flex justify-between gap-4 text-sm">
                        <span class="min-w-0 flex-1 truncate text-gray-600 dark:text-gray-300">{{ $item['product']->name }}{{ ($item['variant'] ?? null) ? ' ('.$item['variant']->label().')' : '' }} &times; {{ $item['quantity'] }}</span>
                        <span class="shrink-0 font-semibold">BDT {{ \App\Support\MoneyFormatter::number($item['subtotal']) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="mt-5 space-y-2 border-t border-gray-200 pt-5 text-sm dark:border-white/10">
                <div class="flex justify-between text-gray-600 dark:text-gray-300">
                    <span>{{ __('Subtotal') }}</span>
                    <span>BDT {{ \App\Support\MoneyFormatter::number($subtotal) }}</span>
                </div>
                @if (($vatAmount ?? 0) > 0)
                    <div class="flex justify-between text-gray-600 dark:text-gray-300" data-checkout-vat>
                        <span>{{ $setting->vatDisplayLabel() }}</span>
                        <span>BDT {{ \App\Support\MoneyFormatter::number($vatAmount) }}</span>
                    </div>
                @elseif (($vatIncluded ?? 0) > 0)
                    <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400" data-checkout-vat-included>
                        <span>{{ __('Includes :vat', ['vat' => $setting->vatDisplayLabel()]) }}</span>
                        <span>BDT {{ \App\Support\MoneyFormatter::number($vatIncluded) }}</span>
                    </div>
                @endif
                <div class="flex justify-between text-gray-600 dark:text-gray-300">
                    <span>{{ __('Parcel weight') }}</span>
                    <span>{{ __(':actualweight kg (charged as :billedweight kg)', ['actualweight' => number_format($actualWeight, 3), 'billedweight' => $billedWeight]) }}</span>
                </div>
                <div class="flex justify-between text-gray-600 dark:text-gray-300">
                    <span>{{ __('Delivery charge') }}</span>
                    <span x-text="area === null ? 'Enter address' : 'BDT ' + deliveryCharge.toFixed(2)" aria-live="polite"></span>
                </div>
                <div class="flex justify-between text-gray-600 dark:text-gray-300" x-show="deliveryTime" x-cloak data-checkout-delivery-time>
                    <span>{{ __('Delivery time') }}</span>
                    <span x-text="deliveryTime" aria-live="polite"></span>
                </div>
                <div class="flex justify-between border-t border-gray-200 pt-2 text-lg font-semibold text-gray-950 dark:border-white/10 dark:text-white">
                    <span>{{ __('Total') }}</span>
                    <span x-text="'BDT ' + total.toFixed(2)" aria-live="polite"></span>
                </div>
            </div>
            @if (($advanceDue ?? 0) > 0)
                <div class="mt-4 rounded-lg border border-[var(--storefront-brand)]/30 bg-[var(--storefront-brand)]/5 px-4 py-3 text-sm leading-6 text-gray-700 dark:text-gray-200">
                    <div class="flex justify-between font-semibold">
                        <span>{{ __('Advance payable online now') }}</span>
                        <span>BDT {{ \App\Support\MoneyFormatter::number($advanceDue) }}</span>
                    </div>
                    @if ($onlinePaymentAvailable ?? false)
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Your cart includes pre-order items. The order will be created only after the secure payment verifies this advance.') }}
                        </p>
                    @else
                        <p id="checkout-preorder-payment-unavailable" class="mt-2 text-xs font-medium text-red-600 dark:text-red-400" role="alert">
                            {{ __('Online payment is currently unavailable. Please contact the store to complete this pre-order.') }}
                        </p>
                    @endif
                </div>
            @endif

            @if ($setting->new_customer_delivery_advance_enabled ?? true)
                <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs leading-5 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                    <span class="font-semibold text-gray-900 dark:text-white">{{ __('Weight-based rate:') }}</span>
                    {{ __('first 1 kg BDT :delivery_first_kg_inside inside Dhaka / BDT :delivery_first_kg_outside outside Dhaka, then BDT :delivery_additional_per_kg per additional started kg.', ['delivery_first_kg_inside' => \App\Support\MoneyFormatter::number($setting->delivery_first_kg_inside), 'delivery_first_kg_outside' => \App\Support\MoneyFormatter::number($setting->delivery_first_kg_outside), 'delivery_additional_per_kg' => \App\Support\MoneyFormatter::number($setting->delivery_additional_per_kg)]) }}
                </div>
            @endif

            <button
                class="mt-6 w-full rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                type="submit"
                form="checkout-form"
                data-checkout-submit
                @disabled($checkoutBlocked)
                @if ($checkoutBlocked) aria-disabled="true" aria-describedby="{{ $checkoutBlockDescription }}" @endif
            >
                {{ __('Place order / continue to payment') }}
            </button>
        </aside>
    </section>

    @include('storefront.checkout.partials.checkout-script')
@endsection
