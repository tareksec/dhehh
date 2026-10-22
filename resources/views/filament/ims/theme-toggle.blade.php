{{--
    Light / dark switch for both IMS panels: a round moon button in light
    mode, a sun in dark mode. It hands the choice to Filament's own theme
    store ("theme-changed"), which keeps it in the browser; light is the
    default (->defaultThemeMode(ThemeMode::Light)).
--}}
<button
    type="button"
    x-data
    x-on:click="$dispatch('theme-changed', $store.theme === 'dark' ? 'light' : 'dark')"
    x-bind:aria-pressed="$store.theme === 'dark' ? 'true' : 'false'"
    x-bind:aria-label="$store.theme === 'dark' ? @js(__('Switch to light mode')) : @js(__('Switch to dark mode'))"
    x-bind:title="$store.theme === 'dark' ? @js(__('Switch to light mode')) : @js(__('Switch to dark mode'))"
    aria-label="{{ __('Switch to dark mode') }}"
    class="ims-round-btn ims-theme-toggle"
>
    <x-filament::icon icon="heroicon-o-moon" class="ims-theme-toggle-moon h-5 w-5" />
    <x-filament::icon icon="heroicon-o-sun" class="ims-theme-toggle-sun h-5 w-5" />
</button>
