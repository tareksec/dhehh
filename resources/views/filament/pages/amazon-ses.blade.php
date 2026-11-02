<x-filament-panels::page>
    @if (! $this->hasSite())
        <x-filament::section>
            <p class="text-sm">{{ __('This company has no site yet. Add one in Site → Storefront Settings; the AWS key and the email account belong to a site.') }}</p>
        </x-filament::section>
    @else
        <div class="zz-ses space-y-6" wire:init="refresh">
            {{-- 1. The AWS key --}}
            <x-filament::section :heading="__('1. Connect your AWS account')" :description="__('Emails still go out through the SMTP account in Integrations → Email Setup. This key lets the dashboard control the Amazon SES account behind it.')">
                <form wire:submit="saveCredentials" class="space-y-4">
                    {{ $this->form }}

                    <div class="zz-ses-actions flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                        <x-filament::button type="submit">{{ __('Save and check') }}</x-filament::button>
                        {{ $this->forgetKeyAction }}
                    </div>
                </form>

                <details class="mt-4 text-sm">
                    <summary class="cursor-pointer py-2 font-medium">{{ __('Which AWS permissions does the key need?') }}</summary>
                    <p class="mt-2">{{ __('In AWS IAM create a separate user for the dashboard (not the SMTP user), give it this policy and create an access key for it. Nothing else is needed.') }}</p>
                    <pre tabindex="0" class="mt-2 max-w-full overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5">{{ $this->iamPolicy() }}</pre>
                </details>
            </x-filament::section>

            @if (! $loaded)
                <x-filament::section>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Reading your Amazon SES account…') }}</p>
                </x-filament::section>
            @else
                {{-- Checklist --}}
                <x-filament::section :heading="__('Is email set up properly?')">
                    <ul class="space-y-3 text-sm">
                        @foreach ($this->checklist() as $item)
                            <li class="flex items-start gap-2.5">
                                @if ($item['ok'] === true)
                                    <x-filament::icon icon="heroicon-m-check-circle" aria-hidden="true" class="mt-0.5 h-5 w-5 shrink-0 text-success-600 dark:text-success-400" />
                                @elseif ($item['ok'] === false)
                                    <x-filament::icon icon="heroicon-m-x-circle" aria-hidden="true" class="mt-0.5 h-5 w-5 shrink-0 text-danger-600 dark:text-danger-400" />
                                @else
                                    <x-filament::icon icon="heroicon-m-question-mark-circle" aria-hidden="true" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
                                @endif
                                <div class="min-w-0 flex-1">
                                    <span class="sr-only">{{ $item['ok'] === true ? __('Done') : ($item['ok'] === false ? __('Needs attention') : __('Not checked yet')) }}:</span>
                                    {{ $item['label'] }}
                                    @if ($item['hint'])
                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $item['hint'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-4">
                        <x-filament::button color="gray" size="sm" wire:click="refresh">{{ __('Refresh') }}</x-filament::button>
                    </div>
                </x-filament::section>

                @if (! empty($overview['errors']['account']))
                    <x-filament::section>
                        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $overview['errors']['account'] }}</p>
                    </x-filament::section>
                @endif

                {{-- 2. The Amazon account and its daily limit; the email usage is on Settings → Email delivery --}}
                @include('filament.pages.amazon-ses-account')

                {{-- 3. Domains and addresses --}}
                @if (isset($overview['identities']) || ! empty($overview['errors']['identities']))
                    <x-filament::section :heading="__('3. Domains and addresses that may send')" :description="__('This company\'s own domains come first. Domains of other companies on the same AWS account are listed below them.')">
                        <x-slot name="afterHeader">{{ $this->addIdentityAction }}</x-slot>

                        @if (! empty($overview['errors']['identities']))
                            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $overview['errors']['identities'] }}</p>
                        @elseif (($overview['identities'] ?? []) === [])
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing is verified in this account yet. Add the domain emails are sent from.') }}</p>
                        @else
                            @unless ($this->canRemoveDomains())
                                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Only a super admin can remove a domain or address.') }}</p>
                            @endunless
                            <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                                @php($identities = collect($overview['identities'])->sortByDesc('own')->values())
                                @foreach ($identities as $identity)
                                    @if (! $identity['own'] && ($loop->first || $identities[$loop->index - 1]['own']))
                                        <li class="pb-1 pt-5 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                            {{ __('Other domains and addresses in this AWS account (used by other companies)') }}
                                        </li>
                                    @endif
                                    <li class="py-3 {{ $identity['own'] ? '' : 'opacity-75' }}">
                                        {{-- Phone: the name has a line to itself, then the status, the kind and the button. From sm up everything sits on one line. --}}
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-2">
                                            <span class="w-full min-w-0 break-all font-semibold sm:w-auto">{{ $identity['name'] }}</span>
                                            <x-filament::badge :color="$identity['verification'] === 'SUCCESS' ? 'success' : ($identity['verification'] === 'FAILED' ? 'danger' : 'warning')">
                                                {{ match ($identity['verification']) {
                                                    'SUCCESS' => __('Verified'),
                                                    'FAILED' => __('Verification failed'),
                                                    'TEMPORARY_FAILURE' => __('Temporary problem'),
                                                    default => __('Waiting for verification'),
                                                } }}
                                            </x-filament::badge>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $identity['type'] === 'DOMAIN' ? __('Domain') : __('Address') }}</span>
                                            @if ($this->canRemoveDomains())
                                                <x-filament::button class="ms-auto shrink-0" color="danger" size="xs" outlined wire:click="mountAction('deleteIdentity', {{ \Illuminate\Support\Js::from(['identity' => $identity['name']]) }})">{{ __('Remove') }}</x-filament::button>
                                            @endif
                                        </div>
                                        @if ($identity['verification'] !== 'SUCCESS' && $identity['dns_records'] !== [])
                                            <div class="mt-2 min-w-0 rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5">
                                                <p class="mb-2">{{ __('Add these three CNAME records at the domain provider (where the domain\'s DNS is managed):') }}</p>
                                                @foreach ($identity['dns_records'] as $record)
                                                    <div class="mb-2 break-all"><span class="font-semibold">{{ $record['type'] }}</span> · {{ __('Name') }}: <code>{{ $record['name'] }}</code><br>{{ __('Value') }}: <code>{{ $record['value'] }}</code></div>
                                                @endforeach
                                            </div>
                                        @elseif ($identity['verification'] !== 'SUCCESS' && $identity['type'] !== 'DOMAIN')
                                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Amazon emailed a link to this address. Click it to finish.') }}</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-filament::section>
                @endif

                {{-- 4. Tracking --}}
                @php($tracking = $overview['tracking'] ?? [])
                <x-filament::section :heading="__('4. Bounces, complaints and deliveries')" :description="__('When on, Amazon reports what became of every email and the Email Log changes from Sent to Delivered, Bounced or Marked as spam.')">
                    @if ($tracking['enabled'] ?? false)
                        <p class="text-sm">
                            <x-filament::badge :color="($tracking['confirmed'] ?? null) === true ? 'success' : 'warning'">
                                {{ ($tracking['confirmed'] ?? null) === true ? __('On and connected') : __('On, waiting for Amazon to confirm') }}
                            </x-filament::badge>
                        </p>
                        @if (! empty($overview['errors']['tracking']))
                            <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $overview['errors']['tracking'] }}</p>
                        @endif
                        <div class="zz-ses-actions mt-3">{{ $this->disableTrackingAction }}</div>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Off. Needs this site to be reachable on a public https address, because Amazon posts the reports to it.') }}</p>
                        <div class="zz-ses-actions mt-3">{{ $this->enableTrackingAction }}</div>
                    @endif
                </x-filament::section>

                {{-- 5. Suppression list --}}
                @if (isset($overview['suppressed']) || ! empty($overview['errors']['suppressed']))
                    <x-filament::section :heading="__('5. Addresses Amazon will not send to')" :description="__('After a bounce or a spam complaint Amazon puts the address here and refuses to send to it, which protects your sending reputation.')">
                        <x-slot name="afterHeader">{{ $this->addSuppressedAction }}</x-slot>

                        @if (! empty($overview['errors']['suppressed']))
                            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $overview['errors']['suppressed'] }}</p>
                        @elseif (($overview['suppressed'] ?? []) === [])
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No address is blocked.') }}</p>
                        @else
                            <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                                @foreach ($overview['suppressed'] as $row)
                                    <li class="flex flex-wrap items-center gap-x-2 gap-y-2 py-3">
                                        <span class="w-full min-w-0 break-all font-semibold sm:w-auto">{{ $row['email'] }}</span>
                                        <x-filament::badge :color="$row['reason'] === 'COMPLAINT' ? 'danger' : 'warning'">{{ $row['reason'] === 'COMPLAINT' ? __('Marked as spam') : __('Bounced') }}</x-filament::badge>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['since'] }}</span>
                                        <x-filament::button class="ms-auto shrink-0" color="gray" size="xs" outlined wire:click="mountAction('removeSuppressed', {{ \Illuminate\Support\Js::from(['email' => $row['email']]) }})">{{ __('Allow again') }}</x-filament::button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-filament::section>
                @endif
            @endif
        </div>
    @endif
</x-filament-panels::page>
