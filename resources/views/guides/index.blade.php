<x-layouts.guides title="Guides">
    <aside class="sidebar">
        @foreach ($categories as $category)
            <h2>{{ $category->name }}</h2>
            <ul>
                @foreach ($category->articles as $article)
                    <li><a href="{{ route('guides.show', [$category->slug, $article->slug]) }}">{{ $article->title }}</a></li>
                @endforeach
            </ul>
        @endforeach
    </aside>

    <main>
        @if ($categories->isEmpty())
            <div class="empty">No guides are published yet.</div>
        @else
            <h1>Guides</h1>
            <p class="meta">Pick a guide from the list on the left.</p>
        @endif
    </main>
</x-layouts.guides>
