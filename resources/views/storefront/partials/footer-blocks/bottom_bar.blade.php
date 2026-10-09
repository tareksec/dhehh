@php
    $copyrightText = trim((string) ($data['copyright_text'] ?? '')) ?: '© {year} {company_name}. All rights reserved.';
    $copyrightText = strtr($copyrightText, ['{year}' => now()->year, '{company_name}' => $company->name]);
    $legalLinks = collect($data['legal_links'] ?? [])->filter(fn ($link) => filled($link['label'] ?? null) && filled($link['page_id'] ?? null));
    $legalPageIds = $legalLinks->pluck('page_id')->unique();
    $legalPageSlugs = $legalPageIds->isNotEmpty()
        ? \App\Models\StorefrontPage::withoutGlobalScopes()->where('company_id', $company->getKey())->forSite($setting ?? null)->whereIn('id', $legalPageIds)->where('is_published', true)->pluck('slug', 'id')
        : collect();
@endphp
<div class="border-t border-gray-200 dark:border-white/10">
    <div class="mx-auto flex w-full max-w-7xl flex-col-reverse items-center justify-between gap-3 px-4 py-4 text-center sm:flex-row sm:text-left sm:px-5 lg:px-6">
        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $copyrightText }}</div>
        @if (filled($data['center_text'] ?? null))
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $data['center_text'] }}</div>
        @endif
        @if (filled($data['right_text'] ?? null))
            <div class="text-sm text-gray-500 dark:text-gray-400">{!! str_replace('{heart}', '<span class="text-red-500" aria-hidden="true">&hearts;</span>', e($data['right_text'])) !!}</div>
        @endif
        @if ($legalLinks->isNotEmpty())
            <nav class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1" aria-label="Legal">
                @foreach ($legalLinks as $legalLink)
                    @continue(! $legalPageSlugs->has($legalLink['page_id']))
                    <a class="text-sm text-gray-500 transition hover:text-[var(--storefront-brand)] dark:text-gray-400" href="{{ $pageUrl($legalPageSlugs->get($legalLink['page_id'])) }}">
                        {{ $legalLink['label'] }}
                    </a>
                @endforeach
            </nav>
        @endif
    </div>
</div>
