@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@section('content')
    @php
        $offersUrl = \App\Support\StorefrontUrl::route('offers.index', [], $previewSlug ?? null);
        $loginUrl = \App\Support\StorefrontUrl::route('account.login', [], $previewSlug ?? null);
    @endphp

    <section class="mx-auto w-full max-w-3xl px-4 py-8 text-center sm:px-5 lg:px-6">
        <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">
            <svg class="h-8 w-8" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
        </div>
        <p class="mt-6 text-xs font-semibold uppercase tracking-wider text-[var(--storefront-brand)]">Order submitted</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">Thank you, {{ $order->customer?->name }}.</h1>
        <p class="storefront-page-copy mx-auto mt-3">Order <span class="font-semibold text-gray-950 dark:text-white">{{ $order->order_number }}</span>{{ $offer ? ' for '.$offer->title : '' }} has been received. Our team will review and confirm it shortly.</p>

        @if ($generatedPassword)
            <div class="mt-6 rounded-xl border border-[var(--storefront-brand)]/30 bg-[var(--storefront-brand)]/5 p-5 text-left text-sm leading-6">
                <h2 class="font-semibold text-gray-950 dark:text-white">আপনার অ্যাকাউন্ট তৈরি হয়েছে</h2>
                <p class="mt-2 text-gray-700 dark:text-gray-200">
                    লগইন: <span class="font-semibold">{{ $order->customer?->phone }}</span><br>
                    পাসওয়ার্ড: <span class="font-semibold">{{ $generatedPassword }}</span>
                </p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">এই পাসওয়ার্ড শুধু একবারই দেখানো হচ্ছে — অনুগ্রহ করে সংরক্ষণ করুন। লগইন করে প্রোফাইল সেটিংস থেকে যেকোনো সময় পরিবর্তন করতে পারবেন।</p>
                <a href="{{ $loginUrl }}" class="mt-3 inline-flex items-center text-sm font-medium text-[var(--storefront-brand)]">এখনই লগইন করুন &rarr;</a>
            </div>
        @endif

        <div class="mt-8 rounded-xl border border-gray-200 bg-white p-6 text-left dark:border-white/10 dark:bg-white/5">
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

        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a class="inline-flex min-h-11 items-center rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90" href="{{ $offersUrl }}">Browse more offers</a>
            <a class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-6 py-3 text-sm font-medium text-gray-900 transition hover:border-[var(--storefront-brand)] dark:border-white/10 dark:text-white" href="{{ \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null) }}">Continue shopping</a>
        </div>
    </section>
@endsection
