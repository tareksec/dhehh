<x-filament-widgets::widget class="ims-widget">
    @php
        $f = $this->figures();
        $kyc = $this->kycWaiting();
        $batches = \App\Filament\Resources\PayoutBatches\PayoutBatchResource::class;
        $settlements = \App\Filament\Resources\ProjectSettlements\ProjectSettlementResource::class;
        $kycPage = \App\Filament\Ims\Office\Resources\KycReviews\KycReviewResource::class;
    @endphp

    <section class="ims-card ims-stack" aria-labelledby="ims-payouts-due">
        <div class="ims-card-head">
            <span class="ims-card-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-paper-airplane" class="h-5 w-5" /></span>
            <div class="min-w-0 flex-1">
                <h2 id="ims-payouts-due" class="ims-card-title">{{ __('Payouts still to send') }}</h2>
                <p class="ims-kpi-note">{{ __('Confirmed, not paid yet') }}</p>
            </div>
        </div>

        <div class="ims-kpi-row">
            <x-ims.money class="ims-kpi-value ims-kpi-value-lg" :amount="$f['due']" :currency="$f['currency']" />
        </div>
        <p class="ims-kpi-note">{{ __('Profit paid this month: :amount', ['amount' => \App\Support\ImsNumbers::money($f['paid'], $f['currency'])]) }}</p>

        <div class="ims-button-pair">
            @if ($batches::canAccess())
                <a href="{{ $batches::getUrl(panel: 'office') }}" class="ims-btn ims-btn-primary">
                    {{ __('Send payouts') }}
                    <x-filament::icon icon="heroicon-m-arrow-up" class="h-4 w-4" />
                </a>
            @endif
            @if ($settlements::canAccess())
                <a href="{{ $settlements::getUrl(panel: 'office') }}" class="ims-btn ims-btn-ghost">
                    {{ __('See the list') }}
                    <x-filament::icon icon="heroicon-m-arrow-down" class="h-4 w-4" />
                </a>
            @endif
        </div>

        <div class="ims-subcard">
            <div class="ims-card-head">
                <div class="min-w-0 flex-1">
                    <h3 class="ims-card-title">{{ __('KYC waiting for review') }}</h3>
                    @if ($kyc['total'] > 0)
                        <p class="ims-kpi-note">{{ trans_choice(':count person is waiting|:count people are waiting', $kyc['total'], ['count' => \App\Support\ImsNumbers::count($kyc['total'])]) }}</p>
                    @endif
                </div>
                @if ($kycPage::canAccess())
                    <a href="{{ $kycPage::getUrl(panel: 'office') }}" class="ims-arrow-btn" aria-label="{{ __('Open KYC') }}">
                        <x-filament::icon icon="heroicon-o-arrow-up-right" class="h-5 w-5" />
                    </a>
                @endif
            </div>

            @if ($kyc['total'] > 0)
                <div class="ims-avatar-stack">
                    @foreach ($kyc['people'] as $person)
                        <x-ims.avatar :name="$person->name" />
                    @endforeach
                    @if ($kyc['total'] > $kyc['people']->count())
                        <span class="ims-avatar ims-avatar-md ims-avatar-more">+{{ \App\Support\ImsNumbers::count($kyc['total'] - $kyc['people']->count()) }}</span>
                    @endif
                </div>
            @else
                <p class="ims-kpi-note">{{ __('Nobody is waiting. Well done!') }}</p>
            @endif
        </div>
    </section>
</x-filament-widgets::widget>
