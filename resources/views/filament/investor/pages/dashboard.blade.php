@php
    use App\Support\MoneyFormatter;
@endphp

<x-filament-panels::page>
    @php $account = $this->account(); @endphp
    @php $currency = $this->currency(); @endphp

    @if ($account->isApplicant())
        <x-filament::section>
            <div class="flex flex-col items-center gap-3 py-8 text-center">
                @if ($account->status === \App\Models\InvestorPortalAccount::STATUS_REJECTED)
                    <x-filament::icon icon="heroicon-o-x-circle" class="h-10 w-10 text-danger-500" />
                    <h2 class="text-lg font-semibold">{{ __('Your application was not approved') }}</h2>
                    @if ($account->rejection_reason)
                        <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $account->rejection_reason }}</p>
                    @endif
                    <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">{{ __('Please contact the company office if you have questions.') }}</p>
                @else
                    <x-filament::icon icon="heroicon-o-clock" class="h-10 w-10 text-warning-500" />
                    <h2 class="text-lg font-semibold">{{ __('Your application is being reviewed') }}</h2>
                    <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">
                        {{ __('The company office checks your details and documents. Your investment, the deposit and the signed deed are then completed at the office; after that this portal shows your investments.') }}
                    </p>
                @endif
            </div>
        </x-filament::section>
    @endif

    @php $outstanding = app(\App\Services\AgreementService::class)->outstanding($account); @endphp
    @if ($outstanding->isNotEmpty())
        <x-filament::section icon="heroicon-o-document-check" icon-color="warning">
            <p class="text-sm">{{ __('Please read and accept: :titles', ['titles' => $outstanding->pluck('title')->implode(', ')]) }}</p>
            <div class="mt-2">
                <x-filament::link :href="\App\Filament\Investor\Pages\Agreements::getUrl()">{{ __('Open agreements') }}</x-filament::link>
            </div>
        </x-filament::section>
    @endif

    @if ($account->isInvestor())
        @php
            $summary = $this->portal()->investorSummary($account);
            $board = $this->board();
            $shares = $this->capitalShares();
            $next = $this->nextPayout();
            $calendar = $this->projectCalendar();
            $tile = 'min-w-0 rounded-3xl bg-white p-6 shadow-(--portal-tile-shadow) sm:p-7 dark:bg-gray-900 dark:ring-1 dark:ring-white/10';
        @endphp

        <div class="flex flex-col gap-5" data-investor-board>
            {{-- Row 1: the money at a glance, and how it splits over the groups. --}}
            <div class="flex flex-wrap gap-5">
                <section aria-labelledby="investor-capital" class="flex min-w-0 flex-[2_1_34rem] flex-col justify-between gap-7 rounded-3xl bg-(--portal-ink) p-6 text-white shadow-(--portal-tile-shadow) sm:p-7 dark:ring-1 dark:ring-white/10">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex flex-col gap-1">
                            <h2 id="investor-capital" class="text-base font-medium text-gray-300">{{ __('Capital at work') }}</h2>
                            <p class="text-4xl font-bold tracking-tight tabular-nums sm:text-5xl">{{ MoneyFormatter::currency($summary['capital_at_work'], $currency) }}</p>
                            @if ($board['groups']->isNotEmpty())
                                <p class="text-sm text-gray-300">{{ __('Across :count groups', ['count' => $board['groups']->count()]) }}</p>
                            @endif
                        </div>
                        <div class="flex flex-col gap-0.5 rounded-2xl bg-(--portal-accent) px-4 py-3 text-(--portal-ink)">
                            <span class="text-sm font-medium">{{ __('Profit received') }}</span>
                            <span class="text-xl font-bold tabular-nums">{{ MoneyFormatter::currency($summary['profit_received'], $currency) }}</span>
                        </div>
                    </div>
                    <dl class="flex flex-wrap gap-3">
                        <div class="flex min-w-0 flex-[1_1_13rem] flex-col gap-0.5 rounded-2xl bg-(--portal-ink-2) px-5 py-4">
                            <dt class="text-sm text-gray-300">{{ __('Profit confirmed, not yet paid') }}</dt>
                            <dd class="text-xl font-bold tabular-nums text-[#ffc86b]">{{ MoneyFormatter::currency($summary['profit_pending'], $currency) }}</dd>
                        </div>
                        <div class="flex min-w-0 flex-[1_1_13rem] flex-col gap-0.5 rounded-2xl bg-(--portal-ink-2) px-5 py-4">
                            <dt class="text-sm text-gray-300">{{ __('Total paid to you') }}</dt>
                            <dd class="text-xl font-bold tabular-nums">{{ MoneyFormatter::currency($summary['paid_out'], $currency) }}</dd>
                        </div>
                    </dl>
                </section>

                @if ($shares !== [])
                    @php
                        $tones = ['stroke-[#14181f] dark:stroke-[#d2d7df]', 'stroke-[#9bd35a]', 'stroke-[#c98a1b]', 'stroke-[#5b7bd5]', 'stroke-[#8c95a5]'];
                        $swatches = ['bg-[#14181f] dark:bg-[#d2d7df]', 'bg-[#9bd35a]', 'bg-[#c98a1b]', 'bg-[#5b7bd5]', 'bg-[#8c95a5]'];
                    @endphp
                    <section aria-labelledby="investor-shares" class="{{ $tile }} flex flex-[1_1_18rem] flex-col gap-5">
                        <h2 id="investor-shares" class="text-lg font-bold">{{ __('Capital by group') }}</h2>
                        <div class="flex flex-wrap items-center gap-6">
                            <svg viewBox="0 0 150 150" class="size-36 shrink-0 -rotate-90" role="img" aria-label="{{ collect($shares)->map(fn ($s) => $s['code'].' '.$s['percent'].'%')->implode(', ') }}">
                                <circle cx="75" cy="75" r="60" fill="none" stroke-width="20" class="stroke-gray-100 dark:stroke-white/10" />
                                @foreach ($shares as $share)
                                    <circle cx="75" cy="75" r="60" fill="none" stroke-width="20" class="{{ $tones[$share['tone']] }}" stroke-dasharray="{{ $share['dash'] }} 377" stroke-dashoffset="{{ $share['offset'] }}" />
                                @endforeach
                            </svg>
                            <ul class="flex min-w-0 flex-[1_1_10rem] flex-col gap-4">
                                @foreach ($shares as $share)
                                    <li class="flex items-start gap-3">
                                        <span class="mt-1.5 size-3 shrink-0 rounded {{ $swatches[$share['tone']] }}" aria-hidden="true"></span>
                                        <span class="flex min-w-0 flex-col">
                                            <span class="font-semibold">{{ $share['code'] }} · {{ $share['name'] }}</span>
                                            <span class="text-sm tabular-nums text-gray-600 dark:text-gray-400">{{ MoneyFormatter::currency($share['amount'], $currency) }} · {{ $share['percent'] }}%</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </section>
                @endif
            </div>

            {{-- Row 2: a tile per project, under its group. --}}
            <section aria-labelledby="investor-projects" class="flex flex-col gap-4">
                <h2 id="investor-projects" class="text-lg font-bold">{{ __('My investments') }}</h2>

                @if ($board['groups']->isEmpty() && $board['legacy']->isEmpty())
                    <div class="{{ $tile }}">
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No investments yet.') }}</p>
                    </div>
                @else
                    @foreach ($board['groups'] as $section)
                        <div class="flex flex-col gap-3" data-investor-group="{{ $section['group']->group_code }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-1">
                                <div class="font-semibold">{{ $section['group']->group_code }} — {{ $section['group']->name }}</div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">
                                    @if ($section['leaving'])
                                        {{ __('You are leaving this group') }}
                                    @else
                                        {{ __('Your money in this group: :amount', ['amount' => MoneyFormatter::currency($section['amount'], $currency)]) }}
                                    @endif
                                    @if ($section['since'])
                                        · {{ __('since :date', ['date' => $section['since']->format('d M Y')]) }}
                                    @endif
                                </div>
                            </div>

                            @if ($section['group']->isOpen())
                                <div class="{{ $tile }}">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('This group has not started yet. Its first project begins when the company starts the group.') }}</p>
                                </div>
                            @endif

                            @if ($section['cards']->isEmpty() && ! $section['group']->isOpen())
                                <div class="{{ $tile }}">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing is waiting for you in this group. Finished projects are on the Payouts page.') }}</p>
                                </div>
                            @elseif ($section['cards']->isNotEmpty())
                                <div class="grid gap-5 [grid-template-columns:repeat(auto-fill,minmax(min(18rem,100%),1fr))]">
                                    @foreach ($section['cards'] as $card)
                                        @include('filament.investor.pages.partials.project-card', ['card' => $card, 'currency' => $currency])
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if ($board['legacy']->isNotEmpty())
                        <div class="grid gap-5 [grid-template-columns:repeat(auto-fill,minmax(min(18rem,100%),1fr))]">
                            @foreach ($board['legacy'] as $card)
                                @include('filament.investor.pages.partials.project-card', ['card' => $card, 'currency' => $currency])
                            @endforeach
                        </div>
                    @endif
                @endif
            </section>

            {{-- Row 3: this year's projects, and the payout to follow. --}}
            @if ($calendar->isNotEmpty() || $next)
                <div class="flex flex-wrap gap-5">
                    @if ($calendar->isNotEmpty())
                        @include('filament.investor.pages.partials.project-calendar', ['calendar' => $calendar, 'tile' => $tile])
                    @endif

                    @if ($next)
                        @include('filament.investor.pages.partials.next-payout', ['next' => $next, 'currency' => $currency])
                    @endif
                </div>
            @endif

            {{-- Row 4: profit by month, and the latest statement lines. --}}
            <div class="flex flex-wrap gap-5">
                <div class="min-w-0 flex-[2_1_34rem]">
                    @livewire(\App\Filament\Investor\Widgets\ProfitTrend::class)
                </div>

                <div class="min-w-0 flex-[1_1_20rem]">
                    <x-filament::section :heading="__('Latest statement entries')">
                        @include('filament.investor.pages.partials.statement-rows', ['entries' => $this->recentStatement(), 'currency' => $currency])
                        <div class="mt-3">
                            <x-filament::link :href="\App\Filament\Investor\Pages\Statement::getUrl()">{{ __('See the full statement') }}</x-filament::link>
                        </div>
                    </x-filament::section>
                </div>
            </div>
        </div>
    @endif

    @if ($account->isChannelPartner())
        @php $partner = $this->portal()->partnerSummary($account); @endphp

        <x-filament::section :heading="__('Channel partner')">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Investors you brought') }}</div>
                    <div class="text-xl font-semibold">{{ $partner['investors'] }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Their capital at work') }}</div>
                    <div class="text-xl font-semibold">{{ MoneyFormatter::currency($partner['referred_capital'], $currency) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Commission paid') }}</div>
                    <div class="text-xl font-semibold">{{ MoneyFormatter::currency($partner['paid'], $currency) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Commission due') }}</div>
                    <div class="text-xl font-semibold">{{ MoneyFormatter::currency($partner['due'], $currency) }}</div>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
