@php
    use App\Support\ImsNumbers;
    use App\Support\MoneyFormatter;
@endphp

{{--
    The portal home (IMS-V2/DESIGN.md §8.1, the owner's reference images): on a
    phone the green balance card first, then the round quick buttons, the
    profit figures, the projects and the latest money lines; on wide screens
    the same cards in a grid. The heading ("Good morning", "Welcome back,
    name") comes from Dashboard::getHeader().
--}}
<x-filament-panels::page>
    @php $account = $this->account(); @endphp
    @php $currency = $this->currency(); @endphp

    @if ($account->isApplicant())
        <section class="ims-card">
            <div class="ims-empty">
                @if ($account->status === \App\Models\InvestorPortalAccount::STATUS_REJECTED)
                    <x-filament::icon icon="heroicon-o-x-circle" class="h-10 w-10 text-(--ims-danger)" />
                    <h2 class="ims-card-title">{{ __('Your application was not approved') }}</h2>
                    @if ($account->rejection_reason)
                        <p class="max-w-md">{{ $account->rejection_reason }}</p>
                    @endif
                    <p class="max-w-md">{{ __('Please contact the company office if you have questions.') }}</p>
                @else
                    <x-filament::icon icon="heroicon-o-clock" class="h-10 w-10 text-(--ims-warning)" />
                    <h2 class="ims-card-title">{{ __('Your application is being reviewed') }}</h2>
                    <p class="max-w-md">
                        {{ __('The company office checks your details and documents. Your investment, the deposit and the signed deed are then completed at the office; after that this portal shows your investments.') }}
                    </p>
                @endif
            </div>
        </section>
    @endif

    {{-- IMS v2 (PRD FR-14, FR-70): KYC not verified yet. --}}
    @if ($account->isInvestor() && $this->company()?->imsIsLive() && ! $account->investor->isKycVerified())
        <section class="ims-banner ims-banner-warning" data-ims-kyc-banner>
            <span class="ims-card-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-identification" class="h-5 w-5" /></span>
            <p class="ims-banner-text">
                @if ($account->investor->kyc_status === \App\Models\Investor::KYC_PENDING)
                    {{ __('The office is checking your KYC.') }}
                @elseif ($account->investor->kyc_status === \App\Models\Investor::KYC_REJECTED)
                    {{ __('Your KYC was not accepted. Please send it again.') }}
                @else
                    {{ __('Please complete your KYC. New money can be put in only after it is verified.') }}
                @endif
            </p>
            @if ($account->investor->kyc_status !== \App\Models\Investor::KYC_PENDING)
                <a href="{{ \App\Filament\Ims\Portal\Pages\Kyc::getUrl() }}" class="ims-btn ims-btn-primary">{{ __('Do KYC now') }}</a>
            @endif
        </section>
    @endif

    @php $outstanding = app(\App\Services\AgreementService::class)->outstanding($account); @endphp
    @if ($outstanding->isNotEmpty())
        <section class="ims-banner ims-banner-warning">
            <span class="ims-card-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-document-check" class="h-5 w-5" /></span>
            <p class="ims-banner-text">{{ __('Please read and accept: :titles', ['titles' => $outstanding->pluck('title')->implode(', ')]) }}</p>
            <a href="{{ \App\Filament\Investor\Pages\Agreements::getUrl() }}" class="ims-btn ims-btn-primary">{{ __('Open agreements') }}</a>
        </section>
    @endif

    @if ($account->isInvestor())
        @php
            $summary = $this->portal()->investorSummary($account);
            $board = $this->board();
            $shares = $this->capitalShares();
            $next = $this->nextPayout();
            $calendar = $this->projectCalendar();
            $quick = $this->quickActions();
            $tile = 'ims-card min-w-0 flex-[1_1_18rem]';
        @endphp

        <div class="ims-home-grid ims-portal-grid" data-investor-board>
            {{-- The green balance card and the quick buttons. --}}
            <div class="ims-area-balance ims-stack">
                <section aria-labelledby="investor-capital" class="ims-balance-card">
                    <div class="ims-card-head">
                        <div class="min-w-0 flex-1">
                            <h2 id="investor-capital" class="ims-card-title">{{ __('Capital at work') }}</h2>
                            @if ($board['groups']->isNotEmpty())
                                <p class="ims-label">{{ __('Across :count groups', ['count' => ImsNumbers::count($board['groups']->count())]) }}</p>
                            @endif
                        </div>
                        @if (\App\Filament\Investor\Pages\MyInvestments::canAccess())
                            <a href="{{ \App\Filament\Investor\Pages\MyInvestments::getUrl() }}" class="ims-arrow-btn ims-arrow-btn-on-green" aria-label="{{ __('My investments') }}">
                                <x-filament::icon icon="heroicon-o-arrow-up-right" class="h-5 w-5" />
                            </a>
                        @endif
                    </div>

                    <div class="ims-balance-figure"><x-ims.money :amount="$summary['capital_at_work']" :currency="$currency" /></div>

                    <div class="ims-balance-foot">
                        <span>{{ __('Profit received') }}</span>
                        <span class="ims-pill ims-pill-on-green"><x-ims.money :amount="$summary['profit_received']" :currency="$currency" :signed="true" /></span>
                    </div>

                    <dl class="ims-balance-split">
                        <div>
                            <dt>{{ __('Profit confirmed, not yet paid') }}</dt>
                            <dd><x-ims.money :amount="$summary['profit_pending']" :currency="$currency" /></dd>
                        </div>
                        <div>
                            <dt>{{ __('Total paid to you') }}</dt>
                            <dd><x-ims.money :amount="$summary['paid_out']" :currency="$currency" /></dd>
                        </div>
                    </dl>
                </section>

                @if ($quick !== [])
                    <nav class="ims-card ims-quick-actions" aria-label="{{ __('Quick actions') }}">
                        @foreach ($quick as $action)
                            <a href="{{ $action['url'] }}">
                                <span class="ims-quick-icon" aria-hidden="true"><x-filament::icon :icon="$action['icon']" class="h-6 w-6" /></span>
                                <span>{{ $action['label'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                @endif
            </div>

            {{-- Profit by month. --}}
            <div class="ims-area-chart">
                <x-ims.bar-chart
                    :bars="$this->profitBars()"
                    :currency="$currency"
                    :heading="__('Profit (last 12 months)')"
                    :link="\App\Filament\Investor\Pages\Payouts::canAccess() ? \App\Filament\Investor\Pages\Payouts::getUrl() : null"
                    :link-label="__('Payouts')"
                    :empty-text="__('No profit yet. The bars fill in once your first project is settled.')"
                    class="h-full"
                />
            </div>

            {{-- The money by group, and the payout to follow. --}}
            <div class="ims-area-side ims-stack">
                @if ($next)
                    @include('filament.investor.pages.partials.next-payout', ['next' => $next, 'currency' => $currency])
                @endif

                @if ($shares !== [])
                    @php
                        $tones = ['stroke-[#0e6b3e]', 'stroke-[#8fc7a7]', 'stroke-[#d97706]', 'stroke-[#5b7bd5]', 'stroke-[#9aa3ae]'];
                        $swatches = ['bg-[#0e6b3e]', 'bg-[#8fc7a7]', 'bg-[#d97706]', 'bg-[#5b7bd5]', 'bg-[#9aa3ae]'];
                    @endphp
                    <section aria-labelledby="investor-shares" class="ims-card ims-stack">
                        <div class="ims-card-head">
                            <span class="ims-card-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-chart-pie" class="h-5 w-5" /></span>
                            <h2 id="investor-shares" class="ims-card-title">{{ __('Capital by group') }}</h2>
                        </div>
                        <div class="flex flex-wrap items-center gap-5">
                            <svg viewBox="0 0 150 150" class="size-32 shrink-0 -rotate-90" role="img" aria-label="{{ collect($shares)->map(fn ($s) => $s['code'].' '.$s['percent'].'%')->implode(', ') }}">
                                <circle cx="75" cy="75" r="60" fill="none" stroke-width="20" class="stroke-[#eef0f2] dark:stroke-white/10" />
                                @foreach ($shares as $share)
                                    <circle cx="75" cy="75" r="60" fill="none" stroke-width="20" stroke-linecap="round" class="{{ $tones[$share['tone']] }}" stroke-dasharray="{{ $share['dash'] }} 377" stroke-dashoffset="{{ $share['offset'] }}" />
                                @endforeach
                            </svg>
                            <ul class="flex min-w-0 flex-[1_1_9rem] flex-col gap-3">
                                @foreach ($shares as $share)
                                    <li class="flex items-start gap-3">
                                        <span class="mt-1.5 size-3 shrink-0 rounded-full {{ $swatches[$share['tone']] }}" aria-hidden="true"></span>
                                        <span class="flex min-w-0 flex-col">
                                            <span class="font-semibold">{{ $share['code'] }} · {{ $share['name'] }}</span>
                                            <span class="ims-kpi-note"><x-ims.money :amount="$share['amount']" :currency="$currency" /> · {{ ImsNumbers::percent($share['percent']) }}</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </section>
                @endif
            </div>

            {{-- A tile per project, under its group. --}}
            <section aria-labelledby="investor-projects" class="ims-area-projects ims-stack">
                <div class="ims-section-head">
                    <h2 id="investor-projects" class="ims-section-title">{{ __('My investments') }}</h2>
                    @if (\App\Filament\Investor\Pages\MyInvestments::canAccess())
                        <a href="{{ \App\Filament\Investor\Pages\MyInvestments::getUrl() }}" class="ims-see-all">{{ __('See all') }}</a>
                    @endif
                </div>

                @if ($board['groups']->isEmpty() && $board['legacy']->isEmpty())
                    <div class="ims-card">
                        <div class="ims-empty">
                            <x-filament::icon icon="heroicon-o-briefcase" class="h-10 w-10" />
                            <p>{{ __('No investments yet.') }}</p>
                            @if (\App\Filament\Investor\Pages\Requests::canAccess())
                                <a href="{{ \App\Filament\Investor\Pages\Requests::getUrl() }}" class="ims-btn ims-btn-primary">{{ __('Add money') }}</a>
                            @endif
                        </div>
                    </div>
                @else
                    @foreach ($board['groups'] as $section)
                        <div class="flex flex-col gap-3" data-investor-group="{{ $section['group']->group_code }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-1">
                                <div class="font-semibold">{{ $section['group']->group_code }} — {{ $section['group']->name }}</div>
                                <div class="ims-kpi-note">
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
                                <div class="ims-card">
                                    <p class="ims-kpi-note">{{ __('This group has not started yet. Its first project begins when the company starts the group.') }}</p>
                                </div>
                            @endif

                            @if ($section['cards']->isEmpty() && ! $section['group']->isOpen())
                                <div class="ims-card">
                                    <p class="ims-kpi-note">{{ __('Nothing is waiting for you in this group. Finished projects are on the Payouts page.') }}</p>
                                </div>
                            @elseif ($section['cards']->isNotEmpty())
                                <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(18rem,100%),1fr))]">
                                    @foreach ($section['cards'] as $card)
                                        @include('filament.investor.pages.partials.project-card', ['card' => $card, 'currency' => $currency])
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if ($board['legacy']->isNotEmpty())
                        <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(18rem,100%),1fr))]">
                            @foreach ($board['legacy'] as $card)
                                @include('filament.investor.pages.partials.project-card', ['card' => $card, 'currency' => $currency])
                            @endforeach
                        </div>
                    @endif
                @endif
            </section>

            {{-- The latest money lines. --}}
            <section class="ims-area-latest ims-card" aria-labelledby="investor-latest">
                <div class="ims-section-head">
                    <h2 id="investor-latest" class="ims-card-title">{{ __('Recent transactions') }}</h2>
                    @if (\App\Filament\Investor\Pages\Statement::canAccess())
                        <a href="{{ \App\Filament\Investor\Pages\Statement::getUrl() }}" class="ims-see-all">{{ __('See all') }}</a>
                    @endif
                </div>
                @include('filament.investor.pages.partials.statement-rows', ['entries' => $this->recentStatement(), 'currency' => $currency])
            </section>

            {{-- This year's projects. --}}
            @if ($calendar->isNotEmpty())
                <div class="ims-area-calendar flex">
                    @include('filament.investor.pages.partials.project-calendar', ['calendar' => $calendar, 'tile' => $tile])
                </div>
            @endif
        </div>
    @endif

    @if ($account->isChannelPartner())
        @php $partner = $this->portal()->partnerSummary($account); @endphp

        <section class="ims-card ims-stack" aria-labelledby="partner-summary">
            <div class="ims-card-head">
                <span class="ims-card-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-user-group" class="h-5 w-5" /></span>
                <h2 id="partner-summary" class="ims-card-title">{{ __('Channel partner') }}</h2>
            </div>
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="ims-subcard">
                    <div class="ims-kpi-note">{{ __('Investors you brought') }}</div>
                    <div class="ims-kpi-value">{{ ImsNumbers::count($partner['investors']) }}</div>
                </div>
                <div class="ims-subcard">
                    <div class="ims-kpi-note">{{ __('Their capital at work') }}</div>
                    <x-ims.money class="ims-kpi-value" :amount="$partner['referred_capital']" :currency="$currency" />
                </div>
                <div class="ims-subcard">
                    <div class="ims-kpi-note">{{ __('Commission paid') }}</div>
                    <x-ims.money class="ims-kpi-value" :amount="$partner['paid']" :currency="$currency" />
                </div>
                <div class="ims-subcard">
                    <div class="ims-kpi-note">{{ __('Commission due') }}</div>
                    <x-ims.money class="ims-kpi-value" :amount="$partner['due']" :currency="$currency" />
                </div>
            </div>
        </section>
    @endif
</x-filament-panels::page>
