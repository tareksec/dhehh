{{-- English / বাংলা switch: the sidebar footer, and above the sign-in forms ($simple). --}}
@php
    $current = app()->getLocale();
    $languages = ['en' => 'English', 'bn' => 'বাংলা'];
@endphp

<nav
    aria-label="{{ __('Language') }}"
    @class([
        'fi-investor-language',
        'fi-investor-language-simple' => $simple ?? false,
    ])
>
    @foreach ($languages as $code => $name)
        <a
            href="{{ route('filament.investor.language', ['locale' => $code]) }}"
            lang="{{ $code }}"
            @if ($current === $code) aria-current="true" @endif
        >{{ $name }}</a>
    @endforeach
</nav>
