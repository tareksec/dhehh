@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@section('content')
    @php
        $checkoutUrl = \App\Support\StorefrontUrl::route('offers.checkout', [$offer->slug], $previewSlug ?? null);
        $loggedInCustomer = auth('customer')->user();
        $codEnabled = ($setting->cod_enabled ?? true) && ! $offer->online_payment_required;
        $hasBkash = filled($setting->manual_bkash_number);
        $hasNagad = filled($setting->manual_nagad_number);
        $availablePaymentMethods = array_values(array_filter([
            $codEnabled ? 'cod' : null,
            $hasBkash ? 'manual_bkash' : null,
            $hasNagad ? 'manual_nagad' : null,
        ]));
        $oldPaymentMethod = old('payment_method');
        $defaultPaymentMethod = in_array($oldPaymentMethod, $availablePaymentMethods, true) ? $oldPaymentMethod : ($availablePaymentMethods[0] ?? '');
        $hasPaymentPath = $availablePaymentMethods !== [];
    @endphp

    @foreach ($offer->blocks ?? [] as $block)
        @includeIf('storefront.offers.blocks.'.$block['type'], ['data' => $block['data'] ?? [], 'blockId' => $block['id'] ?? null, 'reviews' => $reviews[$block['id'] ?? null] ?? collect()])
    @endforeach

    <section
        id="offer-checkout"
        class="mx-auto grid w-full max-w-7xl gap-5 border-t border-gray-200 px-4 py-10 sm:px-5 lg:grid-cols-[minmax(0,1fr)_340px] lg:px-6 dark:border-white/10"
        x-data="{ quantity: {{ (int) old('quantity', 1) }}, unitPrice: {{ (float) $finalPrice }}, method: {{ Illuminate\Support\Js::from($defaultPaymentMethod) }}, vatPerBundle: {{ (float) ($vatPerBundle ?? 0) }}, get vat() { return Math.round(this.vatPerBundle * Math.max(1, this.quantity) * 100) / 100 }, get total() { return this.unitPrice * Math.max(1, this.quantity) + this.vat } }"
    >
        <form id="offer-checkout-form" class="rounded-lg border border-gray-200 bg-white p-4 sm:p-5 dark:border-gray-800 dark:bg-gray-900" method="POST" action="{{ $checkoutUrl }}">
            @csrf

            <h2 class="text-xl font-semibold text-gray-950 dark:text-white">Order this offer</h2>

            @if ($errors->any())
                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200" role="alert">
                    <p class="font-semibold">Please review the highlighted details.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Full name</span>
                    <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-950 outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" name="name" autocomplete="name" value="{{ old('name', $loggedInCustomer->name ?? '') }}" required>
                    @error('name') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Phone number</span>
                    <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-950 outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" type="tel" inputmode="tel" name="phone" autocomplete="tel" value="{{ old('phone', $loggedInCustomer->phone ?? '') }}" required>
                    @error('phone') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Email address <span class="text-gray-400">(optional)</span></span>
                    <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-950 outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" type="email" name="email" autocomplete="email" value="{{ old('email', $loggedInCustomer->email ?? '') }}">
                    @error('email') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Delivery address</span>
                    <textarea class="mt-2 min-h-28 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-950 outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" name="address" autocomplete="street-address" required>{{ old('address', $loggedInCustomer->address ?? '') }}</textarea>
                    @error('address') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                    <span class="mt-2 block text-xs text-gray-500 dark:text-gray-400">Delivery charge is calculated from your address after you place the order.</span>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Quantity</span>
                    <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-950 outline-none transition focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" type="number" name="quantity" min="1" max="100" x-model.number="quantity" required>
                    @error('quantity') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <fieldset class="block sm:col-span-2">
                    <legend class="text-sm font-medium text-gray-700 dark:text-gray-200">Payment method</legend>
                    <div class="mt-2 space-y-3">
                        @if ($codEnabled)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 text-sm transition" :class="method === 'cod' ? 'border-[var(--storefront-brand)] ring-1 ring-[var(--storefront-brand)]' : 'border-gray-300 dark:border-white/15'">
                                <input type="radio" name="payment_method" value="cod" x-model="method" class="h-4 w-4" required @checked($defaultPaymentMethod === 'cod')>
                                <span class="font-medium text-gray-900 dark:text-white">Cash on Delivery</span>
                            </label>
                        @endif
                        @if ($hasBkash)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 text-sm transition" :class="method === 'manual_bkash' ? 'border-[var(--storefront-brand)] ring-1 ring-[var(--storefront-brand)]' : 'border-gray-300 dark:border-white/15'">
                                <input type="radio" name="payment_method" value="manual_bkash" x-model="method" class="h-4 w-4" required @checked($defaultPaymentMethod === 'manual_bkash')>
                                <span class="font-medium text-gray-900 dark:text-white">bKash (Send Money)</span>
                            </label>
                        @endif
                        @if ($hasNagad)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 text-sm transition" :class="method === 'manual_nagad' ? 'border-[var(--storefront-brand)] ring-1 ring-[var(--storefront-brand)]' : 'border-gray-300 dark:border-white/15'">
                                <input type="radio" name="payment_method" value="manual_nagad" x-model="method" class="h-4 w-4" required @checked($defaultPaymentMethod === 'manual_nagad')>
                                <span class="font-medium text-gray-900 dark:text-white">Nagad (Send Money)</span>
                            </label>
                        @endif
                        @if ($hasBkash || $hasNagad)
                            <div x-show="method === 'manual_bkash' || method === 'manual_nagad'" x-cloak class="ml-1 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
                                @if ($hasBkash)
                                    <div x-show="method === 'manual_bkash'">
                                        <p class="text-gray-700 dark:text-gray-200">Send Money to <span class="font-semibold">{{ $setting->manual_bkash_number }}</span></p>
                                    </div>
                                @endif
                                @if ($hasNagad)
                                    <div x-show="method === 'manual_nagad'">
                                        <p class="text-gray-700 dark:text-gray-200">Send Money to <span class="font-semibold">{{ $setting->manual_nagad_number }}</span></p>
                                    </div>
                                @endif
                                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Your sender number</span>
                                        <input class="mt-1 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" type="tel" inputmode="tel" name="sender_number" value="{{ old('sender_number') }}" x-bind:required="method === 'manual_bkash' || method === 'manual_nagad'">
                                        @error('sender_number') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Transaction ID</span>
                                        <input class="mt-1 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white" name="trx_id" value="{{ old('trx_id') }}" x-bind:required="method === 'manual_bkash' || method === 'manual_nagad'">
                                        @error('trx_id') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                </div>
                            </div>
                        @endif
                    </div>
                    @unless ($hasPaymentPath)
                        <p class="mt-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" role="alert">
                            No payment method is available right now. Please contact the store before placing this order.
                        </p>
                    @endunless
                </fieldset>
            </div>
        </form>

        <aside class="h-fit rounded-lg border border-gray-200 bg-white p-5 lg:sticky lg:top-28 dark:border-gray-800 dark:bg-gray-900">
            <h2 class="text-lg font-semibold">{{ $offer->title }}</h2>
            <div class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                @foreach ($offer->items as $item)
                    <div class="flex justify-between gap-4">
                        <span class="min-w-0 flex-1 truncate">{{ $item->product->name }}{{ $item->productVariant ? ' ('.$item->productVariant->label().')' : '' }} &times; {{ $item->quantity }}</span>
                    </div>
                @endforeach
            </div>
            <div class="mt-5 space-y-2 border-t border-gray-200 pt-5 text-sm dark:border-white/10">
                @if ($componentsSubtotal > $finalPrice)
                    <div class="flex justify-between text-gray-500 line-through dark:text-gray-500">
                        <span>Regular price</span>
                        <span>BDT {{ \App\Support\MoneyFormatter::number((float) $componentsSubtotal) }}</span>
                    </div>
                @endif
                <div class="flex justify-between font-medium text-gray-900 dark:text-white">
                    <span>Offer price</span>
                    <span>BDT {{ \App\Support\MoneyFormatter::number((float) $finalPrice) }}</span>
                </div>
                @if ($setting->vatActive())
                    <div class="flex justify-between text-gray-600 dark:text-gray-300" data-offer-vat>
                        @if ($setting->vatInclusive())
                            <span class="text-xs">{{ __('Price includes :vat', ['vat' => $setting->vatDisplayLabel()]) }}</span>
                        @else
                            <span>{{ $setting->vatDisplayLabel() }}</span>
                            <span x-text="'BDT ' + vat.toFixed(2)"></span>
                        @endif
                    </div>
                @endif
                <div class="flex justify-between border-t border-gray-200 pt-2 text-lg font-semibold text-gray-950 dark:border-white/10 dark:text-white">
                    <span>Total (&times; <span x-text="Math.max(1, quantity)"></span>)</span>
                    <span x-text="'BDT ' + total.toFixed(2)"></span>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Delivery charge is added after your address is submitted.</p>
            </div>

            <button class="mt-6 w-full rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50" type="submit" form="offer-checkout-form" @disabled(! $hasPaymentPath)>
                Order now
            </button>
        </aside>
    </section>
@endsection
