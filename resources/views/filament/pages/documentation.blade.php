<x-filament-panels::page>
    @php
        $categories = $this->categories();
        $article = $this->activeArticle();
    @endphp

    <div class="grid gap-4 lg:grid-cols-[18rem_1fr] lg:items-start">
        <x-filament::section>
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" class="mb-3">
                <x-filament::input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    :placeholder="__('Search guides...')"
                />
            </x-filament::input.wrapper>

            @if ($categories->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('No guides match your search yet.') }}
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
                                        <x-filament::button
                                            tag="button"
                                            type="button"
                                            wire:click="selectArticle({{ $categoryArticle->id }})"
                                            :color="$article?->id === $categoryArticle->id ? 'primary' : 'gray'"
                                            :outlined="$article?->id !== $categoryArticle->id"
                                            size="sm"
                                            class="!w-full !justify-start !text-left"
                                        >
                                            {{ $categoryArticle->title }}
                                        </x-filament::button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </nav>
            @endif
        </x-filament::section>

        <x-filament::section>
            @if ($article)
                <h1 class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $article->title }}</h1>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    {{ __('Last updated :date', ['date' => $article->updated_at->format('d M, Y')]) }}
                </p>

                <div class="storefront-richtext mt-5 text-[15px] leading-7 text-gray-700 dark:text-gray-200">
                    {{ \Filament\Forms\Components\RichEditor\RichContentRenderer::make($article->content) }}
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('No guide selected yet. Ask an administrator to add step-by-step guides in Settings → Documentation.') }}
                </p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
