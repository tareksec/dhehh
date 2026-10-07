@extends('storefront.account.layout')

@section('account-content')
    <div>
        <p class="text-sm font-medium text-[var(--storefront-brand)]">Activity</p>
        <h2 class="mt-1 text-2xl font-semibold tracking-tight">Account activity</h2>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">A private timeline of important account and order actions.</p>
    </div>

    <section class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" aria-label="Account activity timeline">
        @if ($activities->isEmpty())
            <div class="py-8 text-center">
                <div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-300">
                    <svg class="h-6 w-6" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12h3l2.25-6 4.5 12 2.25-6h6"/></svg>
                </div>
                <h3 class="mt-4 font-semibold">No activity recorded yet</h3>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Profile changes and new account actions will appear here.</p>
            </div>
        @else
            <ol class="divide-y divide-gray-200 dark:divide-gray-800">
                @foreach ($activities as $activity)
                    @php
                        $orderNumber = data_get($activity->metadata, 'order_number');
                    @endphp
                    <li class="flex gap-3 py-4 first:pt-0 last:pb-0">
                        <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[color-mix(in_srgb,var(--storefront-brand)_12%,transparent)] text-[var(--storefront-brand)]">
                            <svg class="h-5 w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $activity->title }}</h3>
                                <time class="shrink-0 text-xs text-gray-400" datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->format('d M Y, h:i A') }}</time>
                            </div>
                            @if ($activity->description)
                                <p class="mt-1 text-sm leading-6 text-gray-500 dark:text-gray-400">{{ $activity->description }}</p>
                            @endif
                            @if ($orderNumber)
                                <a class="mt-2 inline-flex min-h-11 items-center text-sm font-semibold text-[var(--storefront-brand)] hover:underline" href="{{ \App\Support\StorefrontUrl::route('track.show', [$orderNumber], $previewSlug ?? null) }}">View order</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>

            @if ($activities->hasPages())
                <div class="mt-6 border-t border-gray-200 pt-5 dark:border-gray-800">{{ $activities->links() }}</div>
            @endif
        @endif
    </section>
@endsection
