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
        $customer = $page->customer;
        $money = fn (float $amount) => \App\Support\MoneyFormatter::currency($amount);
        $homeUrl = \App\Support\StorefrontUrl::home($previewSlug ?? null);
        $cartUrl = \App\Support\StorefrontUrl::route('cart.show', [], $previewSlug ?? null);
        $advanceDue = (float) ($advanceDue ?? 0);
        $onlinePaymentAvailable = (bool) ($onlinePaymentAvailable ?? false);
        $vatAmount = (float) ($vatAmount ?? 0);
        $vatIncluded = (float) ($vatIncluded ?? 0);
        $advanceEnabled = (bool) ($setting->new_customer_delivery_advance_enabled ?? true);
        $submitLabel = __('Place order / continue to payment');
    @endphp

    <div class="mp-shell py-4 sm:py-6" x-data="@include('storefront.checkout.partials.checkout-alpine-state')">
        <nav class="mp-breadcrumb" aria-label="{{ __('Breadcrumb') }}">
            <ol>
                <li><a href="{{ $homeUrl }}">{{ __('Home') }}</a></li>
                <li><a href="{{ $cartUrl }}">{{ __('Cart') }}</a></li>
                <li aria-current="page">{{ __('Checkout') }}</li>
            </ol>
        </nav>

        <h1 class="mp-page-title mt-3">{{ __('Checkout') }}</h1>
        <p class="mt-1 text-sm mp-muted">{{ __('Your order is sent to :store for review and confirmation.', ['store' => $company->name]) }}</p>

        <div class="mt-5 lg:grid lg:grid-cols-[minmax(0,1fr)_24rem] lg:items-start lg:gap-6">
            <form id="checkout-form" class="grid gap-4" method="POST" action="{{ \App\Support\StorefrontUrl::route('checkout.store', [], $previewSlug ?? null) }}" @if ($errors->any()) aria-describedby="checkout-errors" @endif>
                @csrf

                @if ($errors->any())
                    <div id="checkout-errors" class="mp-note is-danger" role="alert" tabindex="-1" data-checkout-errors>
                        <p class="font-bold">{{ __('Please review the highlighted checkout details.') }}</p>
                        <ul class="mt-2 list-disc space-y-1 ps-5">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <section class="mp-panel" aria-labelledby="mp-checkout-contact">
                    <h2 id="mp-checkout-contact" class="mp-step-title"><span class="mp-step-number" aria-hidden="true">1</span>{{ __('Contact details') }}</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <label class="mp-field">
                            <span class="mp-field-label">{{ __('Full name') }}</span>
                            <input id="checkout-name" class="mp-input" name="name" autocomplete="name" value="{{ old('name', $customer->name ?? '') }}" required @error('name') aria-invalid="true" aria-describedby="checkout-name-error" @enderror>
                            @error('name') <span id="checkout-name-error" class="mp-field-error">{{ $message }}</span> @enderror
                        </label>
                        <label class="mp-field">
                            <span class="mp-field-label">{{ __('Phone number') }}</span>
                            <input id="checkout-phone" class="mp-input" type="tel" inputmode="tel" name="phone" autocomplete="tel" value="{{ old('phone', $customer->phone ?? '') }}" required @error('phone') aria-invalid="true" aria-describedby="checkout-phone-error" @enderror>
                            @error('phone') <span id="checkout-phone-error" class="mp-field-error">{{ $message }}</span> @enderror
                        </label>
                        <label class="mp-field sm:col-span-2">
                            <span class="mp-field-label">{{ __('Email address') }} <span class="mp-muted font-normal">({{ __('optional') }})</span></span>
                            <input id="checkout-email" class="mp-input" type="email" name="email" autocomplete="email" spellcheck="false" value="{{ old('email', $customer->email ?? '') }}" @error('email') aria-invalid="true" aria-describedby="checkout-email-error" @enderror>
                            @error('email') <span id="checkout-email-error" class="mp-field-error">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </section>

                <section class="mp-panel" aria-labelledby="mp-checkout-address">
                    <h2 id="mp-checkout-address" class="mp-step-title"><span class="mp-step-number" aria-hidden="true">2</span>{{ __('Delivery address') }}</h2>
                    <label class="mp-field mt-4">
                        <span class="sr-only">{{ __('Delivery address') }}</span>
                        <textarea id="checkout-address" class="mp-input" rows="3" name="address" autocomplete="street-address" required x-model.debounce.300ms="address" @error('address') aria-invalid="true" aria-describedby="checkout-address-error" @enderror>{{ old('address', $customer->address ?? '') }}</textarea>
                        @error('address') <span id="checkout-address-error" class="mp-field-error">{{ $message }}</span> @enderror
                    </label>
                    <p class="mt-2 flex items-start gap-2 text-xs mp-muted" aria-live="polite">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-[var(--storefront-accent)]" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s6-4.35 6-11a6 6 0 1 0-12 0c0 6.65 6 11 6 11Z"/><circle cx="12" cy="10" r="2"/></svg>
                        <span x-show="area === null">{{ __('Enter the full address to calculate delivery automatically.') }}</span>
                        <span x-show="area !== null" x-cloak>{{ __('Detected delivery area:') }} <strong x-text="area === 'inside' ? {{ Illuminate\Support\Js::from(__('Inside Dhaka')) }} : {{ Illuminate\Support\Js::from(__('Outside Dhaka')) }}"></strong></span>
                    </p>

                    @if ($advanceEnabled)
                        <div class="mp-note mt-4" role="note">
                            <h3 class="font-bold">{{ __('New customer delivery advance') }}</h3>
                            <p class="mt-1 whitespace-pre-line">{{ $setting->customerAdvanceMessage() }}</p>
                            @if ($onlinePaymentAvailable)
                                <p class="mt-2 text-xs font-semibold">{{ __('If this phone number is not in our customer list, the secure payment page will collect only the calculated delivery charge before the order is created.') }}</p>
                            @else
                                <p class="mt-2 text-xs font-semibold text-[var(--mp-deal)]">{{ __('Online delivery-charge payment is temporarily unavailable. Existing customers can still use the payment methods below.') }}</p>
                            @endif
                        </div>
                    @endif

                    @if ($setting->risk_payment_enabled ?? false)
                        <div class="mp-note is-neutral mt-4" role="note">
                            <h3 class="font-bold">{{ __('Verified advance eligibility') }}</h3>
                            <p class="mt-1">{{ $setting->riskPaymentCustomerMessage() }}</p>
                        </div>
                    @endif
                </section>

                <fieldset
                    id="checkout-payment-method"
                    class="mp-panel"
                    @if ($errors->has('payment_method') || $errors->has('payment'))
                        aria-invalid="true"
                        aria-describedby="checkout-payment-errors"
                    @endif
                >
                    <legend class="sr-only">{{ __('Payment method') }}</legend>
                    <h2 class="mp-step-title" aria-hidden="true"><span class="mp-step-number">3</span>{{ __('Payment method') }}</h2>
                    <div class="mt-4 grid gap-2.5">
                        @foreach ($page->paymentMethods as $paymentMethod)
                            @php $paymentMethodValue = $paymentMethod->paymentValue(); @endphp
                            <label class="mp-pay-option" :class="{ 'is-selected': method === {{ Illuminate\Support\Js::from($paymentMethodValue) }} }">
                                <input id="payment-method-{{ $paymentMethod->getKey() }}" type="radio" name="payment_method" value="{{ $paymentMethodValue }}" x-model="method" required @checked($page->defaultPaymentMethod === $paymentMethodValue)>
                                <span class="font-semibold">{{ $paymentMethod->name }}</span>
                            </label>
                        @endforeach

                        @if ($page->manualPaymentMethods->isNotEmpty())
                            <div x-show="method.startsWith('manual:')" x-cloak class="mp-note is-neutral">
                                @foreach ($page->manualPaymentMethods as $manualMethod)
                                    <div id="manual-payment-instructions-{{ $manualMethod->getKey() }}" x-show="method === {{ Illuminate\Support\Js::from($manualMethod->paymentValue()) }}">
                                        @if ($manualMethod->account_number)
                                            <p>{{ __('Send Money to') }} <span class="font-bold">{{ $manualMethod->account_number }}</span></p>
                                        @endif
                                        @if ($manualMethod->instructions)
                                            <p class="mt-1 text-xs mp-muted">{{ $manualMethod->instructions }}</p>
                                        @endif
                                    </div>
                                @endforeach
                                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <label class="mp-field">
                                        <span class="mp-field-label">{{ __('Your sender number') }}</span>
                                        <input id="checkout-sender-number" class="mp-input" type="tel" inputmode="tel" name="sender_number" autocomplete="tel" value="{{ old('sender_number') }}" x-bind:required="method.startsWith('manual:')" @error('sender_number') aria-invalid="true" aria-describedby="checkout-sender-number-error" @enderror>
                                        @error('sender_number') <span id="checkout-sender-number-error" class="mp-field-error">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="mp-field">
                                        <span class="mp-field-label">{{ __('Transaction ID') }}</span>
                                        <input id="checkout-transaction-id" class="mp-input" name="trx_id" autocomplete="off" spellcheck="false" value="{{ old('trx_id') }}" x-bind:required="method.startsWith('manual:')" @error('trx_id') aria-invalid="true" aria-describedby="checkout-transaction-id-error" @enderror>
                                        @error('trx_id') <span id="checkout-transaction-id-error" class="mp-field-error">{{ $message }}</span> @enderror
                                    </label>
                                </div>
                            </div>
                        @endif

                        @if ($page->onlineGatewayMethod)
                            <div id="online-gateway-payment-instructions" x-show="method === {{ Illuminate\Support\Js::from($page->onlineGatewayMethod->paymentValue()) }}" x-cloak class="mp-note is-neutral">
                                <p>{{ __("You'll be redirected to a secure payment page to pay the full order amount online.") }}</p>
                                @if ($page->onlineGatewayMethod->instructions)
                                    <p class="mt-1 text-xs mp-muted">{{ $page->onlineGatewayMethod->instructions }}</p>
                                @endif
                            </div>
                        @endif
                    </div>

                    @unless ($page->hasPaymentPath)
                        <p id="checkout-payment-unavailable" class="mp-note is-danger mt-3 font-semibold" role="alert">
                            {{ __('No payment method is available right now. Please contact the store before placing this order.') }}
                        </p>
                    @endunless
                    @if ($errors->has('payment_method') || $errors->has('payment'))
                        <div id="checkout-payment-errors" class="mt-3 space-y-1 text-sm font-semibold text-[var(--mp-deal)]" role="alert">
                            @error('payment_method') <p>{{ $message }}</p> @enderror
                            @error('payment') <p>{{ $message }}</p> @enderror
                        </div>
                    @endif
                </fieldset>

                <section class="mp-panel" aria-labelledby="mp-checkout-note">
                    <h2 id="mp-checkout-note" class="mp-step-title"><span class="mp-step-number" aria-hidden="true">4</span>{{ __('Order note') }} <span class="mp-muted text-sm font-normal">({{ __('optional') }})</span></h2>
                    <label class="mp-field mt-4">
                        <span class="sr-only">{{ __('Order note') }}</span>
                        <textarea id="checkout-note" class="mp-input" rows="2" name="note" autocomplete="off" @error('note') aria-invalid="true" aria-describedby="checkout-note-error" @enderror>{{ old('note') }}</textarea>
                        @error('note') <span id="checkout-note-error" class="mp-field-error">{{ $message }}</span> @enderror
                    </label>
                </section>
            </form>

            <aside class="mp-panel mp-summary mt-4 lg:mt-0" aria-labelledby="mp-checkout-summary">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 id="mp-checkout-summary" class="text-base font-bold">{{ __('Order summary') }}</h2>
                    <a class="text-xs font-semibold text-[var(--storefront-brand)] hover:underline" href="{{ $cartUrl }}">{{ __('Edit cart') }}</a>
                </div>

                <ul class="mp-summary-items">
                    @foreach ($items as $item)
                        @php $line = \App\Support\StorefrontCartLine::make($item, $company); @endphp
                        <li>
                            <span class="mp-summary-thumb" aria-hidden="true">
                                @if ($line->imageUrl)
                                    <img src="{{ $line->imageUrl }}" alt="" width="96" height="96" loading="lazy" decoding="async">
                                @else
                                    <span>{{ mb_substr($line->product->name, 0, 1) }}</span>
                                @endif
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold">{{ $line->product->name }}</span>
                                @if ($line->variant)
                                    <span class="block truncate text-xs mp-muted">{{ $line->variant->label() }}</span>
                                @endif
                                <span class="block text-xs mp-muted">{{ $money($line->unitPrice) }} &times; {{ $line->quantity }}</span>
                            </span>
                            <span class="shrink-0 text-sm font-bold">{{ $money($line->lineTotal) }}</span>
                        </li>
                    @endforeach
                </ul>

                <dl class="mp-summary-rows">
                    <div><dt>{{ __('Subtotal') }}</dt><dd>{{ $money((float) $subtotal) }}</dd></div>
                    @if ($vatAmount > 0)
                        <div data-checkout-vat><dt>{{ $setting->vatDisplayLabel() }}</dt><dd>{{ $money($vatAmount) }}</dd></div>
                    @elseif ($vatIncluded > 0)
                        <div class="text-xs mp-muted" data-checkout-vat-included><dt>{{ __('Includes :vat', ['vat' => $setting->vatDisplayLabel()]) }}</dt><dd>{{ $money($vatIncluded) }}</dd></div>
                    @endif
                    <div class="mp-muted"><dt>{{ __('Parcel weight') }}</dt><dd>{{ __(':actual kg (charged as :billed kg)', ['actual' => number_format($page->actualWeight, 3), 'billed' => $page->billedWeight]) }}</dd></div>
                    <div><dt>{{ __('Delivery charge') }}</dt><dd x-text="area === null ? {{ Illuminate\Support\Js::from(__('Enter address')) }} : mpMoney(deliveryCharge)" aria-live="polite">{{ __('Enter address') }}</dd></div>
                    <div class="mp-muted" x-show="deliveryTime" x-cloak data-checkout-delivery-time><dt>{{ __('Delivery time') }}</dt><dd x-text="deliveryTime" aria-live="polite"></dd></div>
                    <div class="is-total"><dt>{{ __('Total') }}</dt><dd x-text="mpMoney(total)" aria-live="polite">{{ $money((float) $subtotal + $vatAmount) }}</dd></div>
                </dl>

                @if ($advanceDue > 0)
                    <div class="mp-note mt-4">
                        <div class="flex justify-between gap-3 font-bold">
                            <span>{{ __('Advance payable online now') }}</span>
                            <span>{{ $money($advanceDue) }}</span>
                        </div>
                        @if ($onlinePaymentAvailable)
                            <p class="mt-1 text-xs mp-muted">{{ __('Your cart includes pre-order items. The order will be created only after the secure payment verifies this advance.') }}</p>
                        @else
                            <p id="checkout-preorder-payment-unavailable" class="mt-2 text-xs font-semibold text-[var(--mp-deal)]" role="alert">{{ __('Online payment is currently unavailable. Please contact the store to complete this pre-order.') }}</p>
                        @endif
                    </div>
                @endif

                @if ($advanceEnabled)
                    <p class="mp-note is-neutral mt-4 text-xs">
                        <span class="font-bold">{{ __('Weight-based rate:') }}</span>
                        {{ __('first 1 kg :inside inside Dhaka / :outside outside Dhaka, then :extra per additional started kg.', [
                            'inside' => $money((float) $setting->delivery_first_kg_inside),
                            'outside' => $money((float) $setting->delivery_first_kg_outside),
                            'extra' => $money((float) $setting->delivery_additional_per_kg),
                        ]) }}
                    </p>
                @endif

                <button
                    class="mp-button mp-button-accent mp-button-lg mp-summary-submit mt-4 w-full"
                    type="submit"
                    form="checkout-form"
                    data-checkout-submit
                    data-pending-label="{{ __('Processing…') }}"
                    @disabled($page->blocked)
                    @if ($page->blocked) aria-disabled="true" aria-describedby="{{ $page->blockDescription }}" @endif
                >{{ $submitLabel }}</button>
            </aside>
        </div>

        {{-- Phone: the live total and the order button stay above the bottom navigation. --}}
        <div class="mp-sticky-buy sm:hidden" data-mobile-checkout-bar>
            <div class="min-w-0">
                <span class="block text-[11px] mp-muted">{{ __('Total') }}</span>
                <span class="block truncate text-base font-extrabold" x-text="mpMoney(total)">{{ $money((float) $subtotal + $vatAmount) }}</span>
            </div>
            <button
                class="mp-button mp-button-accent flex-1"
                type="submit"
                form="checkout-form"
                data-checkout-submit
                data-pending-label="{{ __('Processing…') }}"
                @disabled($page->blocked)
                @if ($page->blocked) aria-disabled="true" aria-describedby="{{ $page->blockDescription }}" @endif
            >{{ __('Place order') }}</button>
        </div>
        <div class="sm:hidden" style="height: calc(4.75rem + env(safe-area-inset-bottom));" aria-hidden="true"></div>
    </div>

    <script>
        // Same figure as MoneyFormatter::currency(): symbol, grouped thousands, no needless decimals.
        window.mpMoney = function (amount) {
            return {{ Illuminate\Support\Js::from(\App\Support\MoneyFormatter::symbol('BDT')) }} + ' ' + Number(amount).toLocaleString('en-US', { maximumFractionDigits: 2 });
        };
    </script>
    @include('storefront.checkout.partials.checkout-script')
@endsection
