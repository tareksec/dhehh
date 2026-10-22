{{--
    The welcome panel beside the IMS sign-in forms. Layout from the owner's
    reference sign-in page (2026-10-20): a coloured panel with the logo at the
    top, a small badge, a big heading, one line of text and three numbered step
    cards at the bottom (the first one white); the form sits on the right. On
    phones the panel is a short block above the form with the steps in a row.
    No stock photos; the logo and name come from the company settings.

    $panel: 'investor' or 'office'
--}}
@php
    $company = app(\App\Services\CompanyContext::class)->company();
    $settings = app(\App\Services\CompanySettingsService::class);
    $name = $settings->name($company);
    $logo = $settings->logoUrl($company);
    $isOffice = ($panel ?? 'investor') === 'office';

    $steps = $isOffice
        ? [__('Sign in with your staff account'), __('Check new money and KYC'), __('Approve profit and payouts')]
        : [__('Sign in to your account'), __('See your money and profit'), __('Follow every payout')];
@endphp

<aside class="ims-auth-hero" aria-label="{{ $name }}">
    <div class="ims-auth-brand">
        @if ($logo)
            <span class="ims-auth-logo"><img src="{{ $logo }}" alt="" /></span>
        @endif
        <span class="ims-auth-brand-name">{{ $name }}</span>
    </div>

    <div class="ims-auth-body">
        <div class="ims-auth-copy">
            <span class="ims-auth-badge">
                <x-filament::icon icon="heroicon-m-shield-check" class="h-4 w-4" />
                {{ $isOffice ? __('IMS office') : __('Investor portal') }}
            </span>
            <h2 class="ims-auth-heading">
                {{ $isOffice ? __('Run investors, profit and payouts from one place') : __('Your investment, clear and simple') }}
            </h2>
            <p class="ims-auth-text">
                {{ $isOffice ? __('The IMS office for the company team.') : __('See your money, your profit and every payout in one place.') }}
            </p>
        </div>

        <ol class="ims-auth-steps" aria-label="{{ __('How it works') }}">
            @foreach ($steps as $i => $step)
                <li @class(['ims-auth-step', 'ims-auth-step-on' => $i === 0])>
                    <span class="ims-auth-step-number">{{ \App\Support\ImsNumbers::count($i + 1) }}</span>
                    <span class="ims-auth-step-label">{{ $step }}</span>
                </li>
            @endforeach
        </ol>
    </div>
</aside>
