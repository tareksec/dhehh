{{-- Bangla / English switch of the reseller portal's public pages (SetResellerPortalLocale keeps the choice). --}}
@php($current = app()->getLocale())
<div class="nb-lang" role="group" aria-label="{{ __('Change language') }}" data-reseller-language-switch>
    @foreach (['en' => __('Language name: English', [], 'en'), 'bn' => __('Language name: Bangla', [], 'bn')] as $code => $name)
        <a href="?lang={{ $code }}" lang="{{ $code }}" hreflang="{{ $code }}" @if ($current === $code) aria-current="true" @endif class="nb-lang__item{{ $current === $code ? ' is-active' : '' }}">{{ $name }}</a>
    @endforeach
</div>
