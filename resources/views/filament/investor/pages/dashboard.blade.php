@php
    use App\Support\MoneyFormatter;
@endphp

<x-filament-panels::page>
    @php($account = $this->account())
    @php($currency = $this->currency())

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

    @php($outstanding = app(\App\Services\AgreementService::class)->outstanding($account))
    @if ($outstanding->isNotEmpty())
        <x-filament::section icon="heroicon-o-document-check" icon-color="warning">
            <p class="text-sm">{{ __('Please read and accept: :titles', ['titles' => $outstanding->pluck('title')->implode(', ')]) }}</p>
            <div class="mt-2">
                <x-filament::link :href="\App\Filament\Investor\Pages\Agreements::getUrl()">{{ __('Open agreements') }}</x-filament::link>
            </div>
        </x-filament::section>
    @endif

    @if ($account->isInvestor())
        @php($summary = $this->portal()->investorSummary($account))

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Capital at work') }}</div>
                <div class="text-xl font-semibold">{{ MoneyFormatter::currency($summary['capital_at_work'], $currency) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Profit received') }}</div>
                <div class="text-xl font-semibold">{{ MoneyFormatter::currency($summary['profit_received'], $currency) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Profit confirmed, not yet paid') }}</div>
                <div class="text-xl font-semibold">{{ MoneyFormatter::currency($summary['profit_pending'], $currency) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Total paid to you') }}</div>
                <div class="text-xl font-semibold">{{ MoneyFormatter::currency($summary['paid_out'], $currency) }}</div>
            </x-filament::section>
        </div>

        <x-filament::section :heading="__('My investments')">
            @php($board = $this->board())

            @if ($board['groups']->isEmpty() && $board['legacy']->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No investments yet.') }}</p>
            @else
                <div class="space-y-6">
                    @foreach ($board['groups'] as $section)
                        <div class="space-y-3" data-investor-group="{{ $section['group']->group_code }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <div class="font-semibold">{{ $section['group']->group_code }} — {{ $section['group']->name }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">
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
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('This group has not started yet. Its first project begins when the company starts the group.') }}</p>
                            @endif

                            @if ($section['cards']->isEmpty() && ! $section['group']->isOpen())
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing is waiting for you in this group. Finished projects are on the Payouts page.') }}</p>
                            @else
                                <div class="grid gap-4 md:grid-cols-2">
                                    @foreach ($section['cards'] as $card)
                                        @include('filament.investor.pages.partials.project-card', ['card' => $card, 'currency' => $currency])
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if ($board['legacy']->isNotEmpty())
                        <div class="grid gap-4 md:grid-cols-2">
                            @foreach ($board['legacy'] as $card)
                                @include('filament.investor.pages.partials.project-card', ['card' => $card, 'currency' => $currency])
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </x-filament::section>

        @livewire(\App\Filament\Investor\Widgets\ProfitTrend::class)

        <x-filament::section :heading="__('Latest statement entries')">
            @include('filament.investor.pages.partials.statement-rows', ['entries' => $this->recentStatement(), 'currency' => $currency])
            <div class="mt-3">
                <x-filament::link :href="\App\Filament\Investor\Pages\Statement::getUrl()">{{ __('See the full statement') }}</x-filament::link>
            </div>
        </x-filament::section>
    @endif

    @if ($account->isChannelPartner())
        @php($partner = $this->portal()->partnerSummary($account))

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
