{{--
    Phones only (hidden from lg up, where the sidebar is always open): the five
    tabs of IMS v2 (IMS-V2/PRD.md FR-73) — Home · Investments · Profit · Feed ·
    Profile. The Profile page lists every other page.
--}}
@php
    use App\Filament\Ims\Portal\Pages\Feed;
    use App\Filament\Investor\Pages\Dashboard;
    use App\Filament\Investor\Pages\MyInvestments;
    use App\Filament\Investor\Pages\Payouts;
    use App\Filament\Investor\Pages\Profile;
    use App\Models\InvestorPortalAccount;
    use Filament\Facades\Filament;

    $account = Filament::auth()->user();
    $items = [];

    if ($account instanceof InvestorPortalAccount) {
        foreach ([
            [Dashboard::class, __('Home'), 'heroicon-o-home'],
            [MyInvestments::class, __('Investments'), 'heroicon-o-briefcase'],
            [Payouts::class, __('Profit'), 'heroicon-o-banknotes'],
            [Feed::class, __('Feed'), 'heroicon-o-newspaper'],
            [Profile::class, __('Profile'), 'heroicon-o-user-circle'],
        ] as [$page, $label, $icon]) {
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
    </nav>
@endif
