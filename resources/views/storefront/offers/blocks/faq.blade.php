@if (! empty($data['questions']))
    <section class="border-b border-gray-200 py-10 dark:border-white/10">
        <div class="mx-auto w-full max-w-3xl px-4 sm:px-5 lg:px-6">
            @if (! empty($data['heading']))
                <h2 class="text-center text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $data['heading'] }}</h2>
            @else
                <h2 class="text-center text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ __('Frequently asked questions') }}</h2>
            @endif
            <div class="mt-6 space-y-3">
                @foreach ($data['questions'] as $qa)
                    @continue(empty($qa['question']))
                    <details class="group rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                        <summary class="cursor-pointer text-sm font-semibold text-gray-900 marker:content-none dark:text-white">
                            {{ $qa['question'] }}
                        </summary>
                        @if (! empty($qa['answer']))
                            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $qa['answer'] }}</p>
                        @endif
                    </details>
                @endforeach
            </div>
        </div>
    </section>
@endif
