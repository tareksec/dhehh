{{--
    The website's footer on the reseller landing page: the Footer Builder of the company's Next.js
    site (StorefrontSiteConfigService::footer()), laid out like the website draws it. $footer is that
    data; every word comes from the admin, the page adds only the section labels left blank.
--}}
@php
    $dark = $footer['dark'];
    $tone = [
        'heading' => $dark ? 'text-white' : 'text-zinc-900',
        'body' => $dark ? 'text-zinc-400' : 'text-zinc-600',
        'muted' => 'text-zinc-500',
        'link' => $dark ? 'text-zinc-400 hover:text-white' : 'text-zinc-600 hover:text-[var(--brand)]',
        'divider' => $dark ? 'border-zinc-800' : 'border-zinc-200',
        'chip' => $dark ? 'border-zinc-700' : 'border-zinc-200 bg-white',
        'social' => $dark ? 'bg-zinc-800 text-zinc-300 hover:bg-[var(--brand)] hover:text-white' : 'bg-zinc-200/70 text-zinc-600 hover:bg-[var(--brand)] hover:text-white',
    ];
    $paymentTones = [
        'pink' => $dark ? 'text-pink-400' : 'text-pink-600',
        'orange' => $dark ? 'text-orange-400' : 'text-orange-600',
        'purple' => $dark ? 'text-purple-300' : 'text-purple-600',
        'blue' => $dark ? 'text-sky-400' : 'text-blue-600',
        'green' => $dark ? 'text-emerald-400' : 'text-emerald-600',
        'red' => $dark ? 'text-red-400' : 'text-red-600',
        'neutral' => $dark ? 'text-zinc-300' : 'text-zinc-700',
    ];
    $headingClass = 'mb-4 text-sm font-bold uppercase tracking-wide '.$tone['heading'];
    $bottom = $footer['bottom'] ?? [];
    $fill = fn (string $text): string => str_replace(['{year}', '{company_name}'], [(string) now()->year, $footer['name']], $text);
    $copyright = $fill(filled($bottom['copyright_text'] ?? null) ? $bottom['copyright_text'] : '© {year} {company_name}');
    $columns = collect($footer['columns'])->map(fn (array $column): array => collect($column)->filter(fn (array $block): bool => match ($block['type']) {
        'quick_links' => $footer['menu'] !== [],
        'custom_links' => ($block['data']['links'] ?? []) !== [],
        'social_links' => $footer['social_links'] !== [],
        'payment_methods' => ($block['data']['items'] ?? []) !== [],
        'custom_text' => filled($block['data']['heading'] ?? null) || filled($block['data']['text'] ?? null),
        'image' => filled($block['data']['image_url'] ?? null),
        'contact_info' => filled($block['data']['address'] ?? null) || filled($block['data']['email'] ?? null) || ($block['data']['phones'] ?? []) !== [] || filled($block['data']['hours'] ?? null) || filled($block['data']['trade_license'] ?? null),
        default => true,
    })->values()->all())->filter()->values();
@endphp
<footer data-reseller-footer style="background-color: {{ $footer['background'] }}" @class(['dark' => $dark])>
    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:[grid-template-columns:var(--footer-columns)]" style="--footer-columns: {{ $columns->count() > 1 ? $footer['template'] : '1fr' }}">
        @foreach ($columns as $column)
            <div class="min-w-0 space-y-5">
                @foreach ($column as $block)
                    @php($data = $block['data'])
                    @switch($block['type'])
                        @case('brand_about')
                            <div>
                                <span class="flex items-center gap-2.5">
                                    @if (($data['show_logo'] ?? false) && $footer['logo_url'])
                                        <img src="{{ $footer['logo_url'] }}" alt="{{ $footer['name'] }}" class="h-10 w-auto max-w-[180px] object-contain">
                                    @else
                                        <span class="leading-tight">
                                            <span class="block text-lg font-extrabold {{ $tone['heading'] }}">{{ $footer['name'] }}</span>
                                            @if (filled($data['tagline'] ?? null))
                                                <span class="block text-[11px] {{ $dark ? 'text-zinc-400' : 'text-zinc-500' }}">{{ $data['tagline'] }}</span>
                                            @endif
                                        </span>
                                    @endif
                                </span>
                                @if (filled($data['text'] ?? null))
                                    <p class="mt-4 text-sm leading-relaxed {{ $tone['body'] }}">{{ $data['text'] }}</p>
                                @endif
                            </div>
                            @break

                        @case('quick_links')
                            <nav aria-label="{{ $data['heading'] ?? __('Quick links') }}">
                                <h3 class="{{ $headingClass }}">{{ filled($data['heading'] ?? null) ? $data['heading'] : __('Quick links') }}</h3>
                                <ul class="space-y-2.5">
                                    @foreach ($footer['menu'] as $link)
                                        <li><a class="text-sm transition {{ $tone['link'] }}" href="{{ $link['href'] }}" @if ($link['new_tab']) target="_blank" rel="noopener noreferrer" @endif>{{ $link['label'] }}</a></li>
                                    @endforeach
                                </ul>
                            </nav>
                            @break

                        @case('custom_links')
                            <nav aria-label="{{ $data['heading'] ?? __('Links') }}">
                                @if (filled($data['heading'] ?? null))
                                    <h3 class="{{ $headingClass }}">{{ $data['heading'] }}</h3>
                                @endif
                                <ul class="space-y-2.5">
                                    @foreach ($data['links'] as $link)
                                        <li><a class="text-sm transition {{ $tone['link'] }}" href="{{ $link['href'] }}" @if ($link['new_tab']) target="_blank" rel="noopener noreferrer" @endif>{{ $link['label'] }}</a></li>
                                    @endforeach
                                </ul>
                            </nav>
                            @break

                        @case('contact_info')
                            <div>
                                <h3 class="{{ $headingClass }}">{{ filled($data['heading'] ?? null) ? $data['heading'] : __('Contact') }}</h3>
                                <ul class="space-y-3 text-sm {{ $tone['body'] }}">
                                    @if (filled($data['address'] ?? null))
                                        <li class="whitespace-pre-line">{{ $data['address'] }}</li>
                                    @endif
                                    @if (filled($data['email'] ?? null))
                                        <li><a href="mailto:{{ $data['email'] }}" class="break-all transition {{ $tone['link'] }}">{{ $data['email'] }}</a></li>
                                    @endif
                                    @if (($data['phones'] ?? []) !== [])
                                        <li class="flex flex-wrap gap-x-1.5 gap-y-1">
                                            @foreach ($data['phones'] as $phone)
                                                <span><a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}" class="transition {{ $tone['link'] }}">{{ $phone }}</a>{{ $loop->last ? '' : ',' }}</span>
                                            @endforeach
                                        </li>
                                    @endif
                                    @if (filled($data['hours'] ?? null))
                                        <li class="whitespace-pre-line">{{ $data['hours'] }}</li>
                                    @endif
                                </ul>
                                @if (filled($data['trade_license'] ?? null))
                                    <p class="mt-4 text-xs {{ $tone['muted'] }}">{{ $data['trade_license'] }}</p>
                                @endif
                            </div>
                            @break

                        @case('social_links')
                            <div>
                                @if (filled($data['heading'] ?? null))
                                    <h3 class="{{ $headingClass }}">{{ $data['heading'] }}</h3>
                                @endif
                                <div class="flex flex-wrap items-center gap-2">
                                    @foreach ($footer['social_links'] as $link)
                                        <a class="flex h-9 w-9 items-center justify-center rounded-full transition {{ $tone['social'] }}" href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ \App\Models\StorefrontSetting::SOCIAL_PLATFORMS[$link['platform'] ?? ''] ?? __('Social link') }}">
                                            @include('storefront.partials.social-icon', ['platform' => $link['platform']])
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                            @break

                        @case('payment_methods')
                            <div>
                                @if (filled($data['heading'] ?? null))
                                    <h3 class="mb-2 text-xs font-bold uppercase tracking-wide {{ $tone['muted'] }}">{{ $data['heading'] }}</h3>
                                @endif
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($data['items'] as $item)
                                        <span class="inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-bold {{ $tone['chip'] }} {{ $paymentTones[$item['tone']] ?? $paymentTones['neutral'] }}">{{ $item['label'] }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @break

                        @case('custom_text')
                            <div>
                                @if (filled($data['heading'] ?? null))
                                    <h3 class="{{ $headingClass }}">{{ $data['heading'] }}</h3>
                                @endif
                                @if (filled($data['text'] ?? null))
                                    <p class="whitespace-pre-line text-sm leading-relaxed {{ $tone['body'] }}">{{ $data['text'] }}</p>
                                @endif
                                @if (filled($data['button_label'] ?? null) && filled($data['button_url'] ?? null))
                                    <a href="{{ $data['button_url'] }}" class="mt-3 inline-flex rounded-lg bg-[var(--brand)] px-4 py-2 text-xs font-semibold text-white transition hover:opacity-90">{{ $data['button_label'] }}</a>
                                @endif
                            </div>
                            @break

                        @case('image')
                            <div>
                                @if (filled($data['heading'] ?? null))
                                    <h3 class="{{ $headingClass }}">{{ $data['heading'] }}</h3>
                                @endif
                                @if (filled($data['url'] ?? null))
                                    <a href="{{ $data['url'] }}" rel="noopener"><img src="{{ $data['image_url'] }}" alt="{{ $data['alt'] ?? '' }}" loading="lazy" class="max-h-24 w-auto"></a>
                                @else
                                    <img src="{{ $data['image_url'] }}" alt="{{ $data['alt'] ?? '' }}" loading="lazy" class="max-h-24 w-auto">
                                @endif
                            </div>
                            @break
                    @endswitch
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="border-t {{ $tone['divider'] }}">
        <div class="mx-auto flex max-w-6xl flex-col items-center gap-2 px-4 py-4 text-xs sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6 {{ $tone['muted'] }}">
            <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 sm:justify-start">
                <p>{{ $copyright }}</p>
                @if (($bottom['legal_links'] ?? []) !== [])
                    <ul class="flex flex-wrap gap-x-3 gap-y-1">
                        @foreach ($bottom['legal_links'] as $link)
                            <li><a href="{{ $link['href'] }}" class="transition {{ $tone['link'] }}">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <p class="text-center">
                @if (filled($bottom['center_text'] ?? null) && filled($bottom['center_url'] ?? null))
                    <a href="{{ $bottom['center_url'] }}" target="_blank" rel="noopener noreferrer" class="transition {{ $dark ? 'hover:text-white' : 'hover:text-[var(--brand)]' }}">{{ $fill($bottom['center_text']) }}</a>
                @elseif (filled($bottom['center_text'] ?? null))
                    {{ $fill($bottom['center_text']) }}
                @endif
            </p>
            <p class="text-center sm:text-right">
                @if (filled($bottom['right_text'] ?? null))
                    {!! str_replace('{heart}', '<span aria-label="love" class="text-red-500">♥</span>', e($bottom['right_text'])) !!}
                @endif
            </p>
        </div>
    </div>
</footer>
