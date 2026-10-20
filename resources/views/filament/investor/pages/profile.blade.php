<x-filament-panels::page>
    {{-- IMS v2 (DESIGN.md §7 MenuList): every page of the portal in one list, for phones. --}}
    @php
        $links = collect([
            [\App\Filament\Ims\Portal\Pages\Kyc::class, __('KYC'), 'heroicon-o-identification'],
            [\App\Filament\Ims\Portal\Pages\Nominees::class, __('Nominees'), 'heroicon-o-users'],
            [\App\Filament\Investor\Pages\BankAccounts::class, __('Bank accounts'), 'heroicon-o-building-library'],
            [\App\Filament\Investor\Pages\Agreements::class, __('Agreements'), 'heroicon-o-document-check'],
            [\App\Filament\Investor\Pages\Documents::class, __('Documents'), 'heroicon-o-folder'],
            [\App\Filament\Ims\Portal\Pages\Projects::class, __('Projects'), 'heroicon-o-briefcase'],
            [\App\Filament\Investor\Pages\Statement::class, __('Statement'), 'heroicon-o-document-text'],
            [\App\Filament\Investor\Pages\Requests::class, __('Requests'), 'heroicon-o-inbox-arrow-down'],
            [\App\Filament\Ims\Portal\Pages\Support::class, __('Support'), 'heroicon-o-chat-bubble-left-right'],
            [\App\Filament\Ims\Portal\Pages\Notifications::class, __('Notifications'), 'heroicon-o-bell'],
            [\App\Filament\Investor\Pages\MyInvestors::class, __('My investors'), 'heroicon-o-user-group'],
            [\App\Filament\Investor\Pages\Commissions::class, __('Commissions'), 'heroicon-o-currency-dollar'],
            [\App\Filament\Investor\Pages\Invite::class, __('Invite'), 'heroicon-o-user-plus'],
        ])->filter(fn (array $link): bool => $link[0]::canAccess());
    @endphp

    <x-filament::section class="lg:hidden" data-ims-menu-list>
        <ul>
            @foreach ($links as [$page, $label, $icon])
                <li>
                    <a href="{{ $page::getUrl() }}" class="ims-todo-item">
                        <span class="flex items-center gap-3">
                            <x-filament::icon :icon="$icon" class="h-5 w-5 text-primary-600" />
                            <span>{{ $label }}</span>
                        </span>
                        <x-filament::icon icon="heroicon-m-chevron-right" class="h-5 w-5 text-gray-400" />
                    </a>
                </li>
            @endforeach
        </ul>
        <div class="mt-3">
            @include('filament.investor.language-switch')
        </div>
    </x-filament::section>

    {{ $this->details }}

    @if ($this->isStaffPreview())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('A staff preview cannot change a password.') }}</p>
    @else
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}

            <div>
                <x-filament::button type="submit">
                    {{ __('Change password') }}
                </x-filament::button>
            </div>
        </form>
    @endif
</x-filament-panels::page>
