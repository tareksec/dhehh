<x-filament-panels::page>
    @php
        $categories = $this->categories();
        $article = $this->activeArticle();
    @endphp

    <div class="grid gap-4 lg:grid-cols-[18rem_1fr] lg:items-start">
        <div class="fi-section rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <input
                type="text"
                wire:model.live.debounce.400ms="search"
                placeholder="Search guides..."
                class="fi-input mb-3 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
            />

            @if ($categories->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No guides match your search yet.
                </p>
            @else
                <nav class="space-y-4">
                    @foreach ($categories as $category)
                        <div>
                            <div class="flex items-center gap-2 px-1 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                @if ($category->icon)
                                    <x-filament::icon :icon="$category->icon" class="h-4 w-4" />
                                @endif
                                {{ $category->name }}
                            </div>

                            <ul class="mt-1 space-y-0.5">
                                @foreach ($category->articles as $categoryArticle)
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="selectArticle({{ $categoryArticle->id }})"
                                            @class([
                                                'block w-full rounded-lg px-3 py-1.5 text-left text-sm transition',
                                                'bg-primary-50 font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' => $article?->id === $categoryArticle->id,
                                                'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $article?->id !== $categoryArticle->id,
                                            ])
                                        >
                                            {{ $categoryArticle->title }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </nav>
            @endif
        </div>

        <div class="fi-section rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
            @if ($article)
                <h1 class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $article->title }}</h1>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Last updated {{ $article->updated_at->format('d M, Y') }}</p>

                <div class="storefront-richtext mt-5 text-[15px] leading-7 text-gray-700 dark:text-gray-200">
                    {{ \Filament\Forms\Components\RichEditor\RichContentRenderer::make($article->content) }}
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No guide selected yet. Ask an administrator to add step-by-step guides in Settings → Documentation.
                </p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
