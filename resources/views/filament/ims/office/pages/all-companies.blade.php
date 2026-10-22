{{--
    "All companies" (18_IMS_ALL_COMPANIES_PLAN.md §3.3, IMS-V2/DESIGN.md): big
    cards, few words, one column on phones. Every line names its company and
    opens that company's own office page.
--}}
<x-filament-panels::page>
    @php
        $rows = $this->rows();
        $totals = $this->totals();
        $waiting = $this->waiting();
        $projects = $this->projects();
        $newInvestors = array_sum(array_column($rows, 'new_investors'));
    @endphp

    <nav class="ims-all-nav" aria-label="{{ __('All companies') }}">
        <a href="#ims-all-waiting">{{ __('Waiting') }}@if ($waiting !== []) <span class="ims-all-nav-count">{{ \App\Support\ImsNumbers::count(count($waiting)) }}</span>@endif</a>
        <a href="#ims-all-companies">{{ __('Companies') }}</a>
        <a href="#ims-all-projects">{{ __('Projects') }}</a>
        <a href="#ims-all-investors">{{ __('New investors') }}</a>
    </nav>

    <div class="ims-all-grid">
        {{-- 1. The money, per currency. --}}
        @forelse ($totals as $currency => $sum)
            <section class="ims-balance-card" aria-labelledby="ims-all-money-{{ $currency }}">
                <div class="ims-card-head">
                    <div>
                        <h2 id="ims-all-money-{{ $currency }}" class="ims-card-title">{{ __('Total money invested') }}</h2>
                        <p class="ims-label">{{ __('All companies, money confirmed so far') }}</p>
                    </div>
                </div>
                <div class="ims-balance-figure">
                    <x-ims.money :amount="$sum['invested']" :currency="$currency" />
                </div>
                <div class="ims-balance-foot">
                    <span>{{ __('Capital at work now') }}: {{ \App\Support\ImsNumbers::money($sum['capital_at_work'], $currency) }}</span>
                    <x-filament::icon icon="heroicon-o-banknotes" class="h-6 w-6" />
                </div>
            </section>

            {{-- 3. Profit to pay. --}}
            <section class="ims-card ims-kpi" aria-labelledby="ims-all-due-{{ $currency }}">
                <h2 id="ims-all-due-{{ $currency }}" class="ims-kpi-label">{{ __('Profit to pay') }}</h2>
                <x-ims.money class="ims-kpi-value" :amount="$sum['payouts_due']" :currency="$currency" />
                <p class="ims-kpi-note">{{ trans_choice(':count payout confirmed, not sent yet|:count payouts confirmed, not sent yet', $sum['payouts_due_count'], ['count' => \App\Support\ImsNumbers::count($sum['payouts_due_count'])]) }}</p>
                <p class="ims-kpi-note">{{ __('Came in this month') }}: {{ \App\Support\ImsNumbers::money($sum['received_this_month'], $currency) }}</p>
            </section>
        @empty
            <section class="ims-card">
                <div class="ims-empty"><p>{{ __('No company can be shown.') }}</p></div>
            </section>
        @endforelse

        {{-- 2. Waiting for a decision. --}}
        <section id="ims-all-waiting" class="ims-card ims-all-wide" aria-labelledby="ims-all-waiting-heading">
            <div class="ims-card-head">
                <span class="ims-card-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-clipboard-document-check" class="h-5 w-5" /></span>
                <div class="min-w-0 flex-1">
                    <h2 id="ims-all-waiting-heading" class="ims-card-title">{{ __('Waiting for a decision') }}</h2>
                    <p class="ims-kpi-note">{{ __('From every company. Tap to open it.') }}</p>
                </div>
            </div>

            @if ($waiting === [])
                <div class="ims-empty">
                    <x-filament::icon icon="heroicon-o-check-badge" class="h-10 w-10 text-(--ims-success)" />
                    <p>{{ __('Nothing is waiting for you today.') }}</p>
                </div>
            @else
                <ul class="ims-todo" data-ims-all-waiting>
                    @foreach ($waiting as $item)
                        <li class="ims-todo-item">
                            <a @if ($item['url']) href="{{ $item['url'] }}" @endif class="ims-todo-link">
                                <span @class(['ims-count', 'ims-count-'.$item['tone']])>{{ \App\Support\ImsNumbers::count($item['count']) }}</span>
                                <span class="ims-todo-label">
                                    {{ $item['label'] }}
                                    <span class="ims-all-company">{{ $this->companyName($item['company']) }}</span>
                                </span>
                                @if ($item['url'])
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="ims-todo-chevron" />
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- 4. One card per company. --}}
        <section id="ims-all-companies" class="ims-card ims-all-wide" aria-labelledby="ims-all-companies-heading">
            <div class="ims-card-head">
                <span class="ims-card-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-building-office-2" class="h-5 w-5" /></span>
                <div class="min-w-0 flex-1">
                    <h2 id="ims-all-companies-heading" class="ims-card-title">{{ __('Companies') }}</h2>
                    <p class="ims-kpi-note">{{ __('Tap a company to open it.') }}</p>
                </div>
            </div>

            <div class="ims-group-grid">
                @foreach ($rows as $row)
                    <a href="{{ $this->companyHome($row['company']) }}" class="ims-group-tile" data-ims-all-company="{{ $row['company']->getKey() }}">
                        <div class="font-semibold">{{ $this->companyName($row['company']) }}</div>
                        <x-ims.money class="ims-kpi-value" :amount="$row['invested']" :currency="$row['currency']" />
                        <div class="ims-kpi-note">{{ __('Money in') }}</div>
                        <div class="ims-all-facts">
                            <span>{{ trans_choice(':count project running|:count projects running', count($row['projects']), ['count' => \App\Support\ImsNumbers::count(count($row['projects']))]) }}</span>
                            <span>{{ __('Profit paid this month') }}: {{ \App\Support\ImsNumbers::money($row['profit_paid_this_month'], $row['currency']) }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- 5. Every running project. --}}
        <section id="ims-all-projects" class="ims-card ims-all-wide" aria-labelledby="ims-all-projects-heading">
            <div class="ims-card-head">
                <span class="ims-card-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-briefcase" class="h-5 w-5" /></span>
                <div class="min-w-0 flex-1">
                    <h2 id="ims-all-projects-heading" class="ims-card-title">{{ __('Projects') }}</h2>
                    <p class="ims-kpi-note">{{ __('Open and running projects of every company') }}</p>
                </div>
            </div>

            @if ($projects === [])
                <div class="ims-empty"><p>{{ __('No project is open or running') }}</p></div>
            @else
                <div class="ims-group-grid">
                    @foreach ($projects as $entry)
                        <a @if ($entry['url']) href="{{ $entry['url'] }}" @endif class="ims-group-tile">
                            <div class="min-w-0">
                                <div class="ims-muted text-sm">{{ $entry['project']->project_code }}</div>
                                <div class="font-semibold">{{ $entry['project']->name }}</div>
                                <div class="ims-all-company">{{ $this->companyName($entry['company']) }}</div>
                            </div>
                            <x-ims.money class="ims-kpi-value" :amount="$entry['invested']" :currency="$entry['currency']" />
                            <div class="ims-kpi-note">{{ __(\App\Models\InvestmentProject::STATUSES[$entry['project']->status] ?? (string) $entry['project']->status) }}</div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- 6. New investors this month. --}}
        <section id="ims-all-investors" class="ims-card ims-all-wide" aria-labelledby="ims-all-investors-heading">
            <div class="ims-card-head">
                <span class="ims-card-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-user-plus" class="h-5 w-5" /></span>
                <div class="min-w-0 flex-1">
                    <h2 id="ims-all-investors-heading" class="ims-card-title">{{ __('New investors this month') }}</h2>
                    <p class="ims-kpi-note">{{ trans_choice(':count new investor in all companies|:count new investors in all companies', $newInvestors, ['count' => \App\Support\ImsNumbers::count($newInvestors)]) }}</p>
                </div>
            </div>

            <ul class="ims-todo">
                @foreach ($rows as $row)
                    @php($canOpenInvestors = \App\Filament\Resources\Investors\InvestorResource::canAccess())
                    <li class="ims-todo-item">
                        <a @if ($canOpenInvestors) href="{{ $this->open(\App\Filament\Resources\Investors\InvestorResource::getUrl(panel: 'office'), $row['company']) }}" @endif class="ims-todo-link">
                            <span class="ims-count ims-count-info">{{ \App\Support\ImsNumbers::count($row['new_investors']) }}</span>
                            <span class="ims-todo-label">{{ $this->companyName($row['company']) }}</span>
                            @if ($canOpenInvestors)
                                <x-filament::icon icon="heroicon-m-chevron-right" class="ims-todo-chevron" />
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</x-filament-panels::page>
