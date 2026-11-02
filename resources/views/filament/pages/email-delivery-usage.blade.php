{{--
    Settings → Email delivery: email usage for whichever mail service the site uses (owner request 2026-10-24,
    design "B", made provider-neutral 2026-11-02): the free monthly limit as a gauge, four numbers, the emails by
    kind and by month, sent against delivered by week and what became of the emails. Counted from the Email Log by
    EmailUsageService. The styles are the .zz-use-* rules in resources/css/filament/admin/theme.css.
--}}
@php
    $usage = $this->usage();
    $provider = $this->provider();
    $name = $provider->name;
    $trackingOn = $this->trackingOn();
    $totals = $usage['totals'];
    $free = $usage['free'];
    $cost = $usage['cost'];
    $weeks = $usage['weeks'];
    $isCurrent = $usage['month']['is_current'];
    $money = fn (?float $amount): string => \App\Services\Email\EmailUsageService::money($amount);
    // 93%, or "<1%" for a small share that is not nothing.
    $share = fn (int $part, int $of): string => $of <= 0 ? '—' : ($part > 0 && round($part / $of * 100) < 1 ? '<1%' : round($part / $of * 100).'%');
    $css = fn (float $number): string => number_format($number, 1, '.', '');
    $circumference = 552.92;
    $ringPercent = min(100, max(0, (float) ($free['percent'] ?? 0)));
    $ringDash = round($circumference * $ringPercent / 100, 1);
    $freeBadge = match ($free['state']) {
        'ok' => ['success', 'heroicon-m-check-circle', __('On track')],
        'near' => ['warning', 'heroicon-m-exclamation-triangle', __('Close to the limit')],
        'over' => ['danger', 'heroicon-m-x-circle', __('Over the free limit')],
        default => ['gray', 'heroicon-m-question-mark-circle', __('Not set')],
    };
    $attempts = $totals['attempts'];
    $stack = [
        [__('Delivered'), $totals['delivered'], 'var(--zz-good)', 'heroicon-m-check-circle', 'var(--zz-good)'],
        [__('Waiting for a report'), $totals['waiting'], 'var(--zz-gray)', 'heroicon-m-clock', 'var(--zz-gray)'],
        [__('Bounced or marked as spam'), $totals['problems'], 'var(--zz-serious)', 'heroicon-m-exclamation-triangle', 'var(--zz-serious)'],
        [__('Failed to send'), $totals['failed'], 'var(--zz-critical)', 'heroicon-m-x-circle', 'var(--zz-critical)'],
    ];
    $emailLogUrl = \App\Filament\Resources\EmailLogs\EmailLogResource::canViewAny() ? \App\Filament\Resources\EmailLogs\EmailLogResource::getUrl('index') : null;
@endphp

<div class="zz-use space-y-4" id="email-usage">
    <div class="zz-use-head">
        <div class="min-w-0">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Email usage') }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Counted from this company\'s Email Log: the emails sent through :provider. A mail service\'s own limits belong to its whole account, so every company that uses the same account shares them.', ['provider' => $name]) }}</p>
        </div>
        <div class="zz-use-seg" role="group" aria-label="{{ __('Month') }}">
            <button type="button" wire:click="showMonth(0)" aria-pressed="{{ $isCurrent ? 'true' : 'false' }}" @class(['is-on' => $isCurrent])>{{ __('This month') }}</button>
            <button type="button" wire:click="showMonth(1)" aria-pressed="{{ $isCurrent ? 'false' : 'true' }}" @class(['is-on' => ! $isCurrent])>{{ __('Last month') }}</button>
        </div>
    </div>

    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $usage['month']['label'] }}</p>

    @if (! $trackingOn && $totals['sent'] > 0)
        <x-filament::section>
            @if ($provider->isAmazonSes())
                <p class="text-sm">{{ __('Delivery tracking is off, so "Delivered", "Bounced" and "Marked as spam" cannot be counted. Turn it on in Settings → Amazon SES.') }}</p>
            @else
                <p class="text-sm">{{ __(':provider does not report back what became of an email, so every email it accepted stays "Sent"; "Delivered", "Bounced" and "Marked as spam" are not counted.', ['provider' => $name]) }}</p>
            @endif
        </x-filament::section>
    @endif

    {{-- The free monthly limit and the four numbers --}}
    <div class="zz-use-hero">
        <x-filament::section :heading="__('Free monthly limit')">
            <x-slot name="afterHeader">
                <x-filament::badge :color="$freeBadge[0]" :icon="$freeBadge[1]">{{ $freeBadge[2] }}</x-filament::badge>
            </x-slot>

            @if ($free['limit'] === null)
                <p class="text-sm">{{ __('Type how many emails :provider lets you send free each month (in Cost settings, below) to see how many are left.', ['provider' => $name]) }}</p>
                <p class="zz-use-bignum mt-3">{{ number_format($totals['sent']) }} <span>{{ __('sent in :month', ['month' => $usage['month']['label']]) }}</span></p>
                <a href="#email-cost-settings" class="zz-use-link text-primary-600 dark:text-primary-400">{{ __('Open Cost settings') }}</a>
            @else
                <div class="zz-use-gauge">
                    <div class="zz-use-ring zz-use-ring--{{ $free['state'] }}" role="img" aria-label="{{ __(':used of :limit free emails used (:percent). :left left.', ['used' => number_format($totals['sent']), 'limit' => number_format($free['limit']), 'percent' => number_format((float) $free['percent'], 1).'%', 'left' => number_format($free['left'])]) }}">
                        <svg viewBox="0 0 220 220" aria-hidden="true">
                            <circle class="zz-use-ring-track" cx="110" cy="110" r="88"></circle>
                            <circle class="zz-use-ring-fill" cx="110" cy="110" r="88" stroke-dasharray="{{ $ringDash }} {{ round($circumference - $ringDash, 1) }}" transform="rotate(-90 110 110)"></circle>
                        </svg>
                        <div class="zz-use-ring-mid">
                            <b>{{ number_format($free['state'] === 'over' ? $free['over_by'] : $free['left']) }}</b>
                            <span>{{ $free['state'] === 'over' ? __('emails over') : __('emails left') }}</span>
                            <span>{{ __('of :count free', ['count' => number_format($free['limit'])]) }}</span>
                        </div>
                    </div>
                    <dl class="zz-use-facts">
                        <div>
                            <dt>{{ __('Used so far') }}</dt>
                            <dd>{{ number_format($totals['sent']) }} · {{ number_format((float) $free['percent'], 1) }}%</dd>
                        </div>
                        @if ($isCurrent && $free['resets_on'])
                            <div>
                                <dt>{{ __('Starts again') }}</dt>
                                <dd>{{ $free['resets_in_days'] <= 1 ? __(':date (tomorrow)', ['date' => $free['resets_on']]) : __(':date (in :count days)', ['date' => $free['resets_on'], 'count' => $free['resets_in_days']]) }}</dd>
                            </div>
                        @endif
                        @if ($free['projected'] !== null)
                            <div>
                                <dt>{{ __('At this pace') }}</dt>
                                <dd>{{ $free['projected_over'] ? __('about :count by month end, over the limit', ['count' => number_format($free['projected'])]) : __('about :count by month end, inside the limit', ['count' => number_format($free['projected'])]) }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif
        </x-filament::section>

        <div class="zz-use-tiles">
            <x-filament::section compact :heading="__('Sent')" icon="heroicon-o-paper-airplane">
                <p class="zz-use-bignum">{{ number_format($totals['sent']) }}</p>
                <p class="zz-use-note">{{ $isCurrent ? __(':count in the last 24 hours', ['count' => number_format($usage['last_24h'])]) : $usage['month']['label'] }}</p>
            </x-filament::section>
            <x-filament::section compact :heading="__('Delivered')" icon="heroicon-o-envelope">
                <p class="zz-use-bignum">{{ $trackingOn ? number_format($totals['delivered']) : '—' }}</p>
                <p class="zz-use-note">{{ $trackingOn ? __(':percent reached the recipient', ['percent' => $share($totals['delivered'], $totals['sent'])]) : ($provider->reportsBack ? __('Turn tracking on to see this.') : __('This service does not report it.')) }}</p>
            </x-filament::section>
            <x-filament::section compact :heading="__('Problems')" icon="heroicon-o-exclamation-triangle">
                <p class="zz-use-bignum">{{ number_format($totals['problems'] + $totals['failed']) }}</p>
                <p class="zz-use-note">{{ __(':count bounced or spam · :failed failed to send', ['count' => number_format($totals['problems']), 'failed' => number_format($totals['failed'])]) }}</p>
            </x-filament::section>
            <x-filament::section compact :heading="$isCurrent ? __('Cost so far') : __('Cost')" icon="heroicon-o-currency-dollar">
                <p class="zz-use-bignum">{{ $money($cost['amount']) }}</p>
                <p class="zz-use-note">
                    @if ($cost['price'] === null)
                        {{ __('Type the price in Cost settings, below.') }}
                    @elseif ($free['limit'] !== null && $cost['without_free'] > $cost['amount'])
                        {{ __(':amount without the free emails', ['amount' => $money($cost['without_free'])]) }}
                    @else
                        {{ __(':amount per 1,000 emails', ['amount' => $money($cost['price'])]) }}
                    @endif
                </p>
            </x-filament::section>
        </div>
    </div>

    {{-- By kind of email --}}
    <x-filament::section :heading="__('By kind of email')" :description="$usage['month']['label']">
        @if ($usage['kinds'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No email went through :provider in this month.', ['provider' => $name]) }}</p>
        @else
            <div class="zz-use-table" role="table" aria-label="{{ __('By kind of email') }}">
                <div class="zz-use-tr zz-use-th" role="row">
                    <span role="columnheader">{{ __('Kind') }}</span>
                    <span role="columnheader" class="zz-use-n">{{ __('Sent') }}</span>
                    <span role="columnheader" class="zz-use-n">{{ __('Delivered') }}</span>
                    <span role="columnheader" class="zz-use-n">{{ __('Waiting') }}</span>
                    <span role="columnheader" class="zz-use-n">{{ __('Bounced or spam') }}</span>
                    <span role="columnheader" class="zz-use-n">{{ __('Failed') }}</span>
                    <span role="columnheader">{{ __('Share of sent') }}</span>
                </div>
                @foreach ($usage['kinds'] as $kind)
                    <div class="zz-use-tr" role="row">
                        <span role="cell" class="zz-use-kind">{{ $kind['name'] }}</span>
                        <span role="cell" class="zz-use-sent"><span class="zz-use-desk">{{ number_format($kind['sent']) }}</span><span class="zz-use-phone">{{ __(':count sent', ['count' => number_format($kind['sent'])]) }}</span></span>
                        <span role="cell" class="zz-use-n" data-label="{{ __('Delivered') }}">{{ $trackingOn ? number_format($kind['delivered']) : '—' }}</span>
                        <span role="cell" class="zz-use-n" data-label="{{ __('Waiting') }}">{{ number_format($kind['waiting']) }}</span>
                        <span role="cell" class="zz-use-n" data-label="{{ __('Bounced or spam') }}">{{ $trackingOn ? number_format($kind['problems']) : '—' }}</span>
                        <span role="cell" class="zz-use-n" data-label="{{ __('Failed') }}">{{ number_format($kind['failed']) }}</span>
                        <span role="cell" class="zz-use-share"><span class="zz-use-track"><i style="width: {{ $css($kind['share']) }}%"></i></span><b>{{ $kind['share'] > 0 && $kind['share'] < 1 ? '<1%' : round($kind['share']).'%' }}</b></span>
                    </div>
                @endforeach
                <div class="zz-use-tr zz-use-total" role="row">
                    <span role="cell" class="zz-use-kind">{{ __('All emails') }}</span>
                    <span role="cell" class="zz-use-sent"><span class="zz-use-desk">{{ number_format($totals['sent']) }}</span><span class="zz-use-phone">{{ __(':count sent', ['count' => number_format($totals['sent'])]) }}</span></span>
                    <span role="cell" class="zz-use-n" data-label="{{ __('Delivered') }}">{{ $trackingOn ? number_format($totals['delivered']) : '—' }}</span>
                    <span role="cell" class="zz-use-n" data-label="{{ __('Waiting') }}">{{ number_format($totals['waiting']) }}</span>
                    <span role="cell" class="zz-use-n" data-label="{{ __('Bounced or spam') }}">{{ $trackingOn ? number_format($totals['problems']) : '—' }}</span>
                    <span role="cell" class="zz-use-n" data-label="{{ __('Failed') }}">{{ number_format($totals['failed']) }}</span>
                    <span role="cell" class="zz-use-share"></span>
                </div>
            </div>
        @endif
    </x-filament::section>

    {{-- Month by month --}}
    <x-filament::section :heading="__('Month by month')" :description="$free['limit'] === null ? __('The free limit is not set yet.') : __('Free limit: :count emails a month', ['count' => number_format($free['limit'])])">
        <div class="zz-use-table zz-use-months" role="table" aria-label="{{ __('Month by month') }}">
            <div class="zz-use-tr zz-use-th" role="row">
                <span role="columnheader">{{ __('Month') }}</span>
                <span role="columnheader" class="zz-use-n">{{ __('Sent') }}</span>
                <span role="columnheader" class="zz-use-n">{{ __('Failed') }}</span>
                <span role="columnheader">{{ __('Free limit used') }}</span>
                <span role="columnheader" class="zz-use-n">{{ __('Cost') }}</span>
            </div>
            @foreach ($usage['months'] as $month)
                <div class="zz-use-tr" role="row" @if ($month['selected']) aria-current="true" @endif>
                    <span role="cell" class="zz-use-kind">{{ $month['label'] }}@if ($month['is_current']) <span class="zz-use-soft">({{ __('so far') }})</span>@endif</span>
                    <span role="cell" class="zz-use-n" data-label="{{ __('Sent') }}">{{ number_format($month['sent']) }}</span>
                    <span role="cell" class="zz-use-n" data-label="{{ __('Failed') }}">{{ number_format($month['failed']) }}</span>
                    <span role="cell" class="zz-use-used">
                        @if ($month['percent'] === null)
                            <span class="zz-use-soft">—</span>
                        @else
                            <span class="zz-use-track"><i class="{{ $month['over_by'] > 0 ? 'is-over' : '' }}" style="width: {{ $css(min(100, $month['percent'])) }}%"></i></span>
                            <span class="zz-use-usedtext">
                                @if ($month['over_by'] > 0)
                                    <x-filament::icon icon="heroicon-m-exclamation-triangle" aria-hidden="true" class="zz-use-warn" />
                                    {{ __(':count over', ['count' => number_format($month['over_by'])]) }}
                                @else
                                    {{ round($month['percent']) }}%
                                @endif
                            </span>
                        @endif
                    </span>
                    <span role="cell" class="zz-use-n zz-use-cost">{{ $money($month['cost']) }}</span>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Sent and delivered by week --}}
    <div>
        <x-filament::section :heading="__('Sent and delivered, week by week')">
            @if ($weeks['rows'] === [] || $totals['sent'] === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No email went through :provider in this month.', ['provider' => $name]) }}</p>
            @else
                <div class="zz-use-legend">
                    <span class="zz-use-capline"><i style="background: var(--zz-soft)"></i>{{ __('Sent (accepted by :provider)', ['provider' => $name]) }}</span>
                    @if ($trackingOn)
                        <span class="zz-use-capline"><i style="background: var(--zz-deep)"></i>{{ __('Delivered (reached the recipient)') }}</span>
                    @endif
                </div>
                <div class="zz-use-wk">
                    <div class="zz-use-yax" aria-hidden="true">
                        <span style="bottom: 100%">{{ number_format($weeks['top']) }}</span>
                        <span style="bottom: 50%">{{ number_format($weeks['top'] / 2) }}</span>
                        <span style="bottom: 0">0</span>
                    </div>
                    <div class="zz-use-wk-area">
                        <div class="zz-use-wk-plot">
                            <div class="zz-use-gl" style="bottom: 0"></div>
                            <div class="zz-use-gl" style="bottom: 50%"></div>
                            <div class="zz-use-gl" style="bottom: 100%"></div>
                            <div class="zz-use-groups">
                                @foreach ($weeks['rows'] as $week)
                                    @php
                                        $weekLabel = $trackingOn
                                            ? __(':range: :sent sent, :delivered delivered', ['range' => $week['range'], 'sent' => number_format($week['sent']), 'delivered' => number_format($week['delivered'])])
                                            : __(':range: :sent sent', ['range' => $week['range'], 'sent' => number_format($week['sent'])]);
                                    @endphp
                                    <div class="zz-use-grp" role="img" aria-label="{{ $weekLabel }}" title="{{ $weekLabel }}">
                                        <span class="zz-use-sentbar" style="height: {{ $css($week['sent'] / $weeks['top'] * 100) }}%"></span>
                                        @if ($trackingOn)
                                            <span class="zz-use-delbar" style="height: {{ $css($week['delivered'] / $weeks['top'] * 100) }}%"></span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="zz-use-caps">
                            @foreach ($weeks['rows'] as $week)
                                <div class="zz-use-cap">
                                    <b>{{ $week['range'] }}</b>
                                    <span class="zz-use-capline"><i style="background: var(--zz-soft)"></i><span class="zz-use-desk">{{ __(':count sent', ['count' => number_format($week['sent'])]) }}</span><span class="zz-use-phone">{{ number_format($week['sent']) }}</span></span>
                                    @if ($trackingOn)
                                        <span class="zz-use-capline"><i style="background: var(--zz-deep)"></i><span class="zz-use-desk">{{ __(':count delivered', ['count' => number_format($week['delivered'])]) }}</span><span class="zz-use-phone">{{ number_format($week['delivered']) }}</span></span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </x-filament::section>

    </div>

    {{-- What became of the emails --}}
    <x-filament::section :heading="__('What happened to the emails')">
        @if ($emailLogUrl)
            <x-slot name="afterHeader"><a href="{{ $emailLogUrl }}" class="zz-use-link text-primary-600 dark:text-primary-400">{{ __('Open the Email Log') }}</a></x-slot>
        @endif

        @if ($attempts === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No email went through :provider in this month.', ['provider' => $name]) }}</p>
        @else
            <div class="zz-use-stack" role="img" aria-label="{{ __('Of :total emails: :delivered delivered, :waiting waiting for a report, :problems bounced or spam, :failed failed to send', ['total' => number_format($attempts), 'delivered' => number_format($totals['delivered']), 'waiting' => number_format($totals['waiting']), 'problems' => number_format($totals['problems']), 'failed' => number_format($totals['failed'])]) }}">
                @foreach ($stack as [$label, $count, $color])
                    @if ($count > 0)
                        <span style="flex: {{ $count }} 0 0; background: {{ $color }}"></span>
                    @endif
                @endforeach
            </div>
            <div class="zz-use-chips">
                @foreach ($stack as [$label, $count, $color, $icon, $iconColor])
                    <div class="zz-use-chip">
                        <x-filament::icon :icon="$icon" aria-hidden="true" style="color: {{ $iconColor }}" />
                        <div><b>{{ number_format($count) }}</b><span>{{ $label }} · {{ $share($count, $attempts) }}</span></div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{-- Cost settings --}}
    <x-filament::section id="email-cost-settings" :heading="__('Cost settings')" :description="__(':provider sets its own prices and they can change, so these two numbers are yours to keep up to date. The cost shown is an estimate, not the provider\'s bill.', ['provider' => $name])">
        <form wire:submit="saveCostSettings" class="space-y-4">
            <div class="zz-use-form">
                <div>
                    <label for="email-free-limit" class="text-sm font-medium text-gray-950 dark:text-white">{{ __('Free emails each month') }}</label>
                    <x-filament::input.wrapper :valid="! $errors->has('freeLimit')" class="mt-1">
                        <x-filament::input id="email-free-limit" type="number" min="0" step="1" inputmode="numeric" wire:model="freeLimit" />
                    </x-filament::input.wrapper>
                    @error('freeLimit')
                        <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="email-price" class="text-sm font-medium text-gray-950 dark:text-white">{{ __('Price per 1,000 emails after that (US dollars)') }}</label>
                    <x-filament::input.wrapper :valid="! $errors->has('pricePer1000')" class="mt-1">
                        <x-filament::input id="email-price" type="number" min="0" step="any" inputmode="decimal" wire:model="pricePer1000" />
                    </x-filament::input.wrapper>
                    @error('pricePer1000')
                        <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <div class="zz-ses-actions">
                <x-filament::button type="submit">{{ __('Save') }}</x-filament::button>
            </div>
        </form>
    </x-filament::section>
</div>
