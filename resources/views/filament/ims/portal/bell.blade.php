{{-- The notification bell (IMS-V2/PRD.md FR-60): a round button in the portal's top bar, with the unread count. --}}
@php
    $account = \Filament\Facades\Filament::auth()->user();
    $unread = $account instanceof \App\Models\InvestorPortalAccount ? \App\Filament\Ims\Portal\Pages\Notifications::unreadCount() : 0;
@endphp

@if ($account instanceof \App\Models\InvestorPortalAccount)
    <a
        href="{{ \App\Filament\Ims\Portal\Pages\Notifications::getUrl() }}"
        class="ims-round-btn ims-bell"
        data-ims-bell
        aria-label="{{ $unread > 0 ? __('Notifications (:count unread)', ['count' => \App\Support\ImsNumbers::count($unread)]) : __('Notifications') }}"
    >
        <x-filament::icon icon="heroicon-o-bell" class="h-5 w-5" />
        @if ($unread > 0)
            <span class="ims-bell-count" aria-hidden="true">{{ \App\Support\ImsNumbers::count($unread) }}</span>
        @endif
    </a>
@endif
