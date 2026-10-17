@extends('storefront.account.layout')

@php
    $accountRoute = static fn (string $name) => isset($previewSlug)
        ? route('storefront.preview.account.'.$name, $previewSlug)
        : route('storefront.account.'.$name);
    $currency = $company->currency ?: 'BDT';
    $money = static fn ($amount) => $currency.' '.\App\Support\MoneyFormatter::number((float) $amount);
    $inputClass = 'min-h-11 w-full rounded-xl border border-gray-300 bg-white px-4 text-sm outline-none transition focus:border-[var(--storefront-brand)] focus:ring-2 focus:ring-[color-mix(in_srgb,var(--storefront-brand)_20%,transparent)] dark:border-gray-700 dark:bg-gray-950';
    $commissionStatusLabels = [
        'pending' => __('Pending delivery'),
        'payable' => __('Payable'),
        'cancelled' => __('Cancelled'),
    ];
    $payoutStatusLabels = [
        'requested' => __('Requested'),
        'approved' => __('Approved'),
        'paid' => __('Paid'),
        'rejected' => __('Rejected'),
    ];
@endphp

@section('account-content')
    <div>
        <p class="text-sm font-medium text-[var(--storefront-brand)]">{{ __('Your Store') }}</p>
        <h2 class="mt-1 text-2xl font-semibold tracking-tight">{{ __('Manage your reseller store') }}</h2>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Pick which products show on your own store page, set your prices, and track your earnings.') }}</p>
    </div>

    <section class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" aria-labelledby="earnings-heading">
        <h3 class="text-lg font-semibold tracking-tight" id="earnings-heading">{{ __('Earnings') }}</h3>
        <dl class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Available to withdraw') }}</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums" data-reseller-available>{{ $money($balances['available']) }}</dd>
            </div>
            <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Waiting for delivery') }}</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums">{{ $money($balances['pending']) }}</dd>
            </div>
            <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Withdrawal in process') }}</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums">{{ $money($balances['in_process']) }}</dd>
            </div>
            <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Total withdrawn') }}</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums">{{ $money($balances['withdrawn']) }}</dd>
            </div>
        </dl>

        <h4 class="mt-5 text-sm font-semibold">{{ __('Request a withdrawal') }}</h4>
        @if ($minWithdrawal > 0)
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Minimum withdrawal: :amount', ['amount' => $money($minWithdrawal)]) }}</p>
        @endif
        <form class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3" method="POST" action="{{ $accountRoute('reseller.payouts.store') }}">
            @csrf
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="payout_amount">{{ __('Amount') }}</label>
                <input class="{{ $inputClass }} mt-2" id="payout_amount" name="amount" type="number" inputmode="decimal" step="0.01" min="0" value="{{ old('amount') }}" required>
                @error('amount') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="payout_method">{{ __('Payment method') }}</label>
                <select class="{{ $inputClass }} mt-2" id="payout_method" name="method" required>
                    @foreach ($payoutMethods as $value => $label)
                        <option value="{{ $value }}" @selected(old('method') === $value)>{{ __($label) }}</option>
                    @endforeach
                </select>
                @error('method') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="payout_account">{{ __('Account number / details') }}</label>
                <input class="{{ $inputClass }} mt-2" id="payout_account" name="account_details" type="text" maxlength="500" autocomplete="off" value="{{ old('account_details') }}" required>
                @error('account_details') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-3">
                <button class="inline-flex min-h-11 items-center justify-center rounded-xl bg-[var(--storefront-brand)] px-6 text-sm font-semibold text-white transition hover:opacity-90" type="submit">{{ __('Request withdrawal') }}</button>
            </div>
        </form>

        @if ($payoutRequests->isNotEmpty())
            <h4 class="mt-5 text-sm font-semibold">{{ __('Withdrawal requests') }}</h4>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[480px] text-left text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-3 font-medium">{{ __('Date') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('Amount') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('Payment method') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('Status') }}</th>
                            <th class="py-2 font-medium">{{ __('Reference') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($payoutRequests as $payout)
                            <tr>
                                <td class="py-2 pr-3">{{ $payout->created_at?->format('d M Y') }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ $money($payout->amount) }}</td>
                                <td class="py-2 pr-3">{{ __($payoutMethods[$payout->method] ?? $payout->method) }}</td>
                                <td class="py-2 pr-3">{{ $payoutStatusLabels[$payout->status] ?? $payout->status }}</td>
                                <td class="py-2 break-all">{{ $payout->payment_reference ?: ($payout->status === 'rejected' ? $payout->admin_note : '') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($commissions->isNotEmpty())
            <h4 class="mt-5 text-sm font-semibold">{{ __('Recent profits') }}</h4>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[420px] text-left text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-3 font-medium">{{ __('Order') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('Sales') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('My profit') }}</th>
                            <th class="py-2 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($commissions as $commission)
                            <tr>
                                <td class="py-2 pr-3">{{ $commission->order?->order_number }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ $money($commission->sales_amount) }}</td>
                                <td class="py-2 pr-3 font-semibold tabular-nums">{{ $money($commission->amount) }}</td>
                                <td class="py-2">{{ $commissionStatusLabels[$commission->status] ?? $commission->status }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" aria-labelledby="store-url-heading">
        <h3 class="text-lg font-semibold tracking-tight" id="store-url-heading">{{ __('Store address') }}</h3>
        @if ($storeUrl)
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Your store is live at:') }}</p>
            <a class="mt-1 block break-all text-sm font-semibold text-[var(--storefront-brand)] hover:underline" href="{{ $storeUrl }}" target="_blank" rel="noopener">{{ $storeUrl }}</a>
        @else
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Set a store URL below to go live.') }}</p>
        @endif
        <form class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end" method="POST" action="{{ $accountRoute('reseller.slug') }}">
            @csrf
            @method('PATCH')
            <div class="flex-1">
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="reseller_slug">{{ __('Store URL slug') }}</label>
                <input class="{{ $inputClass }} mt-2" id="reseller_slug" name="reseller_slug" type="text" value="{{ old('reseller_slug', $customer->reseller_slug) }}" required>
                @error('reseller_slug') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <button class="inline-flex min-h-11 items-center justify-center rounded-xl bg-[var(--storefront-brand)] px-6 text-sm font-semibold text-white transition hover:opacity-90" type="submit">{{ __('Save') }}</button>
        </form>
    </section>

    <section class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" aria-labelledby="store-products-heading">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="text-lg font-semibold tracking-tight" id="store-products-heading">{{ __('Products') }}</h3>
            <form class="w-full sm:w-64" method="GET" action="{{ $accountRoute('reseller') }}">
                <input class="{{ $inputClass }} min-h-10" type="search" name="search" value="{{ $search }}" placeholder="{{ __('Search products…') }}" aria-label="{{ __('Search products…') }}">
            </form>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Your cost is the wholesale rate plus the store cost. Set any price at or above your cost; the difference is your profit, paid after the order is delivered.') }}</p>

        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($products as $product)
                @php
                    $isPicked = in_array($product->id, $pickedProductIds, true);
                    $basePrice = \App\Services\ResellerPricing::basePrice($product, $costPerUnit);
                    $suggestedPrice = $product->baseSellingPrice();
                    $ownPrice = $pickedPrices[$product->id] ?? null;
                    $toggleUrl = \App\Support\StorefrontUrl::route('account.reseller.products.toggle', [$product->id], $previewSlug ?? null);
                    $priceUrl = \App\Support\StorefrontUrl::route('account.reseller.products.price', [$product->id], $previewSlug ?? null);
                @endphp
                <div class="flex flex-col rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $product->name }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $product->sku }}</p>
                    <dl class="mt-2 space-y-0.5 text-xs text-gray-600 dark:text-gray-300">
                        @if ($basePrice !== null)
                            <div class="flex justify-between gap-2"><dt>{{ __('Your cost') }}</dt><dd class="tabular-nums">{{ $money($basePrice) }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-2"><dt>{{ __('Suggested price') }}</dt><dd class="tabular-nums">{{ $money($suggestedPrice) }}</dd></div>
                    </dl>
                    @if ($basePrice === null)
                        <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">{{ __('No wholesale rate set: sells at the normal price, no profit.') }}</p>
                    @endif

                    @if ($isPicked && $basePrice !== null)
                        <form class="mt-3 flex items-end gap-2" method="POST" action="{{ $priceUrl }}">
                            @csrf
                            @method('PATCH')
                            <div class="flex-1">
                                <label class="text-xs font-medium text-gray-700 dark:text-gray-300" for="price-{{ $product->id }}">{{ __('Your price') }}</label>
                                <input class="{{ $inputClass }} mt-1 min-h-9" id="price-{{ $product->id }}" name="price" type="number" inputmode="decimal" step="0.01" min="{{ $basePrice }}" value="{{ $ownPrice !== null ? (float) $ownPrice : '' }}" placeholder="{{ \App\Support\MoneyFormatter::number(max($suggestedPrice, $basePrice)) }}">
                            </div>
                            <button class="inline-flex min-h-9 items-center justify-center rounded-lg border border-gray-300 px-3 text-xs font-semibold transition hover:border-[var(--storefront-brand)] dark:border-gray-700" type="submit">{{ __('Save') }}</button>
                        </form>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Profit per unit: :amount', ['amount' => $money(max(($ownPrice !== null ? (float) $ownPrice : $suggestedPrice), $basePrice) - $basePrice)]) }}</p>
                    @endif

                    <form class="mt-auto pt-3" method="POST" action="{{ $toggleUrl }}">
                        @csrf
                        <button
                            class="inline-flex min-h-9 w-full items-center justify-center rounded-lg px-4 text-xs font-semibold transition {{ $isPicked ? 'bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-950 dark:text-red-300' : 'bg-[var(--storefront-brand)] text-white hover:opacity-90' }}"
                            type="submit"
                        >
                            {{ $isPicked ? __('Remove from store') : __('Add to store') }}
                        </button>
                    </form>
                </div>
            @empty
                <p class="col-span-full text-sm text-gray-500 dark:text-gray-400">{{ __('No products found.') }}</p>
            @endforelse
        </div>

        <div class="mt-4">
            {{ $products->links() }}
        </div>
    </section>
@endsection
