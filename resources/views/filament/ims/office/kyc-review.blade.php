<x-filament-panels::page>
    @php
        $investor = $this->record;
        $pictures = $this->pictures();
        $checklist = $this->checklist();
    @endphp

    <x-filament::section>
        <div class="flex flex-wrap items-center gap-3">
            <x-filament::badge :color="\App\Models\Investor::kycStatusColor($investor->kyc_status)">
                {{ \App\Models\Investor::kycStatusLabel($investor->kyc_status) }}
            </x-filament::badge>
            <span class="text-sm text-gray-600">{{ __('Phone') }}: {{ $investor->phone }}</span>
            <span class="text-sm text-gray-600">{{ __('Date of birth') }}: {{ \App\Support\ImsNumbers::date($investor->date_of_birth) }}</span>
            <span class="text-sm text-gray-600">{{ __('NID number') }}: {{ $investor->investorId() ?? '—' }}</span>
        </div>
        @if ($investor->kyc_note)
            <p class="mt-3 text-sm text-danger-600">{{ __('Reason given') }}: {{ $investor->kyc_note }}</p>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('Pictures')">
        @if ($pictures->isEmpty())
            <p class="text-sm text-gray-600">{{ __('No pictures yet. Use "Send KYC for the investor" to add them.') }}</p>
        @else
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(16rem,100%),1fr))]">
                @foreach (\App\Models\InvestorKycDocument::REQUIRED as $type)
                    <figure class="ims-card flex flex-col gap-2">
                        <figcaption class="text-sm font-semibold">{{ \App\Models\InvestorKycDocument::typeLabel($type) }}</figcaption>
                        @if ($picture = $pictures->get($type))
                            <a href="{{ route('filament.office.files.kyc', $picture) }}" target="_blank" rel="noopener">
                                <img src="{{ route('filament.office.files.kyc', $picture) }}" alt="{{ \App\Models\InvestorKycDocument::typeLabel($type) }}" class="max-h-72 w-full rounded-xl object-contain" loading="lazy">
                            </a>
                        @else
                            <p class="text-sm text-danger-600">{{ __('Missing') }}</p>
                        @endif
                    </figure>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('Checklist')">
        <ul class="flex flex-col gap-2" data-ims-kyc-checklist>
            @foreach ($checklist as $key => $item)
                <li class="flex items-start gap-2" data-check="{{ $key }}">
                    @if ($item['ok'] === true)
                        <x-filament::icon icon="heroicon-s-check-circle" class="h-5 w-5 shrink-0 text-success-600" />
                    @elseif ($item['ok'] === false)
                        <x-filament::icon icon="heroicon-s-x-circle" class="h-5 w-5 shrink-0 text-danger-600" />
                    @else
                        <x-filament::icon icon="heroicon-s-question-mark-circle" class="h-5 w-5 shrink-0 text-warning-600" />
                    @endif
                    <span class="text-sm">{{ __($item['label']) }}</span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-panels::page>
