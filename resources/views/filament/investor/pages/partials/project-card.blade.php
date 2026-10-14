@php
    use App\Models\ProjectSettlement;
    use App\Services\InvestorPortalCards;
    use App\Support\MoneyFormatter;

    $state = $card['state'];
    $settlement = $card['settlement'];
    $payout = $card['payout'];
    $badge = match ($state) {
        InvestorPortalCards::RUNNING => ['info', __('Running')],
        InvestorPortalCards::COMPLETED => ['success', __('Completed')],
        InvestorPortalCards::PAYMENT_PENDING => ['warning', __('Payment on the way')],
        InvestorPortalCards::PAID => ['primary', __('Paid')],
        default => ['gray', __('Withdrawn')],
    };
@endphp

<div class="rounded-xl border border-gray-200 p-4 dark:border-white/10" data-project-card="{{ $state }}">
    <div class="flex items-start justify-between gap-2">
        <div class="font-semibold">{{ $card['title'] }}</div>
        <x-filament::badge class="shrink-0" :color="$badge[0]">{{ $badge[1] }}</x-filament::badge>
    </div>

    <div class="mt-2 text-lg font-semibold">{{ MoneyFormatter::currency($card['amount'], $currency) }}</div>

    {{-- Progress towards the end of the project; full once its cycle is over. --}}
    @if ($state === InvestorPortalCards::RUNNING && $card['project']?->status !== 'open')
        <div class="mt-3">
            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>{{ __('Progress') }}</span>
                <span>{{ $card['progress'] }}%</span>
            </div>
            <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $card['progress'] }}">
                <div class="h-2 rounded-full bg-primary-600" style="width: {{ $card['progress'] }}%"></div>
            </div>
            @if ($card['ends_on'])
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Ends on :date', ['date' => $card['ends_on']->format('d M Y')]) }}</div>
            @endif
        </div>
    @elseif ($state !== InvestorPortalCards::RUNNING)
        <div class="mt-3">
            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100">
                <div class="h-2 rounded-full bg-success-600" style="width: 100%"></div>
            </div>
            <div class="mt-2 flex items-center gap-1 text-sm font-medium text-success-700 dark:text-success-400">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
                {{ $card['number'] ? __('Project :number completed', ['number' => $card['number']]) : __('Project completed') }}
            </div>
        </div>
    @endif

    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
        @if ($state === InvestorPortalCards::COMPLETED)
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Next') }}</dt>
            <dd>{{ __('The company is calculating your profit.') }}</dd>
        @endif

        @if ($settlement)
            @if ($settlement->rate_per_lac !== null)
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Profit per lakh') }}</dt>
                <dd>{{ MoneyFormatter::currency($settlement->rate_per_lac, $currency) }}</dd>
            @endif
            @if ($settlement->annualized_return_percent !== null)
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Annualized return') }}</dt>
                <dd>{{ MoneyFormatter::number($settlement->annualized_return_percent) }}%</dd>
            @endif
            @if ($settlement->outcome !== 'profit')
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Result') }}</dt>
                <dd>{{ __(ProjectSettlement::OUTCOMES[$settlement->outcome] ?? $settlement->outcome) }}</dd>
            @endif
        @endif

        @if ($payout)
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Your profit') }}</dt>
            <dd>{{ MoneyFormatter::currency($card['profit'], $currency) }}</dd>
            @if (($card['principal_returned'] ?? 0) > 0)
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Capital returned') }}</dt>
                <dd>{{ MoneyFormatter::currency($card['principal_returned'], $currency) }}</dd>
            @endif
            <dt class="text-gray-500 dark:text-gray-400">{{ $state === InvestorPortalCards::PAID ? __('Paid to you') : __('You receive') }}</dt>
            <dd class="font-semibold">{{ MoneyFormatter::currency($card['total_payout'], $currency) }}</dd>
        @endif

        @if ($state === InvestorPortalCards::PAID)
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Paid on') }}</dt>
            <dd>{{ $payout->paid_at?->format('d M Y') ?? '-' }}</dd>
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Screenshot') }}</dt>
            <dd>{{ __('The company will add the payment screenshot here.') }}</dd>
        @endif
    </dl>
</div>
