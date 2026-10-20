{{--
    The welcome panel beside the IMS sign-in forms (IMS-V2/DESIGN.md §8.1
    "স্বাগত / লগইন", the reference's first phone screen): the company's logo and
    name, a big heading, one line of text and a small drawn preview of the
    dashboard. On phones it is a short green block above the form. No stock
    photos; the logo and name come from the company settings.

    $panel: 'investor' or 'office'
--}}
@php
    $company = app(\App\Services\CompanyContext::class)->company();
    $settings = app(\App\Services\CompanySettingsService::class);
    $name = $settings->name($company);
    $logo = $settings->logoUrl($company);
    $isOffice = ($panel ?? 'investor') === 'office';
@endphp

<aside class="ims-auth-hero" aria-label="{{ $name }}">
    <div class="ims-auth-brand">
        @if ($logo)
            <span class="ims-auth-logo"><img src="{{ $logo }}" alt="" /></span>
        @endif
        <span class="ims-auth-brand-name">{{ $name }}</span>
    </div>

    <div class="ims-auth-copy">
        <h2 class="ims-auth-heading">
            {{ $isOffice ? __('Run investors, profit and payouts from one place') : __('Your investment, clear and simple') }}
        </h2>
        <p class="ims-auth-text">
            {{ $isOffice ? __('The IMS office for the company team.') : __('See your money, your profit and every payout in one place.') }}
        </p>
    </div>

    {{-- A small drawn preview of the dashboard: decoration only. --}}
    <div class="ims-auth-preview" aria-hidden="true">
        <div class="ims-auth-preview-card ims-auth-preview-balance">
            <span class="ims-auth-preview-line w-1/3"></span>
            <span class="ims-auth-preview-big"></span>
            <span class="ims-auth-preview-line w-1/2"></span>
        </div>
        <div class="ims-auth-preview-card ims-auth-preview-chart">
            @foreach ([45, 70, 55, 92, 64, 80] as $i => $h)
                <span @class(['ims-auth-preview-bar', 'ims-auth-preview-bar-on' => $i === 3]) style="height: {{ $h }}%"></span>
            @endforeach
        </div>
    </div>
</aside>
