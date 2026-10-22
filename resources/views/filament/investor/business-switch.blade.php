{{-- One person, many businesses (17_IMS_IN_PORTAL_PLAN.md §5): the other businesses this phone has an active portal account in. Hidden when there are none. --}}
@php
    $account = \Filament\Facades\Filament::auth()->user();
    $current = app(\App\Services\CompanyContext::class)->company();
    $settings = app(\App\Services\CompanySettingsService::class);
    $businesses = $account instanceof \App\Models\InvestorPortalAccount
        ? \App\Support\ModuleGuard::run('investor-business-switch', fn () => app(\App\Services\InvestorBusinessSwitchService::class)->otherBusinesses($account), collect())
        : collect();
@endphp

@if ($businesses->isNotEmpty())
    <x-filament::dropdown placement="bottom-end" teleport class="fi-investor-business-switch">
        <x-slot name="trigger">
            <button type="button" class="ims-round-btn" aria-label="{{ __('Switch business') }}" title="{{ __('Switch business') }}">
                <x-filament::icon icon="heroicon-o-building-office-2" class="h-5 w-5" />
            </button>
        </x-slot>

        <x-filament::dropdown.header icon="heroicon-o-check-circle">
            {{ $settings->name($current) }}
        </x-filament::dropdown.header>

        <x-filament::dropdown.list>
            @foreach ($businesses as $entry)
                <form method="POST" action="{{ route('filament.investor.switch-business', ['company' => $entry['company']->getKey()]) }}">
                    @csrf
                    <x-filament::dropdown.list.item tag="button" type="submit" icon="heroicon-o-arrow-right-circle">
                        {{ $settings->name($entry['company']) }}
                    </x-filament::dropdown.list.item>
                </form>
            @endforeach
        </x-filament::dropdown.list>

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item tag="a" :href="\App\Filament\Investor\Pages\MyBusinesses::getUrl()" icon="heroicon-o-building-office-2">
                {{ __('My businesses') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
