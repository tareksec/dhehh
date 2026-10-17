{{-- The payout to follow (InvestorPortalCards::nextPayout()): its amount and its four steps. --}}
@php
    use App\Support\MoneyFormatter;

    $card = $next['card'];
    $stateText = ['done' => __('Done'), 'current' => __('In progress'), 'next' => __('Still to come')];
@endphp

<section aria-labelledby="investor-next-payout" class="flex min-w-0 flex-[1_1_18rem] flex-col gap-5 rounded-3xl bg-(--portal-accent) p-6 text-(--portal-ink) shadow-(--portal-tile-shadow) sm:p-7" data-investor-next-payout>
    <div class="flex flex-col gap-1">
        <h2 id="investor-next-payout" class="text-base font-semibold">{{ __('Next payout') }}</h2>
        @if ($card['total_payout'] !== null)
            <p class="text-4xl font-bold tracking-tight tabular-nums">{{ MoneyFormatter::currency($card['total_payout'], $currency) }}</p>
        @endif
        <p class="text-sm">{{ $card['title'] }}</p>
    </div>

    <ol class="flex flex-col">
        @foreach ($next['steps'] as $step)
            <li class="flex gap-3" @if ($step['state'] === 'current') aria-current="step" @endif>
                <div class="flex flex-col items-center">
                    <span @class([
                        'flex size-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold',
                        'bg-(--portal-ink) text-(--portal-accent)' => $step['state'] === 'done',
                        'bg-(--portal-ink)/15 ring-2 ring-(--portal-ink)' => $step['state'] === 'current',
                        'border-[1.5px] border-dashed border-(--portal-ink)/50' => $step['state'] === 'next',
                    ])>
                        @if ($step['state'] === 'done')
                            <x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />
                        @else
                            {{ $loop->iteration }}
                        @endif
                    </span>
                    @unless ($loop->last)
                        <span class="my-1 w-0.5 flex-1 rounded bg-(--portal-ink)/25" aria-hidden="true"></span>
                    @endunless
                </div>
                <div class="flex flex-col pb-4">
                    <span @class(['font-semibold', 'opacity-70' => $step['state'] === 'next'])>{{ $step['label'] }}</span>
                    <span class="text-sm opacity-80">{{ $stateText[$step['state']] }}</span>
                </div>
            </li>
        @endforeach
    </ol>
</section>
