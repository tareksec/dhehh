{{-- One LC file (ViewLcFile): the stages, the main numbers, and the five papers with print or what is missing. --}}
@php
    use App\Models\LcFile;
    use App\Support\ImsNumbers;

    $file = $this->getRecord()->loadMissing(['purchase.supplier', 'purchase.company', 'purchase.items.product']);
    $purchase = $file->purchase;
    $stages = array_keys(LcFile::STAGES);
    $at = array_search($file->stage, $stages, true);
    $date = fn ($value): ?string => $value ? ImsNumbers::date($value) : null;
@endphp

<x-filament-panels::page>
    <section class="trade-card" aria-labelledby="trade-lc-stage">
        <h2 id="trade-lc-stage" class="trade-card-title">{{ __('Stage') }}</h2>
        <ol class="trade-steps">
            @foreach ($stages as $index => $stage)
                <li @class(['trade-step', 'trade-step-done' => $at !== false && $index < $at, 'trade-step-now' => $index === $at])>
                    <span class="trade-step-dot">
                        @if ($at !== false && $index < $at)
                            <x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />
                        @else
                            {{ ImsNumbers::digits((string) ($index + 1)) }}
                        @endif
                    </span>
                    <span>{{ LcFile::stageLabel($stage) }}</span>
                </li>
            @endforeach
        </ol>
        <p class="trade-card-note">{{ __('Documents at the bank') }}: <strong>{{ LcFile::docStatusLabel($file->doc_status) }}</strong></p>
    </section>

    <section class="trade-card" aria-labelledby="trade-lc-facts">
        <h2 id="trade-lc-facts" class="trade-card-title">{{ __('The file') }}</h2>
        <dl class="trade-facts">
            <div><dt>{{ __('Supplier') }}</dt><dd>{{ $purchase?->supplier?->company_name ?: $purchase?->supplier?->name }}</dd></div>
            <div><dt>{{ __('LC value (USD)') }}</dt><dd>$ {{ number_format($file->lcValue(), 2) }}</dd></div>
            @if ($purchase?->pi_number)<div><dt>{{ __('PI no.') }}</dt><dd>{{ $purchase->pi_number }} · {{ $date($purchase->pi_date) }}</dd></div>@endif
            @if ($purchase?->lc_number)<div><dt>{{ __('LC no.') }}</dt><dd>{{ $purchase->lc_number }} · {{ $date($purchase->lc_date) }}</dd></div>@endif
            @if ($file->margin_percent !== null)<div><dt>{{ __('Margin %') }}</dt><dd>{{ ImsNumbers::percent($file->margin_percent) }} · $ {{ number_format($file->marginUsd(), 2) }}@if ($file->margin_amount_bdt) · {{ ImsNumbers::money($file->margin_amount_bdt) }}@endif</dd></div>@endif
            @if ($file->cover_note_number)<div><dt>{{ __('Marine cover note no.') }}</dt><dd>{{ $file->cover_note_number }} · {{ $date($file->cover_note_date) }}</dd></div>@endif
            @if ($file->vessel_name || $file->carrier)<div><dt>{{ __('Vessel name') }}</dt><dd>{{ collect([$file->carrier, $file->vessel_name])->filter()->implode(' · ') }}</dd></div>@endif
            @if ($file->bl_number)<div><dt>{{ __('Bill of lading no.') }}</dt><dd>{{ $file->bl_number }} · {{ $date($file->bl_date) }}</dd></div>@endif
            @if ($file->container_number)<div><dt>{{ __('Container no.') }}</dt><dd>{{ $file->container_number }} · {{ $file->seal_number }}</dd></div>@endif
            @if ($file->eta)<div><dt>{{ __('ETA (arrives)') }}</dt><dd>{{ $date($file->eta) }}</dd></div>@endif
            <div><dt>{{ __('Net and gross weight of this shipment') }}</dt><dd>{{ number_format($file->netWeight(), 0) }} / {{ number_format($file->grossWeight(), 0) }} KGS</dd></div>
        </dl>
    </section>

    <section class="trade-card" aria-labelledby="trade-lc-papers">
        <h2 id="trade-lc-papers" class="trade-card-title">{{ __('Papers') }}</h2>
        <ul class="trade-list">
            @foreach (LcFile::DOCUMENTS as $document => $title)
                @php($missing = $file->missingFor($document))
                <li>
                    <span>
                        {{ __($title) }}
                        @if ($missing !== [])
                            <small class="trade-out">{{ __('Still needed: :fields', ['fields' => collect($missing)->map(fn (string $field): string => __($field))->implode(', ')]) }}</small>
                        @endif
                    </span>
                    @if ($missing === [])
                        <x-filament::button tag="a" size="sm" icon="heroicon-o-printer" :href="route('filament.trade.lc-files.document', ['lcFile' => $file, 'document' => $document])" target="_blank">{{ __('Print') }}</x-filament::button>
                    @else
                        <x-filament::badge color="gray">{{ __('Not ready') }}</x-filament::badge>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
</x-filament-panels::page>
