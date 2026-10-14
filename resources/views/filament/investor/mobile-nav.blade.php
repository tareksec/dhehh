{{--
    Phones only (hidden from lg up, where the sidebar is always open): the
    most used pages along the bottom of the screen, and "Menu" for the rest.
--}}
@php
    use App\Filament\Investor\Pages\Dashboard;
    use App\Filament\Investor\Pages\MyInvestments;
    use App\Filament\Investor\Pages\Payouts;
    use App\Models\InvestorPortalAccount;
    use Filament\Facades\Filament;

    $account = Filament::auth()->user();
    $items = [];

    if ($account instanceof InvestorPortalAccount) {
        foreach ([[Dashboard::class, __('Home'), 'heroicon-o-home'], [MyInvestments::class, __('Investments'), 'heroicon-o-briefcase'], [Payouts::class, __('Payouts'), 'heroicon-o-banknotes']] as [$page, $label, $icon]) {
            if ($page::canAccess()) {
                $items[] = ['url' => $page::getUrl(), 'label' => $label, 'icon' => $icon, 'active' => request()->routeIs($page::getRouteName())];
            }
        }
    }
@endphp

@if ($account instanceof InvestorPortalAccount)
    <nav class="fi-investor-mobile-nav" aria-label="{{ __('Quick menu') }}">
        @foreach ($items as $item)
            <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif>
                <x-filament::icon :icon="$item['icon']" class="fi-investor-mobile-nav-icon" />
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
        <button
            type="button"
            x-data="{}"
            x-on:click="$store.sidebar.open()"
            aria-controls="fi-main-sidebar"
        >
            <x-filament::icon icon="heroicon-o-bars-3" class="fi-investor-mobile-nav-icon" />
            <span>{{ __('Menu') }}</span>
        </button>
    </nav>
@endif
