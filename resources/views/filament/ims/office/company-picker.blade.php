{{-- The office's company picker (18_IMS_ALL_COMPANIES_PLAN.md §3.2): the open company, and every other company this person may open. In the top bar, and on small phones in the menu drawer ($drawer). Hidden when there is only one. --}}
@php
    $user = \Filament\Facades\Filament::auth()->user();
    $current = app(\App\Services\CompanyContext::class)->company();
    $settings = app(\App\Services\CompanySettingsService::class);
    $host = \App\Models\Company::forImsHost(request()->getHost())?->ims_domain;
    $companies = $user instanceof \App\Models\User && $current
        ? \App\Support\ModuleGuard::run('ims-office-company-picker', fn () => app(\App\Services\ImsOfficeCompanies::class)->for($user, $host), collect())
        : collect();
    $home = \App\Filament\Ims\Office\Pages\Home::getUrl(panel: 'office');
    $link = fn ($company): string => $home.'?'.\App\Services\ImsOfficeCompanies::QUERY_KEY.'='.$company->getKey();
    $isCurrent = fn ($company): bool => (int) $company->getKey() === (int) $current->getKey();
@endphp

@if ($companies->count() > 1)
    @if ($drawer ?? false)
        <nav class="fi-ims-company-drawer" aria-label="{{ __('Choose company') }}">
            <p class="fi-ims-company-drawer-title">{{ __('Choose company') }}</p>
            @foreach ($companies as $company)
                <a href="{{ $link($company) }}" @if ($isCurrent($company)) aria-current="true" @endif>
                    <x-filament::icon :icon="$isCurrent($company) ? 'heroicon-o-check-circle' : 'heroicon-o-building-office-2'" class="h-5 w-5" />
                    <span>{{ $settings->name($company) }}</span>
                </a>
            @endforeach
        </nav>
    @else
        <x-filament::dropdown placement="bottom-end" teleport class="fi-ims-company-picker">
            <x-slot name="trigger">
                <button type="button" class="fi-ims-company-picker-btn" aria-label="{{ __('Choose company') }}" title="{{ __('Choose company') }}">
                    <x-filament::icon icon="heroicon-o-building-office-2" class="h-5 w-5" />
                    <span class="fi-ims-company-picker-name">{{ $settings->name($current) }}</span>
                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
                </button>
            </x-slot>

            <x-filament::dropdown.header icon="heroicon-o-building-office-2">
                {{ __('Choose company') }}
            </x-filament::dropdown.header>

            <x-filament::dropdown.list>
                @foreach ($companies as $company)
                    <x-filament::dropdown.list.item
                        tag="a"
                        :href="$link($company)"
                        :icon="$isCurrent($company) ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-right-circle'"
                        :color="$isCurrent($company) ? 'primary' : 'gray'"
                    >
                        {{ $settings->name($company) }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endif
@endif
