{{--
    Settings → Amazon SES, section 2: the Amazon account itself (sandbox or production, the daily limit, the
    fastest rate, Amazon's own view of the account), read by refresh(). The email usage, the free allowance and the
    cost moved to Settings → Email delivery on 2026-11-02 so they work for any mail service; this section links
    to it. The styles are the .zz-use-* rules in resources/css/filament/admin/theme.css.
--}}
@php
    $account = $overview['account'] ?? null;
    $css = fn (float $number): string => number_format($number, 1, '.', '');
    $deliveryUrl = \App\Filament\Pages\EmailDelivery::canAccess() ? \App\Filament\Pages\EmailDelivery::getUrl() : null;
@endphp

<div class="zz-use space-y-4" id="ses-account">
    <x-filament::section :heading="__('2. Your Amazon SES account')" :description="__('Amazon\'s own limits belong to the whole AWS account, so every company that uses it shares them.')">
        @if ($account)
            @php($left = max(0, $account['max_24h'] - $account['sent_24h']))
            @php($dailyPercent = $account['max_24h'] > 0 ? min(100, $account['sent_24h'] / $account['max_24h'] * 100) : 0)
            <x-slot name="afterHeader">
                @if (! $account['sending_enabled'])
                    <x-filament::badge color="danger" icon="heroicon-m-x-circle">{{ __('Paused by Amazon') }}</x-filament::badge>
                @elseif ($account['sandbox'])
                    <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">{{ __('Sandbox') }}</x-filament::badge>
                @else
                    <x-filament::badge color="success" icon="heroicon-m-check-circle">{{ __('Production') }}</x-filament::badge>
                @endif
            </x-slot>
            <p class="zz-use-bignum">{{ number_format($left) }} <span>{{ __('more allowed today') }}</span></p>
            <div class="zz-use-bar" role="img" aria-label="{{ __(':sent of :max emails sent in the last 24 hours', ['sent' => number_format($account['sent_24h']), 'max' => number_format($account['max_24h'])]) }}"><span style="width: {{ $css($dailyPercent) }}%"></span></div>
            <div class="zz-use-bar-legend"><span>{{ __(':count sent in the last 24 hours', ['count' => number_format($account['sent_24h'])]) }}</span><span>{{ __(':count allowed', ['count' => number_format($account['max_24h'])]) }}</span></div>
            <dl class="zz-use-facts zz-use-facts--rows">
                <div>
                    <dt>{{ __('Mode') }}</dt>
                    <dd>{{ $account['sandbox'] ? __('Sandbox: only verified addresses receive email') : __('Production: can send to anyone') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Sending') }}</dt>
                    <dd>{{ $account['sending_enabled'] ? __('On') : __('Paused by Amazon') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Fastest rate') }}</dt>
                    <dd>{{ __(':count per second', ['count' => rtrim(rtrim(number_format($account['max_rate'], 1), '0'), '.')]) }}</dd>
                </div>
                @if ($account['enforcement'] !== '')
                    <div>
                        <dt>{{ __('Amazon\'s view of the account') }}</dt>
                        <dd class="{{ $account['enforcement'] === 'HEALTHY' ? '' : 'text-danger-600 dark:text-danger-400' }}">{{ $account['enforcement'] === 'HEALTHY' ? __('Healthy') : $account['enforcement'] }}</dd>
                    </div>
                @endif
            </dl>
            @if ($account['enforcement'] !== '' && $account['enforcement'] !== 'HEALTHY')
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Too many bounces or complaints put an account under review. Find the cause in the Email Log and remove bad addresses before Amazon pauses sending.') }}</p>
            @endif
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Save the key and press Refresh.') }}</p>
        @endif
    </x-filament::section>

    @if ($deliveryUrl)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('How many emails went out, how much of the free allowance is used and what it costs: see') }}
            <a href="{{ $deliveryUrl }}" class="zz-use-link text-primary-600 dark:text-primary-400">{{ __('Settings → Email delivery') }}</a>.
        </p>
    @endif
</div>
