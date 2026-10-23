{{-- The Trade Portal's বাংলা / English switch: the top bar, the sign-in page ($simple) and the phone menu sheet ($sheet). --}}
@php
    $current = app()->getLocale();
    $languages = ['bn' => 'বাংলা', 'en' => 'English'];
@endphp

<nav
    aria-label="{{ __('Language') }}"
    @class([
        'trade-language',
        'trade-language-simple' => $simple ?? false,
        'trade-language-sheet' => $sheet ?? false,
    ])
>
    @foreach ($languages as $code => $name)
        <a
            href="{{ route('filament.trade.language', ['locale' => $code]) }}"
            lang="{{ $code }}"
            @if ($current === $code) aria-current="true" @endif
        >{{ $name }}</a>
    @endforeach
</nav>
