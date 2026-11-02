{{--
    Settings → Email inbox (owner request 2026-11-02, the approved "Email settings" design): how a mailbox receives and sends,
    its signature, how the inbox behaves, and the labels. Receiving is Cloudflare Email Routing pushing each mail to the ERP
    (docs/email-inbox-setup.md); only a hash of the forwarding address is kept, so the address is shown once, when it is made.
--}}
@php
    $mailbox = $this->mailbox();
    $palette = ['blue' => '#2a78d6', 'green' => '#0ca30c', 'orange' => '#ec835a', 'purple' => '#8a5cd6', 'amber' => '#fab219', 'red' => '#d03b3b', 'gray' => '#898781'];
@endphp
<x-filament-panels::page>
    @if ($mailbox === null)
        <x-filament::section>
            <div class="flex flex-col items-center gap-2 py-6 text-center">
                <x-filament::icon icon="heroicon-o-envelope" class="h-10 w-10 text-gray-400" aria-hidden="true" />
                <h2 class="text-base font-semibold">{{ __('No mailbox yet') }}</h2>
                <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">{{ __('A mailbox is an email address this company reads in CRM → Email. Add one with the button above, then connect it to Cloudflare Email Routing.') }}</p>
            </div>
        </x-filament::section>
    @else
        @php
            $sending = $this->sending;
            $receiving = $mailbox->receive_mode === \App\Models\EmailMailbox::RECEIVE_WEBHOOK;
        @endphp
        <div class="space-y-6">
            @if ($this->mailboxes->count() > 1)
                <div class="max-w-md">
                    <label for="mailbox-picker" class="mb-1 block text-sm font-medium">{{ __('Mailbox') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="mailbox-picker" wire:model.live="mailboxId">
                            @foreach ($this->mailboxes as $option)
                                <option value="{{ $option->id }}">{{ $option->name }} · {{ $option->address }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            @endif

            {{-- Connection --}}
            <x-filament::section :heading="__('Connection')" :description="__('How mail sent to :address reaches the ERP, and whether it is working.', ['address' => $mailbox->address])">
                <div class="space-y-4 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        @if (! $mailbox->is_active)
                            <x-filament::badge color="gray">{{ __('Mailbox is off') }}</x-filament::badge>
                        @elseif (! $receiving)
                            <x-filament::badge color="gray">{{ __('Send only') }}</x-filament::badge>
                        @elseif ($mailbox->last_received_at)
                            <x-filament::badge color="success">{{ __('Receiving, last mail :time', ['time' => $mailbox->last_received_at->diffForHumans()]) }}</x-filament::badge>
                        @else
                            <x-filament::badge color="warning">{{ __('Waiting for the first email') }}</x-filament::badge>
                        @endif
                    </div>

                    @if ($receiving)
                        @if ($this->forwardingUrl)
                            <div class="space-y-2 rounded-lg border border-warning-300 bg-warning-50 p-3 dark:border-warning-500/40 dark:bg-warning-500/10"
                                x-data="{ copied: false }">
                                <p class="font-medium">{{ __('Forwarding address for the Cloudflare Worker') }}</p>
                                <div class="flex flex-wrap items-center gap-2">
                                    <input type="text" readonly value="{{ $this->forwardingUrl }}" x-ref="url" class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-xs dark:border-white/20 dark:bg-gray-900" aria-label="{{ __('Forwarding address') }}" x-on:focus="$el.select()">
                                    <x-filament::button color="gray" size="sm" x-on:click="navigator.clipboard.writeText($refs.url.value).then(() => { copied = true; setTimeout(() => copied = false, 2500) })">
                                        <span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                    </x-filament::button>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-300">{{ __('Copy it now: it is shown only this once. Keep it secret, because anyone who has it can put mail into this mailbox.') }}</p>
                            </div>
                        @else
                            <p class="text-gray-500 dark:text-gray-400">{{ __('The forwarding address was shown once, when it was made, and is not kept. Make a new one if you need to put it in Cloudflare again.') }}</p>
                        @endif

                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::button color="gray" wire:click="newToken" wire:confirm="{{ __('Make a new forwarding address? The old one stops working at once, and you must put the new one into the Cloudflare Worker.') }}">{{ __('New token') }}</x-filament::button>
                            <x-filament::button color="gray" wire:click="sendTestMail" wire:loading.attr="disabled" wire:target="sendTestMail">{{ __('Send a test mail to this address') }}</x-filament::button>
                        </div>

                        <details class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <summary class="cursor-pointer font-medium">{{ __('Show setup steps') }}</summary>
                            <ol class="mt-3 list-decimal space-y-2 ps-5 text-gray-600 dark:text-gray-300">
                                <li>{{ __('In Cloudflare open Email → Email Routing of the domain and turn it on. Do this only for a domain whose mail does not live somewhere else, because it replaces the domain\'s MX records.') }}</li>
                                <li>{{ __('Add a fallback address under Destination addresses (for example your Gmail) and verify it. A message goes there if the ERP cannot take it, so no mail is lost.') }}</li>
                                <li>{{ __('Workers & Pages → Create → Worker, name it erp-email-inbox, press Edit code, paste the code below, and Deploy.') }}</li>
                                <li>{{ __('In the Worker\'s Settings → Variables and Secrets add INBOX_URL as a Secret (the forwarding address above) and FALLBACK as text (the verified fallback address).') }}</li>
                                <li>{{ __('Email Routing → Routing rules → Custom addresses: the address :address → Send to a Worker → erp-email-inbox.', ['address' => $mailbox->address]) }}</li>
                                <li>{{ __('Press "Send a test mail to this address" here, or write to it from another account. The mail appears in CRM → Email within seconds.') }}</li>
                            </ol>
                            @php $script = $this->workerScript(); @endphp
                            @if ($script !== '')
                                <div class="mt-3" x-data="{ copied: false }">
                                    <div class="mb-1 flex items-center justify-between">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Worker code') }}</span>
                                        <x-filament::button color="gray" size="xs" x-on:click="navigator.clipboard.writeText($refs.code.textContent).then(() => { copied = true; setTimeout(() => copied = false, 2500) })">
                                            <span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                        </x-filament::button>
                                    </div>
                                    <pre x-ref="code" class="max-h-72 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ $script }}</pre>
                                </div>
                            @endif
                        </details>
                    @endif
                </div>
            </x-filament::section>

            {{-- Sending --}}
            <x-filament::section :heading="__('Sending')" :description="__('Mail leaves through the account set up in Email Setup.')">
                @if ($sending['site'] === null)
                    <p class="text-sm">{{ __('This company has no site yet, so there is no email account to send through.') }}</p>
                @else
                    <div class="space-y-3 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge :color="$sending['ready'] ? 'success' : 'warning'">{{ $sending['ready'] ? __('Working') : __('Not set up') }}</x-filament::badge>
                            <span class="font-medium">{{ $sending['provider']->name }}</span>
                        </div>
                        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('SMTP host') }}</dt><dd class="min-w-0 break-all text-right font-medium">{{ $sending['provider']->host !== '' ? $sending['provider']->host.($sending['port'] ? ':'.$sending['port'] : '') : '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('From address') }}</dt><dd class="min-w-0 break-all text-right font-medium">{{ $sending['from'] ?: '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('This month') }}</dt><dd class="text-right font-medium">{{ __(':sent sent, :failed failed', ['sent' => $sending['sent'], 'failed' => $sending['failed']]) }}</dd></div>
                        </dl>
                        @if ($sending['from'] && strcasecmp((string) $sending['from'], $mailbox->address) !== 0)
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Replies are sent from :address; the email account\'s own From address is :from. Most services only send from an address or domain you verified with them.', ['address' => $mailbox->address, 'from' => $sending['from']]) }}</p>
                        @endif
                        <div class="flex flex-wrap gap-2">
                            <x-filament::button tag="a" :href="$sending['setupUrl']" color="gray" size="sm">{{ __('Open Email Setup') }}</x-filament::button>
                            <x-filament::button tag="a" :href="$sending['deliveryUrl']" color="gray" size="sm">{{ __('See email delivery') }}</x-filament::button>
                        </div>
                    </div>
                @endif
            </x-filament::section>

            {{ $this->form }}

            <div>
                <x-filament::button wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ __('Save changes') }}</x-filament::button>
            </div>

            {{-- Labels --}}
            <x-filament::section :heading="__('Labels')" :description="__('Shown beside each conversation. They belong to the company and are shared by its mailboxes.')">
                <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                    @forelse ($this->labels as $label)
                        <li class="flex items-center justify-between gap-3 py-2" wire:key="label-row-{{ $label->id }}">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="h-3 w-3 shrink-0 rounded" style="background: {{ $palette[$label->color] ?? '#898781' }}" aria-hidden="true"></span>
                                <span class="truncate font-medium">{{ $label->name }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count conversation|:count conversations', $label->threads_count) }}</span>
                            </span>
                            <span class="flex shrink-0 gap-1">
                                <x-filament::button color="gray" size="xs" wire:click="editLabel({{ $label->id }})">{{ __('Edit') }}</x-filament::button>
                                <x-filament::button color="danger" size="xs" wire:click="deleteLabel({{ $label->id }})" wire:confirm="{{ __('Delete the label :name? It is taken off its conversations; the mail itself stays.', ['name' => $label->name]) }}">{{ __('Delete') }}</x-filament::button>
                            </span>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500 dark:text-gray-400">{{ __('No labels yet.') }}</li>
                    @endforelse
                </ul>

                <form class="mt-4 space-y-3 border-t border-gray-200 pt-4 dark:border-white/10" wire:submit="saveLabel">
                    <p class="text-sm font-medium">{{ $this->editingLabelId ? __('Edit label') : __('New label') }}</p>
                    <div class="max-w-sm">
                        <x-filament::input.wrapper :valid="! $errors->has('labelName')">
                            <x-filament::input type="text" wire:model="labelName" maxlength="60" :placeholder="__('Label name')" :aria-label="__('Label name')" />
                        </x-filament::input.wrapper>
                        @error('labelName')<p class="mt-1 text-xs text-danger-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex flex-wrap gap-3" role="radiogroup" aria-label="{{ __('Label colour') }}">
                        @foreach (\App\Models\EmailLabel::COLORS as $color)
                            <label class="relative inline-flex h-7 w-7 cursor-pointer items-center justify-center" title="{{ $color }}">
                                <input type="radio" wire:model="labelColor" value="{{ $color }}" class="peer sr-only" aria-label="{{ $color }}">
                                <span class="h-5 w-5 rounded-full outline outline-2 outline-offset-2 outline-transparent peer-checked:outline-gray-900 peer-focus-visible:outline-primary-600 dark:peer-checked:outline-white" style="background: {{ $palette[$color] }}"></span>
                            </label>
                        @endforeach
                    </div>
                    <div class="flex gap-2">
                        <x-filament::button type="submit" size="sm">{{ $this->editingLabelId ? __('Save label') : __('Create label') }}</x-filament::button>
                        @if ($this->editingLabelId)
                            <x-filament::button color="gray" size="sm" wire:click="cancelLabel">{{ __('Cancel') }}</x-filament::button>
                        @endif
                    </div>
                </form>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
