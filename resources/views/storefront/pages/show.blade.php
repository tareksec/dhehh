@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@php
    use Filament\Forms\Components\RichEditor\RichContentRenderer;

    $homeUrl = \App\Support\StorefrontUrl::currentResellerSlug()
        ? \App\Support\StorefrontUrl::route('show', [], $previewSlug ?? null)
        : (isset($previewSlug) ? route('storefront.preview.show', $previewSlug) : route('marketing.home'));
    $contactUrl = \App\Support\StorefrontUrl::route('contact', [], $previewSlug ?? null);
    $coverImageUrl = \App\Support\CompanyMedia::publicUrl($page->cover_image, $company);
    $isHtmlContent = str_contains((string) $page->content, '<');
@endphp

@section('content')
    <nav class="mx-auto w-full max-w-4xl px-4 pt-4 text-xs text-gray-500 sm:px-5 lg:px-6 dark:text-gray-400" aria-label="Breadcrumb">
        <a class="hover:text-gray-900 dark:hover:text-white" href="{{ $homeUrl }}">Home</a>
        <span class="mx-2">/</span>
        <span class="text-gray-900 dark:text-white">{{ $page->title }}</span>
    </nav>

    <article class="mx-auto w-full max-w-4xl px-4 py-6 sm:px-5 lg:px-6">
        @if ($coverImageUrl)
            <img class="mb-6 aspect-[21/9] w-full rounded-lg object-cover" src="{{ $coverImageUrl }}" alt="{{ $page->title }}" width="1680" height="720" fetchpriority="high">
        @endif

        <p class="text-xs font-semibold uppercase tracking-wider text-[var(--storefront-brand)]">{{ $company->name }}</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $page->title }}</h1>
        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Last updated {{ $page->updated_at->format('d M, Y') }}</p>

        @if ($page->excerpt)
            <p class="storefront-page-copy mt-4">{{ $page->excerpt }}</p>
        @endif

        <div class="storefront-richtext mt-6 text-[15px] leading-7 text-gray-700 dark:text-gray-200">
            @if ($isHtmlContent)
                {{ RichContentRenderer::make($page->content) }}
            @else
                @foreach (preg_split("/\r\n|\n|\r/", trim($page->content)) as $paragraph)
                    @if (trim($paragraph) !== '')
                        <p>{{ $paragraph }}</p>
                    @endif
                @endforeach
            @endif
        </div>

        <div class="mt-8 flex flex-col items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-5 py-6 text-center dark:border-white/10 dark:bg-gray-900">
            <div class="text-base font-semibold text-gray-900 dark:text-white">Still have questions?</div>
            <p class="text-sm text-gray-500 dark:text-gray-400">Can&rsquo;t find what you&rsquo;re looking for? Our team is happy to help.</p>
            <a class="inline-flex items-center rounded-lg bg-[var(--storefront-brand)] px-6 py-2.5 text-sm font-medium text-white transition hover:opacity-90" href="{{ $contactUrl }}">
                Contact us
            </a>
        </div>
    </article>
@endsection
