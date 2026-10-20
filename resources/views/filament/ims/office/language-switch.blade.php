{{-- The office's English / বাংলা switch: the top bar, the sign-in page ($simple) and, on small phones, the menu drawer ($drawer). --}}
@php
    $current = app()->getLocale();
    $languages = ['en' => 'English', 'bn' => 'বাংলা'];
@endphp

<nav
    aria-label="{{ __('Language') }}"
    @class([
        'fi-ims-language',
        'fi-ims-language-simple' => $simple ?? false,
        'fi-ims-language-drawer' => $drawer ?? false,
    ])
>
    @foreach ($languages as $code => $name)
        <a
            href="{{ route('filament.office.language', ['locale' => $code]) }}"
            lang="{{ $code }}"
            @if ($current === $code) aria-current="true" @endif
        >{{ $name }}</a>
    @endforeach
</nav>
