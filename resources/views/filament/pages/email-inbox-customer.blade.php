{{--
    The customer panel of CRM → Email (owner request 2026-11-02): who the open conversation is with. The customer is the
    one matched by the sender's email when the mail arrived (EmailContactMatcher); a person who is not a customer yet is
    shown as a contact. On a wide screen the panel sits beside the conversation; on a narrower one it slides over it
    (the person icon in the toolbar opens and closes it; the choice is remembered in the browser).
--}}
@php
    $thread = $this->thread;
    $customer = $contact['customer'];
    $name = $customer?->name ?: ($thread->contact_name ?: $thread->contact_email);
    $canManage = $this->canManage();
@endphp
<aside class="zz-mail-cust" x-bind:class="{ 'is-open': cust }" x-cloak aria-label="{{ __('Customer') }}" wire:key="customer-panel-{{ $thread->id }}">
    <header class="zz-mail-cust-head">
        <h3>{{ __('Customer') }}</h3>
        <button type="button" class="zz-mail-ib zz-mail-ib-sm" x-on:click="cust = false" aria-label="{{ __('Close') }}">
            <x-filament::icon icon="heroicon-m-x-mark" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />
        </button>
    </header>

    <div class="zz-mail-cust-body">
        <div class="zz-mail-cust-who">
            <span class="zz-mail-av {{ $this->avatarClass($thread->contact_email) }}" aria-hidden="true">{{ $this->initials($name, $thread->contact_email) }}</span>
            <div>
                <b>{{ $name }}</b>
                <span>{{ $thread->contact_email }}</span>
                <span class="zz-mail-chip">{{ $customer ? __('Customer') : __('Not a customer yet') }}</span>
            </div>
        </div>

        @if ($customer)
            @if ($contact['customerUrl'])
                <a class="zz-mail-linkrow" href="{{ $contact['customerUrl'] }}" target="_blank" rel="noopener">
                    <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />{{ __('Open customer') }}
                </a>
            @endif
            @if ($contact['phone'])
                <div class="zz-mail-kv">
                    <x-filament::icon icon="heroicon-o-phone" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />
                    <a href="{{ $contact['telUrl'] }}">{{ $contact['phone'] }}</a>
                </div>
            @endif
            @if ($contact['whatsappUrl'])
                <a class="zz-mail-linkrow" href="{{ $contact['whatsappUrl'] }}" target="_blank" rel="noopener noreferrer">
                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />{{ __('Open WhatsApp chat') }}
                </a>
            @endif
            @if ($contact['chatUrl'])
                <a class="zz-mail-linkrow" href="{{ $contact['chatUrl'] }}">
                    <x-filament::icon icon="heroicon-o-chat-bubble-oval-left" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />{{ __('Open chat in the CRM Inbox') }}
                </a>
            @endif

            <div class="zz-mail-tiles">
                <div class="zz-mail-tile"><b>{{ $contact['orderCount'] }}</b><span>{{ __('Orders') }}</span></div>
                <div class="zz-mail-tile"><b>{{ $contact['spent'] }}</b><span>{{ __('Total spent') }}</span></div>
                <div class="zz-mail-tile"><b>{{ $contact['due'] }}</b><span>{{ __('Balance') }}</span></div>
            </div>

            @if ($contact['orders']->isNotEmpty())
                <h4 class="zz-mail-cust-h">{{ __('Recent orders') }}</h4>
                <ul class="zz-mail-orders">
                    @foreach ($contact['orders'] as $order)
                        <li wire:key="cust-order-{{ $order->id }}">
                            <a href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $order]) }}" target="_blank" rel="noopener">
                                <span><b>{{ $order->order_number }}</b><small>{{ $order->order_date?->translatedFormat('M j, Y') }} · {{ __(\App\Models\Order::STATUSES[$order->status] ?? ucfirst((string) $order->status)) }}</small></span>
                                <span>{{ $this->money($order->total_amount) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @else
            <p class="zz-mail-hint">{{ __('This address is not one of your customers. You can still make a lead of it or tie the conversation to an order.') }}</p>
        @endif

        @if ($contact['order'])
            <div class="zz-mail-linked">
                <span>{{ __('Linked order') }}</span>
                <a href="{{ $contact['orderUrl'] }}" target="_blank" rel="noopener"><b>{{ $contact['order']->order_number }}</b></a>
                @if ($canManage)
                    <button type="button" class="zz-mail-chipx" wire:click="unlinkOrder" aria-label="{{ __('Unlink order') }}">×</button>
                @endif
            </div>
        @endif

        @if ($contact['lead'])
            <div class="zz-mail-linked">
                <span>{{ __('Lead') }}</span>
                <a href="{{ $contact['leadUrl'] }}" target="_blank" rel="noopener"><b>{{ $contact['lead']->name }}</b></a>
            </div>
        @endif

        @if ($canManage)
            <div class="zz-mail-cust-actions">
                <a class="zz-mail-btn2" href="{{ $contact['createOrderUrl'] }}" target="_blank" rel="noopener">
                    <x-filament::icon icon="heroicon-o-shopping-bag" class="zz-mail-i" aria-hidden="true" />{{ __('Create order') }}
                </a>
                @unless ($contact['lead'])
                    <button type="button" class="zz-mail-btn2" wire:click="startLead" aria-expanded="{{ $this->showLeadForm ? 'true' : 'false' }}">
                        <x-filament::icon icon="heroicon-o-user-plus" class="zz-mail-i" aria-hidden="true" />{{ __('Create lead') }}
                    </button>
                @endunless
                <button type="button" class="zz-mail-btn2" wire:click="$toggle('showOrderLink')" aria-expanded="{{ $this->showOrderLink ? 'true' : 'false' }}">
                    <x-filament::icon icon="heroicon-o-link" class="zz-mail-i" aria-hidden="true" />{{ __('Link to order') }}
                </button>
            </div>

            @if ($this->showLeadForm && ! $contact['lead'])
                <form class="zz-mail-orderlink" wire:submit="createLeadForThread" wire:key="lead-form">
                    <label for="zz-mail-lead-name">{{ __('Name') }}</label>
                    <input id="zz-mail-lead-name" type="text" wire:model="leadName" maxlength="190" autocomplete="off">
                    @error('leadName')<p class="zz-mail-err">{{ $message }}</p>@enderror
                    <label for="zz-mail-lead-phone">{{ __('Phone') }}</label>
                    <input id="zz-mail-lead-phone" type="tel" wire:model="leadPhone" maxlength="30" autocomplete="off" placeholder="01XXXXXXXXX">
                    @error('leadPhone')<p class="zz-mail-err">{{ $message }}</p>@enderror
                    <button type="submit" class="zz-mail-btn">{{ __('Create lead') }}</button>
                </form>
            @endif

            @if ($this->showOrderLink)
                <form class="zz-mail-orderlink" wire:submit="linkOrder" wire:key="order-link-form">
                    <label for="zz-mail-order-number">{{ __('Invoice number') }}</label>
                    <div>
                        <input id="zz-mail-order-number" type="text" wire:model="orderNumberInput" autocomplete="off" placeholder="INV-1001">
                        <button type="submit" class="zz-mail-btn">{{ __('Link') }}</button>
                    </div>
                </form>
            @endif
        @endif
    </div>
</aside>
