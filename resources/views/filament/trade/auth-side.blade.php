{{--
    The navy side of the Trade Portal's sign-in (and other simple pages):
    on wide screens a panel beside the form, on phones a band above it
    (theme.css "Sign-in page"). Interface text only.
--}}
<aside class="trade-auth-side" aria-label="{{ __('Trade Portal') }}">
    <div class="trade-auth-mark">
        <span class="trade-auth-logo"><x-filament::icon icon="heroicon-o-building-storefront" class="h-7 w-7" /></span>
        <span>{{ __('Trade Portal') }}</span>
    </div>

    <div class="trade-auth-copy">
        <h2 class="trade-auth-title">{{ __('Your shop in one place') }}</h2>
        <p class="trade-auth-text">{{ __('Sales, stock, dues, cash book and LC files — on your phone or computer.') }}</p>

        <ul class="trade-auth-points">
            <li><x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />{{ __('Write a delivery challan in a minute') }}</li>
            <li><x-filament::icon icon="heroicon-o-clock" class="h-5 w-5" />{{ __('See at once who owes you money') }}</li>
            <li><x-filament::icon icon="heroicon-o-globe-asia-australia" class="h-5 w-5" />{{ __('Follow every LC from opening to arrival') }}</li>
        </ul>
    </div>

    <x-filament::icon icon="heroicon-o-chart-bar" class="trade-auth-art" aria-hidden="true" />
</aside>
