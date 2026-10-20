<x-filament-panels::page>
    @php $investor = $this->investor(); @endphp

    <x-filament::section>
        <div class="flex flex-wrap items-center gap-3" data-ims-kyc-status="{{ $investor->kyc_status }}">
            <span class="text-sm text-gray-600">{{ __('Your KYC') }}:</span>
            <x-filament::badge :color="\App\Models\Investor::kycStatusColor($investor->kyc_status)">
                {{ \App\Models\Investor::kycStatusLabel($investor->kyc_status) }}
            </x-filament::badge>
        </div>

        @switch($investor->kyc_status)
            @case(\App\Models\Investor::KYC_VERIFIED)
                <p class="mt-3 text-sm">{{ __('Your KYC is verified. Nothing more to do here.') }}</p>
                @break
            @case(\App\Models\Investor::KYC_PENDING)
                <p class="mt-3 text-sm">{{ __('The office is checking your pictures. You can send them again if you took a wrong one.') }}</p>
                @break
            @case(\App\Models\Investor::KYC_REJECTED)
                <p class="mt-3 text-sm text-danger-600">{{ __('Not accepted: :reason', ['reason' => $investor->kyc_note]) }}</p>
                <p class="mt-1 text-sm">{{ __('Please take the pictures again and send them.') }}</p>
                @break
            @default
                <p class="mt-3 text-sm">{{ __('New money can be put in only after your KYC is verified. It takes three pictures.') }}</p>
        @endswitch
    </x-filament::section>

    @if ($this->canSend())
        <form wire:submit="send" class="flex flex-col gap-6">
            <x-filament::section :heading="__('Take three pictures')" :description="__('Lay the card flat in good light, without glare. All four corners must be in the picture.')">
                {{ $this->form }}
            </x-filament::section>

            <div>
                <x-filament::button type="submit" size="lg" icon="heroicon-o-paper-airplane">{{ __('Send for checking') }}</x-filament::button>
            </div>
        </form>
    @endif
</x-filament-panels::page>
