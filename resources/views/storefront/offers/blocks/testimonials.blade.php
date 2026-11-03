@if (($reviews ?? collect())->isNotEmpty())
    <section class="border-b border-gray-200 bg-gray-50 py-10 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto w-full max-w-5xl px-4 sm:px-5 lg:px-6">
            <h2 class="text-center text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $data['heading'] ?? __('Customer reviews') }}</h2>
            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($reviews as $review)
                    <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-gray-900">
                        <div class="text-amber-500" aria-hidden="true">{{ str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating) }}</div>
                        @if ($review->comment)
                            <p class="mt-2 leading-6 text-gray-700 dark:text-gray-200">{{ $review->comment }}</p>
                        @endif
                        <p class="mt-3 text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $review->customer_name }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif
