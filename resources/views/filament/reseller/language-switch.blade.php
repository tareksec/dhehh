{{-- Bangla / English switch on the portal's sign-in and application pages (SetResellerPortalLocale keeps the choice). --}}
@php($current = app()->getLocale())
<p class="flex items-center justify-end gap-2 text-sm text-gray-600 dark:text-gray-300" data-reseller-language-switch>
    <span>{{ __('Language') }}:</span>
    @foreach (['en' => __('Language name: English', [], 'en'), 'bn' => __('Language name: Bangla', [], 'bn')] as $code => $name)
        <a href="?lang={{ $code }}" lang="{{ $code }}" hreflang="{{ $code }}" @if ($current === $code) aria-current="true" @endif
            class="rounded-md px-2 py-0.5 font-semibold {{ $current === $code ? 'bg-primary-600 text-white' : 'text-primary-600 underline dark:text-primary-400' }}">{{ $name }}</a>
    @endforeach
</p>
