@php
    use App\Support\MoneyFormatter;

    $money = fn (float $amount): string => MoneyFormatter::currency($amount, $currency);
    $counts = $summary['counts'];
    $maxDay = max(1, collect($summary['by_day'])->map(fn (array $day): int => $day['sent'] + $day['failed'])->max() ?? 1);
    $cardStyle = 'display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 1rem';
@endphp

<x-filament-panels::page>
    @if ($urlMissing !== [])
        <x-filament::section>
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">
                {{ __('The SMS gateway URL has no :missing in it, so no SMS can be sent. Replace the sample words (Receiver, TestSMS) with {phone} and {message}.', ['missing' => implode(' / ', $urlMissing)]) }}
                <a href="{{ $integrationsUrl }}" class="underline">{{ __('Fix it in Integrations → SMS Gateway.') }}</a>
            </p>
        </x-filament::section>
    @endif

    @if ($notice)
        <x-filament::section>
            <p class="text-sm text-warning-600 dark:text-warning-400">{{ $notice }}</p>
        </x-filament::section>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('Counts come from this system\'s SMS log. "Sent" means the gateway accepted the message; BulkSMSBD does not report whether the phone received it.') }}
        </p>
        <div style="min-width: 12rem">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="period" aria-label="{{ __('Period') }}">
                    @foreach ($periodLabels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </div>

    <div style="{{ $cardStyle }}">
        {{-- Balance --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('Balance on BulkSMSBD') }}</x-slot>

            @if ($allCompanies)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Pick one company in the top bar to see its balance.') }}</p>
            @elseif ($setting === null)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('No SMS gateway is saved yet.') }}
                    <a href="{{ $integrationsUrl }}" class="text-primary-600 underline dark:text-primary-400">{{ __('Set it up in Integrations → SMS Gateway.') }}</a>
                </p>
            @elseif (! $balanceSupported)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('The balance can be read only for a BulkSMSBD gateway.') }}</p>
            @else
                <div wire:init="loadBalance" class="space-y-3">
                    @if ($balance === null)
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Checking the balance...') }}</p>
                    @elseif ($balance['ok'])
                        <p class="text-3xl font-semibold">{{ $money((float) $balance['balance']) }}</p>
                        @if ($lowBalance !== null && (float) $balance['balance'] < $lowBalance)
                            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ __('The balance is below your alert level of :amount. Recharge soon so messages keep going out.', ['amount' => $money($lowBalance)]) }}</p>
                        @endif
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Checked :time', ['time' => \Illuminate\Support\Carbon::parse($balance['checked_at'])->format('d M, H:i')]) }}</p>
                    @else
                        <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $balance['message'] }}</p>
                        @if (filled($balance['raw'] ?? null))
                            <p class="break-all text-xs text-gray-500 dark:text-gray-400">{{ __('The gateway said:') }} <code>{{ $balance['raw'] }}</code></p>
                        @endif
                    @endif

                    <div class="flex flex-wrap gap-2">
                        <x-filament::button size="sm" color="gray" wire:click="refreshBalance" wire:loading.attr="disabled" wire:target="refreshBalance,loadBalance">
                            {{ __('Refresh') }}
                        </x-filament::button>
                        @if ($rechargeUrl)
                            <x-filament::button size="sm" tag="a" :href="$rechargeUrl" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square">
                                {{ __('Recharge on BulkSMSBD') }}
                            </x-filament::button>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('BulkSMSBD lets a program read the balance but not recharge it, so a recharge is made on their site.') }}</p>
                </div>
            @endif
        </x-filament::section>

        {{-- Sent --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('SMS sent') }}</x-slot>
            <p class="text-3xl font-semibold text-success-600 dark:text-success-400">{{ number_format($counts['sent']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __(':parts SMS parts used (a long or Bangla message takes more than one)', ['parts' => number_format($summary['parts'])]) }}</p>
        </x-filament::section>

        {{-- Cost --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('Estimated cost') }}</x-slot>
            @if ($summary['cost'] !== null)
                <p class="text-3xl font-semibold">{{ $money($summary['cost']) }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __(':parts parts × :price. An estimate: BulkSMSBD\'s own report has the exact charge.', ['parts' => number_format($summary['parts']), 'price' => $money($price)]) }}</p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Enter your price per SMS to see the estimated cost.') }}
                    <a href="{{ $integrationsUrl }}" class="text-primary-600 underline dark:text-primary-400">{{ __('Integrations → SMS Gateway') }}</a>
                </p>
            @endif
        </x-filament::section>

        {{-- Refused --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('Refused by the gateway') }}</x-slot>
            <p class="text-3xl font-semibold {{ $counts['failed'] > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ number_format($counts['failed']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('Wrong key or sender ID, no balance, an invalid number...') }}
                @if ($logsUrl)
                    <a href="{{ $logsUrl }}" class="text-primary-600 underline dark:text-primary-400">{{ __('See why in the SMS Log') }}</a>
                @endif
            </p>
        </x-filament::section>

        {{-- Skipped / waiting --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('Not sent by our own rules') }}</x-slot>
            <p class="text-3xl font-semibold">{{ number_format($counts['skipped']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('No wording written, number opted out, no phone... These cost nothing.') }}
                @if ($counts['queued'] > 0)
                    {{ __(':count more are waiting in the queue.', ['count' => number_format($counts['queued'])]) }}
                @endif
            </p>
        </x-filament::section>
    </div>

    {{-- By kind --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('By kind of message') }}</x-slot>
        @if ($summary['by_kind'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No SMS in this period.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400">
                            <th class="py-2 pe-4 text-start font-medium">{{ __('Message') }}</th>
                            <th class="whitespace-nowrap py-2 pe-4 text-end font-medium">{{ __('Sent') }}</th>
                            <th class="whitespace-nowrap py-2 pe-4 text-end font-medium">{{ __('Parts') }}</th>
                            <th class="whitespace-nowrap py-2 pe-4 text-end font-medium">{{ __('Refused') }}</th>
                            <th class="whitespace-nowrap py-2 text-end font-medium">{{ __('Not sent') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($summary['by_kind'] as $kind => $row)
                            <tr class="border-t border-gray-100 dark:border-white/10">
                                <td class="py-2 pe-4">{{ $kindLabels[$kind] ?? $kind }}</td>
                                <td class="py-2 pe-4 text-end">{{ number_format($row['sent']) }}</td>
                                <td class="py-2 pe-4 text-end">{{ number_format($row['parts']) }}</td>
                                <td class="py-2 pe-4 text-end">{{ number_format($row['failed']) }}</td>
                                <td class="py-2 text-end">{{ number_format($row['skipped'] + $row['queued']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- By day --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('Day by day') }}</x-slot>
        <div class="space-y-1">
            @foreach (array_reverse($days) as $day)
                @php($row = $summary['by_day'][$day] ?? ['sent' => 0, 'failed' => 0])
                <div class="flex items-center gap-3 text-sm">
                    <span class="w-24 shrink-0 text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($day)->format('d M, D') }}</span>
                    <span class="flex h-3 min-w-0 flex-1 overflow-hidden rounded bg-gray-100 dark:bg-white/10" aria-hidden="true">
                        <span style="width: {{ round($row['sent'] / $maxDay * 100, 2) }}%; background: rgb(34 197 94)"></span>
                        <span style="width: {{ round($row['failed'] / $maxDay * 100, 2) }}%; background: rgb(239 68 68)"></span>
                    </span>
                    <span class="w-40 shrink-0 text-end">{{ number_format($row['sent']) }} {{ __('sent') }}@if ($row['failed'] > 0), <span class="text-danger-600 dark:text-danger-400">{{ number_format($row['failed']) }} {{ __('refused') }}</span>@endif</span>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
