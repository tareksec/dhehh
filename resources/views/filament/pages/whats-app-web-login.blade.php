<x-filament-panels::page>
    <div @if ($this->shouldPoll()) wire:poll.4s="refreshStatuses" @endif style="display: grid; gap: 1.5rem;">
        <x-filament::section :heading="__('How it works')">
            <ul style="font-size: .875rem; line-height: 1.6; padding-left: 1.1rem; list-style: disc;">
                <li>{{ __('Scan the QR code with the WhatsApp Business app (Settings → Linked devices → Link a device), exactly like WhatsApp Web.') }}</li>
                <li>{{ __('Every 1:1 chat the phone shares - full history and every new message - is saved into the ERP Inbox, with photos, voice notes and documents.') }}</li>
                <li>{{ __('Every contact is saved as a WhatsApp contact and linked to the matching customer or lead by phone number.') }}</li>
                <li>{{ __('The archive belongs to the ERP: logging out, deleting the channel or the number being banned never deletes saved chats or customer info. "Delete for everyone" only marks a message as deleted.') }}</li>
                <li>{{ __('This connection is read-only - reply from the phone app. WhatsApp Web automation is not an official Meta API, so use it on your own number at your own risk.') }}</li>
            </ul>
        </x-filament::section>

        <x-filament::section :heading="__('Bridge connection')" :description="__('The WhatsApp Web bridge is a small separate service (whatsapp-bridge folder) that keeps the linked device online.')">
            <div style="display: grid; gap: 1rem; max-width: 640px;">
                <div>
                    <label style="display:block; font-size: .8rem; font-weight: 600; margin-bottom: .3rem;">{{ __('Bridge URL') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input type="url" wire:model="settings.bridge_url" placeholder="https://wa-bridge.example.com" />
                    </x-filament::input.wrapper>
                    @error('settings.bridge_url') <p style="font-size: .72rem; color: rgb(220 38 38); margin-top: .3rem;">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label style="display:block; font-size: .8rem; font-weight: 600; margin-bottom: .3rem;">
                        {{ __('Bridge API key') }} {{ ($settings['has_api_key'] ?? false) ? __('(already saved - leave blank to keep it)') : '' }}
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input type="password" wire:model="settings.api_key" placeholder="{{ ($settings['has_api_key'] ?? false) ? '••••••••' : '' }}" />
                    </x-filament::input.wrapper>
                    <p style="font-size: .72rem; color: rgb(113 113 122); margin-top: .3rem;">{{ __('Same value as BRIDGE_API_KEY on the bridge. Stored encrypted per company.') }}</p>
                    @error('settings.api_key') <p style="font-size: .72rem; color: rgb(220 38 38); margin-top: .3rem;">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-filament::button wire:click="saveSettings" icon="heroicon-m-check">{{ __('Save settings') }}</x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('Link a WhatsApp number')">
            @if (! ($settings['ready'] ?? false))
                <p style="font-size: .875rem; color: rgb(161 98 7);">{{ __('Save the bridge URL and API key above first.') }}</p>
            @else
                <div style="display: flex; gap: .75rem; align-items: flex-start; flex-wrap: wrap; max-width: 640px;">
                    <div style="flex: 1 1 260px;">
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" wire:model="deviceName" :placeholder="__('Name, e.g. Sales WhatsApp')" />
                        </x-filament::input.wrapper>
                        @error('deviceName') <p style="font-size: .72rem; color: rgb(220 38 38); margin-top: .3rem;">{{ $message }}</p> @enderror
                    </div>
                    <x-filament::button wire:click="linkDevice" icon="heroicon-m-qr-code">{{ __('Show QR code') }}</x-filament::button>
                </div>
            @endif
        </x-filament::section>

        @foreach ($this->channels() as $channel)
            @php
                $state = $live[$channel->getKey()] ?? null;
                $status = $state['status'] ?? $channel->web_status ?? 'pending';
                $label = \App\Models\ConversationChannel::WEB_STATUSES[$status] ?? \Illuminate\Support\Str::headline($status);
                $color = match ($status) {
                    'connected' => 'success',
                    'qr', 'starting', 'connecting', 'reconnecting', 'pending' => 'warning',
                    default => 'danger',
                };
            @endphp

            <x-filament::section wire:key="web-channel-{{ $channel->getKey() }}" :heading="$channel->display_name">
                <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; align-items: flex-start;">
                    <div style="flex: 1 1 280px; display: grid; gap: .5rem; font-size: .875rem;">
                        <div><x-filament::badge :color="$color" style="display: inline-flex;">{{ __($label) }}</x-filament::badge></div>
                        <div>{{ __('Phone') }}: <strong>{{ $channel->web_phone ? '+'.$channel->web_phone : '—' }}</strong></div>
                        <div>{{ __('Chats saved') }}: <strong>{{ number_format($channel->conversations_count) }}</strong></div>
                        <div>{{ __('Messages saved') }}: <strong>{{ number_format($this->messageCount($channel)) }}</strong></div>
                        <div>{{ __('Contacts saved') }}: <strong>{{ number_format($this->contactCount($channel)) }}</strong></div>
                        <div>
                            {{ __('History sync') }}:
                            <strong>
                                @if ($channel->web_history_synced_at)
                                    {{ __('Complete') }} ({{ $channel->web_history_synced_at->diffForHumans() }})
                                @elseif (isset($state['history_progress']))
                                    {{ (int) $state['history_progress'] }}%
                                @else
                                    —
                                @endif
                            </strong>
                        </div>
                        @if ($channel->web_connected_at)
                            <div style="color: rgb(113 113 122);">{{ __('Last connected') }}: {{ $channel->web_connected_at->diffForHumans() }}</div>
                        @endif
                        @if ($status !== 'connected' && filled($state['last_error'] ?? null))
                            <div style="color: rgb(161 98 7);">{{ __('Last error') }}: <strong>{{ $state['last_error'] }}</strong>@if (filled($state['last_error_code'] ?? null)) ({{ $state['last_error_code'] }})@endif</div>
                        @endif
                        @if (in_array($status, ['logged_out', 'banned', 'replaced', 'error', 'qr_expired'], true))
                            <p style="color: rgb(161 98 7);">{{ __('Not syncing right now. Everything already saved stays in the ERP. Scan a new QR code to resume.') }}</p>
                        @endif

                        <div style="display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .5rem;">
                            @if ($status !== 'connected')
                                <x-filament::button size="sm" wire:click="connect({{ $channel->getKey() }})" icon="heroicon-m-qr-code">{{ __('Show QR code') }}</x-filament::button>
                            @endif
                            <x-filament::button size="sm" color="gray" wire:click="refreshNames({{ $channel->getKey() }})" icon="heroicon-m-identification">{{ __('Fill missing names') }}</x-filament::button>
                            @if (in_array($status, ['connected', 'qr', 'connecting', 'reconnecting'], true))
                                <x-filament::button size="sm" color="danger" outlined
                                    wire:click="logout({{ $channel->getKey() }})"
                                    wire:confirm="{{ __('Unlink this device from WhatsApp? Saved chats and contacts stay in the ERP.') }}"
                                    icon="heroicon-m-arrow-right-start-on-rectangle">{{ __('Log out') }}</x-filament::button>
                            @endif
                        </div>
                    </div>

                    @if ($status === 'qr' && filled($state['qr'] ?? null))
                        <div style="text-align: center;">
                            <img src="{{ $state['qr'] }}" alt="{{ __('WhatsApp QR code') }}" style="width: 260px; height: 260px; background: #fff; padding: 8px; border-radius: 12px;">
                            <p style="font-size: .75rem; color: rgb(113 113 122); margin-top: .4rem; max-width: 260px;">{{ __('Open WhatsApp Business → Settings → Linked devices → Link a device, then scan.') }}</p>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
