<x-layouts.guides :title="$article->title.' - Guides'">
    <aside class="sidebar">
        @foreach ($categories as $category)
            <h2>{{ $category->name }}</h2>
            <ul>
                @foreach ($category->articles as $categoryArticle)
                    <li>
                        <a
                            href="{{ route('guides.show', [$category->slug, $categoryArticle->slug]) }}"
                            @class(['active' => $categoryArticle->is($article)])
                        >{{ $categoryArticle->title }}</a>
                    </li>
                @endforeach
            </ul>
        @endforeach
    </aside>

    <main>
        <h1>{{ $article->title }}</h1>
        <p class="meta">{{ $article->category->name }} · Last updated {{ $article->updated_at->format('d M, Y') }}</p>

        <div class="content">
            {{ \Filament\Forms\Components\RichEditor\RichContentRenderer::make($article->content) }}
        </div>
    </main>
</x-layouts.guides>
