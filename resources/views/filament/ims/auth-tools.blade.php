{{--
    Above the IMS sign-in forms: the light / dark switch and the panel's
    English / বাংলা switch, side by side in the form's top corner.

    $panel: 'investor' or 'office'
--}}
{{-- The word on the "Or" line above a second way to sign in (theme.css reads it). --}}
<style>:root { --ims-or-label: "{{ __('Or') }}"; }</style>

<div class="ims-auth-tools">
    @include('filament.ims.theme-toggle')

    @if (($panel ?? 'investor') === 'office')
        @include('filament.ims.office.language-switch', ['simple' => true])
    @else
        @include('filament.investor.language-switch', ['simple' => true])
    @endif
</div>
