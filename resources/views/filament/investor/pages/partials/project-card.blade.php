@php
    use App\Models\ProjectSettlement;
    use App\Services\InvestorPortalCards;
    use App\Support\MoneyFormatter;

    $state = $card['state'];
    $settlement = $card['settlement'];
    $payout = $card['payout'];
    [$pill, $label] = match ($state) {
        InvestorPortalCards::RUNNING => ['bg-[#e6ecf5] text-[#24406e] dark:bg-[#5b7bd5]/20 dark:text-[#c6d4f5]', __('Running')],
        InvestorPortalCards::COMPLETED => ['bg-[#e3f1e7] text-[#1d5b33] dark:bg-[#9bd35a]/15 dark:text-[#cdeaa9]', __('Completed')],
        InvestorPortalCards::PAYMENT_PENDING => ['bg-[#fff1d6] text-[#7a4a06] dark:bg-[#c98a1b]/20 dark:text-[#ffd894]', __('Payment on the way')],
        InvestorPortalCards::PAID => ['bg-[#eceff3] text-[#2f3640] dark:bg-white/10 dark:text-gray-200', __('Paid')],
        default => ['bg-[#eceff3] text-[#4a5260] dark:bg-white/10 dark:text-gray-300', __('Withdrawn')],
    };
    $running = $state === InvestorPortalCards::RUNNING && $card['project']?->status !== 'open';
    $filled = $running ? (int) round($card['progress'] / 10) : 10;
@endphp

<article class="flex min-w-0 flex-col gap-4 rounded-3xl bg-white p-6 shadow-(--portal-tile-shadow) dark:bg-gray-900 dark:ring-1 dark:ring-white/10" data-project-card="{{ $state }}">
    <div class="flex items-start justify-between gap-3">
        <h3 class="text-lg font-bold">{{ $card['title'] }}</h3>
        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-bold {{ $pill }}">
            <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>{{ $label }}
        </span>
    </div>

    <div class="text-2xl font-bold tracking-tight tabular-nums">{{ MoneyFormatter::currency($card['amount'], $currency) }}</div>

    {{-- Progress towards the end of the project in ten blocks; all ten once its cycle is over. --}}
    @if ($running || $state !== InvestorPortalCards::RUNNING)
        <div class="flex flex-col gap-2">
            <div
                class="flex gap-1"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="{{ $running ? $card['progress'] : 100 }}"
                aria-label="{{ __('Progress') }}"
            >
                @for ($block = 1; $block <= 10; $block++)
                    <span @class([
                        'h-5 flex-1 rounded-md',
                        'bg-(--portal-ink) dark:bg-(--portal-accent)' => $running && $block <= $filled,
                        'bg-[#c98a1b]' => ! $running,
                        'bg-[#e3e7ed] dark:bg-white/10' => $running && $block > $filled,
                    ])></span>
                @endfor
            </div>

            @if ($running)
                <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                    <span>{{ __('Progress') }}</span>
                    <span class="font-bold tabular-nums text-gray-950 dark:text-white">{{ $card['progress'] }}%</span>
                </div>
                @if ($card['ends_on'])
                    <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Ends on :date', ['date' => $card['ends_on']->format('d M Y')]) }}</div>
                @endif
            @else
                <div class="flex items-center gap-1.5 text-sm font-semibold text-[#1d5b33] dark:text-[#cdeaa9]">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
                    {{ $card['number'] ? __('Project :number completed', ['number' => $card['number']]) : __('Project completed') }}
                </div>
            @endif
        </div>
    @endif

    @if ($state === InvestorPortalCards::COMPLETED || $settlement || $payout || $state === InvestorPortalCards::PAID)
    <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 border-t border-gray-100 pt-4 text-sm dark:border-white/10">
        @if ($state === InvestorPortalCards::COMPLETED)
            <dt class="text-gray-600 dark:text-gray-400">{{ __('Next') }}</dt>
            <dd>{{ __('The company is calculating your profit.') }}</dd>
        @endif

        @if ($settlement)
            @if ($settlement->rate_per_lac !== null)
                <dt class="text-gray-600 dark:text-gray-400">{{ __('Profit per lakh') }}</dt>
                <dd class="tabular-nums">{{ MoneyFormatter::currency($settlement->rate_per_lac, $currency) }}</dd>
            @endif
            @if ($settlement->annualized_return_percent !== null)
                <dt class="text-gray-600 dark:text-gray-400">{{ __('Annualized return') }}</dt>
                <dd class="tabular-nums">{{ MoneyFormatter::number($settlement->annualized_return_percent) }}%</dd>
            @endif
            @if ($settlement->outcome !== 'profit')
                <dt class="text-gray-600 dark:text-gray-400">{{ __('Result') }}</dt>
                <dd>{{ __(ProjectSettlement::OUTCOMES[$settlement->outcome] ?? $settlement->outcome) }}</dd>
            @endif
        @endif

        @if ($payout)
            <dt class="text-gray-600 dark:text-gray-400">{{ __('Your profit') }}</dt>
            <dd class="tabular-nums">{{ MoneyFormatter::currency($card['profit'], $currency) }}</dd>
            @if (($card['principal_returned'] ?? 0) > 0)
                <dt class="text-gray-600 dark:text-gray-400">{{ __('Capital returned') }}</dt>
                <dd class="tabular-nums">{{ MoneyFormatter::currency($card['principal_returned'], $currency) }}</dd>
            @endif
            <dt class="text-gray-600 dark:text-gray-400">{{ $state === InvestorPortalCards::PAID ? __('Paid to you') : __('You receive') }}</dt>
            <dd class="font-bold tabular-nums">{{ MoneyFormatter::currency($card['total_payout'], $currency) }}</dd>
        @endif

        @if ($state === InvestorPortalCards::PAID)
            <dt class="text-gray-600 dark:text-gray-400">{{ __('Paid on') }}</dt>
            <dd>{{ $payout->paid_at?->format('d M Y') ?? '-' }}</dd>
            <dt class="text-gray-600 dark:text-gray-400">{{ __('Screenshot') }}</dt>
            <dd>{{ __('The company will add the payment screenshot here.') }}</dd>
        @endif
    </dl>
    @endif
</article>
