@php
    $preview = \App\Support\InvestorPortalStaffPreview::current();
    $account = $preview ? \Filament\Facades\Filament::auth()->user() : null;
@endphp

@if ($preview && \App\Support\InvestorPortalStaffPreview::isFor($account))
    <div
        role="status"
        style="margin: 0 0 1rem; border: 1px solid rgb(245 158 11 / .35); background: rgb(254 243 199 / .95); color: #92400e; border-radius: 8px; padding: .75rem 1rem; font-size: .875rem; font-weight: 700;"
    >
        {{ __('Staff preview: you are looking at the portal as :name (opened by :staff). Nothing can be changed here, and it does not count as :name signing in.', ['name' => $account->name, 'staff' => $preview['staff_name']]) }}
    </div>
@endif
