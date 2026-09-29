@extends('storefront.account.layout')

@php
    $accountRoute = static fn (string $name) => isset($previewSlug)
        ? route('storefront.preview.account.'.$name, $previewSlug)
        : route('storefront.account.'.$name);
    $productsUrl = \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null);
@endphp

@section('account-content')
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-medium text-[var(--storefront-brand)]">Overview</p>
            <h2 class="mt-1 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">Welcome back, {{ str($customer->name)->before(' ') }}.</h2>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Manage your account and follow every order from one place.</p>
        </div>
        <a class="mt-3 inline-flex min-h-11 items-center justify-center rounded-lg bg-[var(--storefront-brand)] px-5 text-sm font-semibold text-white transition hover:opacity-90 sm:mt-0" href="{{ $productsUrl }}">Continue shopping</a>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-3 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Total orders', 'value' => $orderCount],
            ['label' => 'Active orders', 'value' => $activeOrderCount],
            ['label' => 'Completed', 'value' => $completedOrderCount],
            ['label' => 'Total purchased', 'value' => ($company->currency ?: 'BDT').' '.\App\Support\MoneyFormatter::number((float) $totalSpent)],
        ] as $stat)
            <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900 sm:p-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</p>
                <p class="mt-1 text-xl font-semibold tracking-tight text-gray-950 dark:text-white sm:text-2xl">{{ $stat['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[1.15fr_0.85fr]">
        <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" aria-labelledby="recent-orders-heading">
            <div class="flex items-center justify-between gap-4">
                <h3 class="text-lg font-semibold tracking-tight" id="recent-orders-heading">Recent orders</h3>
                <a class="text-sm font-semibold text-[var(--storefront-brand)] hover:underline" href="{{ $accountRoute('orders') }}">View all</a>
            </div>
            @if ($recentOrders->isEmpty())
                <div class="py-8 text-center">
                    <p class="font-medium text-gray-900 dark:text-white">No orders yet</p>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Your storefront purchases will appear here.</p>
                </div>
            @else
                <div class="mt-4 divide-y divide-gray-200 dark:divide-gray-800">
                    @foreach ($recentOrders as $order)
                        <a class="flex min-h-16 items-center justify-between gap-4 py-3 transition hover:text-[var(--storefront-brand)]" href="{{ \App\Support\StorefrontUrl::route('track.show', [$order->order_number], $previewSlug ?? null) }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold">{{ $order->order_number }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ optional($order->order_date)->format('d M Y') }} &middot; {{ $order->items->sum('quantity') }} items</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold">{{ \App\Support\MoneyFormatter::currency((float) $order->total_amount, $company->currency ?: 'BDT') }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ App\Models\Order::STATUSES[$order->status] ?? str($order->status)->headline() }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" aria-labelledby="recent-activity-heading">
            <div class="flex items-center justify-between gap-4">
                <h3 class="text-lg font-semibold tracking-tight" id="recent-activity-heading">Recent activity</h3>
                <a class="text-sm font-semibold text-[var(--storefront-brand)] hover:underline" href="{{ $accountRoute('activity') }}">View all</a>
            </div>
            @if ($recentActivities->isEmpty())
                <div class="py-8 text-center">
                    <p class="font-medium text-gray-900 dark:text-white">No activity yet</p>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Account updates will be recorded here.</p>
                </div>
            @else
                <div class="mt-4 space-y-4">
                    @foreach ($recentActivities as $activity)
                        <div class="flex gap-3">
                            <div class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-[color-mix(in_srgb,var(--storefront-brand)_12%,transparent)] text-[var(--storefront-brand)]">
                                <svg class="h-4 w-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $activity->title }}</p>
                                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $activity->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection
