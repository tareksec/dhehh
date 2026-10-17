@extends('storefront.account.layout')

@php
    $storeUrl = \App\Support\StorefrontUrl::route('account.reviews.store', [$order->order_number], $previewSlug ?? null);
    $ordersUrl = \App\Support\StorefrontUrl::route('account.orders', [], $previewSlug ?? null);
@endphp

@section('account-content')
    <div class="mb-4">
        <a href="{{ $ordersUrl }}" class="text-sm font-medium text-[var(--storefront-brand)]">&larr; Back to orders</a>
        <h2 class="mt-2 text-2xl font-semibold tracking-tight">Review order {{ $order->order_number }}</h2>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Share your experience with the products from this order.</p>
    </div>

    @if (session('storefront_status'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-200">
            {{ session('storefront_status') }}
        </div>
    @endif

    <div class="space-y-4">
        @foreach ($order->items as $item)
            @continue(! $item->product)
            @php $alreadyReviewed = $reviewedProductIds->contains($item->product_id); @endphp
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $item->product->name }}</h3>
                    @if ($alreadyReviewed)
                        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">Reviewed</span>
                    @endif
                </div>

                @unless ($alreadyReviewed)
                    <form class="mt-3 space-y-3" method="POST" action="{{ $storeUrl }}">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $item->product_id }}">

                        <fieldset>
                            <legend class="text-xs font-medium text-gray-500 dark:text-gray-400">Rating</legend>
                            <div class="mt-1 flex items-center gap-1" x-data="{ rating: 5 }">
                                @for ($star = 1; $star <= 5; $star++)
                                    <label class="cursor-pointer text-2xl" :class="rating >= {{ $star }} ? 'text-amber-500' : 'text-gray-300 dark:text-gray-600'">
                                        <input type="radio" name="rating" value="{{ $star }}" class="sr-only" x-model.number="rating" @checked($star === 5)>
                                        ★
                                    </label>
                                @endfor
                            </div>
                        </fieldset>

                        <label class="block">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Comment (optional)</span>
                            <textarea name="comment" rows="3" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10 dark:text-white"></textarea>
                        </label>

                        <button class="inline-flex min-h-10 items-center rounded-lg bg-[var(--storefront-brand)] px-5 text-sm font-medium text-white transition hover:opacity-90" type="submit">
                            Submit review
                        </button>
                    </form>
                @endunless
            </div>
        @endforeach
    </div>
@endsection
