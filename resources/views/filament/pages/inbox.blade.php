@php
    $channels = $this->channels;
    $conversations = $this->conversations;
    $conversation = $this->selectedConversation;
    $selectedChannel = $channelId ? $channels->firstWhere('id', $channelId) : null;
    $providerIcons = [
        'whatsapp' => 'heroicon-o-device-phone-mobile',
        'messenger' => 'heroicon-o-chat-bubble-oval-left-ellipsis',
        'manual' => 'heroicon-o-pencil-square',
        'phone' => 'heroicon-o-phone',
    ];
    $statusColors = ['open' => 'success', 'pending' => 'warning', 'closed' => 'gray'];
    $deliveryLabels = [
        'pending' => 'Pending',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'read' => 'Read',
        'played' => 'Played',
        'failed' => 'Failed',
        'internal' => 'Internal note',
    ];
    $displayName = $conversation
        ? $conversation->profileDisplayName()
        : null;
    $canManage = $this->canManageConversations;
    $isSuperAdmin = auth()->user()?->isSuperAdmin() ?? false;
    $canCreateManualConversation = $canManage && app(\App\Services\CompanyContext::class)->company() !== null;
    $conversationCompany = $conversation?->company ?? $conversation?->channel?->company;
    $conversationTimezone = $conversationCompany?->timezone ?: config('app.timezone', 'UTC');
    $conversationCurrency = $conversationCompany?->currency ?: 'BDT';
    $isExternalConversation = $conversation && in_array($conversation->provider, ['whatsapp', 'messenger'], true);
    $replyWindowOpen = ! $isExternalConversation || $conversation->withinReplyWindow();
    $channelStatus = $conversation?->channel?->diagnosticStatus();
    $healthColor = match ($channelStatus) {
        'Inbound confirmed', 'Connected' => 'success',
        'Needs attention' => 'danger',
        'Configured', 'Verify callback', 'Subscribe app', 'Callback verified' => 'warning',
        default => 'gray',
    };

    // Modern chat-bubble skin (owner-approved design pass): a stable per-contact
    // avatar color and initials, and a delivery-status -> tick-icon mapping.
    // Purely presentational — no Livewire behavior below depends on these.
    $avatarPalette = ['#C1682F', '#3E7C6B', '#7A5C7E', '#4C6B8A'];
    $avatarColor = fn (int|string|null $seed): string => $avatarPalette[crc32((string) $seed) % count($avatarPalette)];
    $initials = function (?string $name): string {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        $letters = mb_substr($parts[0], 0, 1).(isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '');

        return mb_strtoupper($letters !== '' ? $letters : '?');
    };
    $deliveryTick = fn (?string $status): array => match ($status) {
        'sent' => ['mark' => 'single', 'tone' => 'muted'],
        'delivered' => ['mark' => 'double', 'tone' => 'muted'],
        'read', 'played' => ['mark' => 'double', 'tone' => 'ok'],
        'failed' => ['mark' => 'alert', 'tone' => 'danger'],
        default => ['mark' => null, 'tone' => 'muted'],
    };
@endphp

<x-filament-panels::page class="zz-inbox-page">
    <div
        data-zz-no-reload
        wire:poll.visible.8s="refreshInbox"
        class="zz-inbox"
        x-data="{
            observer: null,
            scrollAnchor: null,
            stickToBottom: true,
            bottomSyncTimers: [],
            desktopMediaQuery: null,
            desktopMediaHandler: null,
            desktopLayout: window.matchMedia('(min-width: 1280px)').matches,
            conversationRailCollapsed: (() => {
                try {
                    return window.localStorage.getItem('zz-inbox-conversations-collapsed') === 'true';
                } catch (error) {
                    return false;
                }
            })(),
            reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            railCollapsed() {
                return this.desktopLayout && this.conversationRailCollapsed;
            },
            conversationGridStyle() {
                if (! this.desktopLayout) return null;

                return this.railCollapsed()
                    ? 'grid-template-columns: 4.75rem minmax(0, 9fr) minmax(17rem, 3fr)'
                    : 'grid-template-columns: minmax(18rem, 4fr) minmax(0, 5fr) minmax(17rem, 3fr)';
            },
            toggleConversationRail() {
                this.conversationRailCollapsed = ! this.conversationRailCollapsed;

                try {
                    window.localStorage.setItem(
                        'zz-inbox-conversations-collapsed',
                        this.conversationRailCollapsed ? 'true' : 'false',
                    );
                } catch (error) {}

                this.$nextTick(() => this.queueBottomSync());
            },
            nearBottom() {
                const element = this.$refs.messageLog;
                return element ? element.scrollHeight - element.scrollTop - element.clientHeight < 160 : false;
            },
            scrollBottom(smooth = false) {
                const element = this.$refs.messageLog;
                if (! element) return;
                this.stickToBottom = true;
                element.scrollTo({
                    top: element.scrollHeight,
                    behavior: smooth && ! this.reducedMotion ? 'smooth' : 'auto',
                });
            },
            queueBottomSync() {
                this.bottomSyncTimers.forEach((timer) => window.clearTimeout(timer));
                this.bottomSyncTimers = [0, 80, 240, 600].map((delay) => window.setTimeout(() => {
                    if (this.stickToBottom) this.scrollBottom(false);
                }, delay));
            },
            forceBottomSync() {
                this.stickToBottom = true;
                this.queueBottomSync();
            },
            observeMessages() {
                this.observer?.disconnect();
                const element = this.$refs.messageLog;
                if (! element) return;
                this.observer = new MutationObserver(() => {
                    if (this.stickToBottom) this.scrollBottom(true);
                });
                this.observer.observe(element, { childList: true, subtree: true });
            },
            rememberScrollPosition() {
                const element = this.$refs.messageLog;
                this.scrollAnchor = element
                    ? { height: element.scrollHeight, top: element.scrollTop }
                    : null;
            },
            preserveScrollPosition() {
                const element = this.$refs.messageLog;
                if (! element || ! this.scrollAnchor) return;
                element.scrollTop = this.scrollAnchor.top + (element.scrollHeight - this.scrollAnchor.height);
                this.scrollAnchor = null;
            },
            autogrowComposer(event) {
                const element = event.target;
                if (! element) return;
                element.style.height = 'auto';
                element.style.height = Math.min(element.scrollHeight, 192) + 'px';
            },
            resetComposerHeight() {
                const element = this.$refs.replyTextarea;
                if (! element) return;
                element.style.height = '';
            },
            init() {
                this.desktopMediaQuery = window.matchMedia('(min-width: 1280px)');
                this.desktopMediaHandler = (event) => {
                    this.desktopLayout = event.matches;
                    this.$nextTick(() => this.queueBottomSync());
                };
                this.desktopMediaQuery.addEventListener('change', this.desktopMediaHandler);

                this.$nextTick(() => {
                    this.observeMessages();
                    this.queueBottomSync();
                });
            },
            destroy() {
                this.observer?.disconnect();
                this.bottomSyncTimers.forEach((timer) => window.clearTimeout(timer));
                this.desktopMediaQuery?.removeEventListener('change', this.desktopMediaHandler);
            },
        }"
        x-on:load.window="forceBottomSync()"
        x-on:pageshow.window="forceBottomSync()"
        x-on:livewire:navigated.window="$nextTick(() => forceBottomSync())"
        x-on:inbox-scroll-bottom.window="$nextTick(() => { observeMessages(); resetComposerHeight(); stickToBottom = true; queueBottomSync(); })"
        x-on:inbox-conversation-selected.window="$nextTick(() => { observeMessages(); resetComposerHeight(); $refs.threadHeading?.focus(); stickToBottom = true; queueBottomSync(); })"
        x-on:inbox-list-focused.window="$nextTick(() => $refs.conversationSearch?.focus())"
        x-on:inbox-preserve-scroll.window="$nextTick(() => preserveScrollPosition())"
    >
        @if ($selectedChannel?->last_error)
            <x-filament::callout
                color="danger"
                icon="heroicon-o-exclamation-triangle"
                heading="This channel needs attention"
                :description="$selectedChannel->last_error"
            >
                @if ($isSuperAdmin)
                    <x-slot:footer>
                        <x-filament::link
                            :href="\App\Filament\Resources\ConversationChannels\ConversationChannelResource::getUrl('edit', ['record' => $selectedChannel])"
                            icon="heroicon-o-wrench-screwdriver"
                        >
                            Open channel setup
                        </x-filament::link>
                    </x-slot:footer>
                @endif
            </x-filament::callout>
        @elseif ($selectedChannel && $selectedChannel->provider === 'whatsapp' && $selectedChannel->diagnosticStatus() !== 'Inbound confirmed')
            <x-filament::callout
                color="warning"
                icon="heroicon-o-exclamation-circle"
                heading="Inbound setup is not complete"
                :description="$selectedChannel->diagnosticStatus().'. Verify the callback, enable the messages webhook field in Meta, and run Test & Subscribe.'"
            />
        @endif

        <div
            class="grid h-[calc(100dvh-9rem)] min-h-0 grid-cols-1 gap-[5px] transition-[grid-template-columns] duration-200 motion-reduce:transition-none"
            x-bind:style="conversationGridStyle()"
        >
            <div
                @class([
                    'min-h-0 min-w-0',
                    'hidden xl:block' => $conversation,
                    'block' => ! $conversation,
                ])
            >
                <x-filament::section
                    x-cloak
                    x-bind:data-collapsed="railCollapsed().toString()"
                    class="flex h-full min-h-0 flex-col [&>.fi-section-content-ctn]:flex [&>.fi-section-content-ctn]:min-h-0 [&>.fi-section-content-ctn]:flex-1 [&>.fi-section-content-ctn>.fi-section-content]:flex [&>.fi-section-content-ctn>.fi-section-content]:min-h-0 [&>.fi-section-content-ctn>.fi-section-content]:flex-1 [&>.fi-section-content-ctn>.fi-section-content]:flex-col [&[data-collapsed=true]>.fi-section-header]:justify-center [&[data-collapsed=true]>.fi-section-header]:px-2 [&[data-collapsed=true]>.fi-section-header>.fi-icon]:hidden [&[data-collapsed=true]_.fi-section-header-text-ctn]:hidden [&[data-collapsed=true]_.fi-section-header-after-ctn]:ms-0 [&[data-collapsed=true]_.fi-section-content]:p-2"
                    icon="heroicon-o-chat-bubble-left-right"
                    heading="Conversations"
                    :description="number_format($conversations->total()).' matching conversations'"
                >
                    <x-slot:afterHeader>
                        <div class="flex items-center gap-1">
                            @if ($canCreateManualConversation)
                                <span x-show="! railCollapsed()">
                                    <x-filament::icon-button
                                        icon="heroicon-o-plus"
                                        wire:click="$toggle('showManualForm')"
                                        label="Log a manual conversation"
                                        color="gray"
                                        :aria-expanded="$showManualForm ? 'true' : 'false'"
                                        aria-controls="manual-conversation-form"
                                    />
                                </span>
                            @endif

                            <span class="hidden xl:inline-flex" x-show="! railCollapsed()">
                                <x-filament::icon-button
                                    icon="heroicon-o-chevron-left"
                                    color="gray"
                                    label="Collapse conversations"
                                    aria-controls="inbox-conversations-content"
                                    x-bind:aria-expanded="(! railCollapsed()).toString()"
                                    x-on:click="toggleConversationRail()"
                                />
                            </span>
                            <span class="hidden xl:inline-flex" x-show="railCollapsed()">
                                <x-filament::icon-button
                                    icon="heroicon-o-chevron-right"
                                    color="gray"
                                    label="Expand conversations"
                                    aria-controls="inbox-conversations-content"
                                    x-bind:aria-expanded="(! railCollapsed()).toString()"
                                    x-on:click="toggleConversationRail()"
                                />
                            </span>
                        </div>
                    </x-slot:afterHeader>

                    <div id="inbox-conversations-content" class="flex h-full min-h-0 flex-col gap-[5px]">
                        <div x-show="! railCollapsed()" class="space-y-[5px]">
                            <div>
                                <label for="inbox-search" class="sr-only">Search conversations</label>
                                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                                    <x-filament::input
                                        id="inbox-search"
                                        x-ref="conversationSearch"
                                        type="search"
                                        name="conversation_search"
                                        wire:model.live.debounce.350ms="search"
                                        placeholder="Search name, phone, or messages…"
                                        autocomplete="off"
                                    />
                                </x-filament::input.wrapper>
                            </div>

                            <div class="grid grid-cols-2 gap-[5px]">
                                <div>
                                    <label for="inbox-status" class="mb-1 block text-sm font-medium">Status</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select
                                            id="inbox-status"
                                            name="conversation_status"
                                            wire:change="setStatusFilter($event.target.value)"
                                        >
                                            <option value="open" @selected($statusFilter === 'open')>Open</option>
                                            <option value="pending" @selected($statusFilter === 'pending')>Pending</option>
                                            <option value="closed" @selected($statusFilter === 'closed')>Closed</option>
                                            <option value="all" @selected($statusFilter === 'all')>All statuses</option>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </div>

                                <div>
                                    <label for="inbox-assignment" class="mb-1 block text-sm font-medium">Assigned to</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select id="inbox-assignment" name="conversation_assignment" wire:model.live="assignedFilter">
                                            <option value="all">Anyone</option>
                                            <option value="mine">Me</option>
                                            <option value="unassigned">Unassigned</option>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </div>
                            </div>

                            <label class="flex cursor-pointer items-center gap-2 text-sm font-medium">
                                <x-filament::input.checkbox name="unread_only" wire:model.live="unreadOnly" />
                                Unread only
                            </label>

                            @if ($showManualForm && $canCreateManualConversation)
                                <form id="manual-conversation-form" wire:submit="createManualConversation">
                                    <x-filament::fieldset label="Log an offline conversation" class="space-y-3">
                                        <div>
                                            <label for="manual-name" class="mb-1 block text-sm font-medium">Contact name</label>
                                            <x-filament::input.wrapper :valid="! $errors->has('manualName')">
                                                <x-filament::input
                                                    id="manual-name"
                                                    type="text"
                                                    name="manual_name"
                                                    wire:model="manualName"
                                                    maxlength="120"
                                                    autocomplete="name"
                                                    :aria-describedby="$errors->has('manualName') ? 'manual-name-error' : null"
                                                    :aria-invalid="$errors->has('manualName') ? 'true' : 'false'"
                                                    required
                                                />
                                            </x-filament::input.wrapper>
                                            @error('manualName')
                                                <p id="manual-name-error" class="mt-1 text-sm text-danger-600" role="alert">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="manual-phone" class="mb-1 block text-sm font-medium">Phone number</label>
                                            <x-filament::input.wrapper :valid="! $errors->has('manualPhone')">
                                                <x-filament::input
                                                    id="manual-phone"
                                                    type="tel"
                                                    name="manual_phone"
                                                    wire:model="manualPhone"
                                                    maxlength="40"
                                                    autocomplete="tel"
                                                    :aria-describedby="$errors->has('manualPhone') ? 'manual-phone-error' : null"
                                                    :aria-invalid="$errors->has('manualPhone') ? 'true' : 'false'"
                                                    required
                                                />
                                            </x-filament::input.wrapper>
                                            @error('manualPhone')
                                                <p id="manual-phone-error" class="mt-1 text-sm text-danger-600" role="alert">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="flex justify-end gap-2">
                                            <x-filament::button
                                                type="button"
                                                color="gray"
                                                wire:click="$set('showManualForm', false)"
                                            >
                                                Cancel
                                            </x-filament::button>
                                            <x-filament::button type="submit" icon="heroicon-o-pencil-square">
                                                Create
                                            </x-filament::button>
                                        </div>
                                    </x-filament::fieldset>
                                </form>
                            @endif
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto pe-1" wire:loading.class="opacity-60" wire:target="search,setStatusFilter,assignedFilter,unreadOnly,setChannelFilter">
                            <ul class="space-y-[5px]" aria-label="Conversation list">
                                @forelse ($conversations as $item)
                                    @php
                                        $itemName = $item->profileDisplayName();
                                        $preview = $item->latestMessage
                                            ? \Illuminate\Support\Str::limit((string) ($item->latestMessage->body ?: ucfirst($item->latestMessage->type)), 72)
                                            : 'No messages yet';
                                        $isSelected = $selectedConversationId === $item->getKey();
                                        $itemCompany = $item->company ?? $item->channel?->company;
                                        $itemLastMessageAt = $item->last_message_at?->copy()->timezone($itemCompany?->timezone ?: config('app.timezone', 'UTC'));
                                    @endphp
                                    <li wire:key="conversation-{{ $item->getKey() }}">
                                        <div x-show="! railCollapsed()">
                                            <x-filament::button
                                                class="zz-inbox-conversation-card w-full justify-start text-left"
                                                :color="$isSelected ? 'primary' : 'gray'"
                                                :outlined="! $isSelected"
                                                wire:click="selectConversation({{ $item->getKey() }})"
                                                :aria-current="$isSelected ? 'true' : 'false'"
                                            >
                                                <span class="flex w-full min-w-0 items-start gap-[5px]">
                                                    <span
                                                        class="zz-avatar flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
                                                        style="background-color: {{ $avatarColor($item->getKey()) }}"
                                                        aria-hidden="true"
                                                    >{{ $initials($itemName) }}</span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="flex items-start justify-between gap-2">
                                                            <span class="truncate font-semibold">{{ $itemName }}</span>
                                                            @if ($itemLastMessageAt)
                                                                <time
                                                                    class="shrink-0 text-xs opacity-70"
                                                                    datetime="{{ $itemLastMessageAt->toIso8601String() }}"
                                                                    title="{{ $itemLastMessageAt->format('d M Y, H:i T') }}"
                                                                >
                                                                    {{ $itemLastMessageAt->diffForHumans(short: true) }}
                                                                </time>
                                                            @endif
                                                        </span>
                                                        <span class="mt-1 flex items-center justify-between gap-2">
                                                            <span class="truncate text-xs opacity-75">
                                                                @if ($item->latestMessage?->direction === 'outgoing')You: @endif{{ $preview }}
                                                            </span>
                                                            @if ($item->unread_count > 0)
                                                                <x-filament::badge color="danger" size="sm">
                                                                    <span class="sr-only">Unread messages:</span>{{ $item->unread_count }}
                                                                </x-filament::badge>
                                                            @endif
                                                        </span>
                                                        <span class="mt-2 flex flex-wrap items-center gap-1">
                                                            <x-filament::badge color="gray" size="sm">
                                                                {{ $item->channel?->display_name ?: (\App\Models\Conversation::PROVIDERS[$item->provider] ?? ucfirst($item->provider)) }}
                                                            </x-filament::badge>
                                                            <x-filament::badge :color="$statusColors[$item->status] ?? 'gray'" size="sm">
                                                                {{ \App\Models\Conversation::STATUSES[$item->status] ?? ucfirst($item->status) }}
                                                            </x-filament::badge>
                                                            @if ($channelId === null && $itemCompany)
                                                                <x-filament::badge color="info" size="sm">
                                                                    {{ $itemCompany->name }}
                                                                </x-filament::badge>
                                                            @endif
                                                            @if ($item->assignedUser)
                                                                <span class="truncate text-xs opacity-70">{{ $item->assignedUser->name }}</span>
                                                            @endif
                                                        </span>
                                                    </span>
                                                </span>
                                            </x-filament::button>
                                        </div>

                                        <div x-cloak x-show="railCollapsed()" class="flex justify-center py-1">
                                            <x-filament::icon-button
                                                data-conversation-avatar
                                                icon="heroicon-o-user-circle"
                                                size="lg"
                                                :color="$isSelected ? 'primary' : 'gray'"
                                                :badge="$item->unread_count ?: null"
                                                badge-color="danger"
                                                :label="'Open conversation with '.$itemName"
                                                :tooltip="$itemName"
                                                wire:click="selectConversation({{ $item->getKey() }})"
                                                :aria-current="$isSelected ? 'true' : 'false'"
                                            />
                                        </div>
                                    </li>
                                @empty
                                    <li>
                                        <div x-show="! railCollapsed()">
                                            <x-filament::empty-state
                                                compact
                                                icon="heroicon-o-chat-bubble-left-ellipsis"
                                                heading="No conversations found"
                                                description="Try another channel, status, assignment, or search."
                                            />
                                        </div>
                                        <div x-cloak x-show="railCollapsed()" class="flex justify-center py-2">
                                            <x-filament::icon
                                                icon="heroicon-o-user-circle"
                                                class="h-7 w-7 text-gray-400 dark:text-gray-500"
                                                aria-label="No conversations found"
                                            />
                                        </div>
                                    </li>
                                @endforelse
                            </ul>
                        </div>

                        @if ($conversations->hasMorePages())
                            <div x-show="! railCollapsed()">
                                <x-filament::button
                                    color="gray"
                                    outlined
                                    icon="heroicon-o-arrow-down"
                                    wire:click="loadMoreConversations"
                                    wire:target="loadMoreConversations"
                                    wire:loading.attr="disabled"
                                >
                                    Load more conversations
                                </x-filament::button>
                            </div>
                        @endif
                    </div>
                </x-filament::section>
            </div>

            <div
                @class([
                    'min-h-0 min-w-0',
                    'block' => $conversation,
                    'hidden xl:block' => ! $conversation,
                ])
            >
                @if ($conversation)
                    <x-filament::section
                        class="flex h-full min-h-0 flex-col [&>.fi-section-content-ctn]:flex [&>.fi-section-content-ctn]:min-h-0 [&>.fi-section-content-ctn]:flex-1 [&>.fi-section-content-ctn>.fi-section-content]:flex [&>.fi-section-content-ctn>.fi-section-content]:min-h-0 [&>.fi-section-content-ctn>.fi-section-content]:flex-1 [&>.fi-section-content-ctn>.fi-section-content]:flex-col"
                        :icon="$providerIcons[$conversation->provider] ?? 'heroicon-o-user-circle'"
                        :heading="$displayName"
                        :description="($conversation->channel?->display_name ?: (\App\Models\Conversation::PROVIDERS[$conversation->provider] ?? ucfirst($conversation->provider))).($conversation->contact_phone ? ' · '.$conversation->contact_phone : '')"
                    >
                        <x-slot:afterHeader>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <x-filament::icon-button
                                    class="xl:hidden"
                                    icon="heroicon-o-arrow-left"
                                    wire:click="deselectConversation"
                                    label="Back to conversations"
                                    color="gray"
                                />
                                <x-filament::badge :color="$statusColors[$conversation->status] ?? 'gray'">
                                    {{ \App\Models\Conversation::STATUSES[$conversation->status] ?? ucfirst($conversation->status) }}
                                </x-filament::badge>
                                @if ($isExternalConversation)
                                    <x-filament::badge :color="$replyWindowOpen ? 'success' : 'danger'">
                                        {{ $replyWindowOpen ? $conversation->replyWindowHoursLeft().'h reply window' : 'Reply window closed' }}
                                    </x-filament::badge>
                                @endif
                            </div>
                        </x-slot:afterHeader>

                        <h2 x-ref="threadHeading" tabindex="-1" class="sr-only">Conversation with {{ $displayName }}</h2>

                        <x-filament::section
                            class="mb-3 xl:hidden"
                            compact
                            collapsible
                            collapsed
                            :collapse-id="'mobile-conversation-tools-'.$conversation->getKey()"
                            icon="heroicon-o-adjustments-horizontal"
                            heading="Conversation Tools"
                            :description="$conversation->assignedUser?->name ? 'Owned by '.$conversation->assignedUser->name : 'Unassigned'"
                        >
                            <div class="space-y-[5px]">
                                <dl class="grid grid-cols-1 gap-[5px] text-sm sm:grid-cols-3">
                                    @if ($conversation->contact_phone)
                                        <div class="min-w-0">
                                            <dt class="text-gray-500 dark:text-gray-400">Phone</dt>
                                            <dd class="truncate font-medium">{{ $conversation->contact_phone }}</dd>
                                        </div>
                                    @endif
                                    <div class="min-w-0">
                                        <dt class="text-gray-500 dark:text-gray-400">Channel</dt>
                                        <dd class="truncate font-medium">
                                            {{ $conversation->channel?->display_name ?: (\App\Models\Conversation::PROVIDERS[$conversation->provider] ?? ucfirst($conversation->provider)) }}
                                        </dd>
                                    </div>
                                    @if ($conversationCompany)
                                        <div class="min-w-0">
                                            <dt class="text-gray-500 dark:text-gray-400">Company</dt>
                                            <dd class="truncate font-medium">{{ $conversationCompany->name }}</dd>
                                        </div>
                                    @endif
                                </dl>

                                @if ($canManage)
                                    <div class="space-y-2">
                                        <h3 class="text-sm font-semibold">Assignment & Read State</h3>
                                        <div class="flex flex-wrap gap-2">
                                            @if ($conversation->assigned_to === auth()->id())
                                                <x-filament::button size="sm" color="gray" outlined wire:click="unassignConversation">
                                                    Unassign
                                                </x-filament::button>
                                            @else
                                                <x-filament::button size="sm" icon="heroicon-o-user-plus" wire:click="assignToMe">
                                                    Assign to Me
                                                </x-filament::button>
                                            @endif
                                            <x-filament::button size="sm" color="gray" outlined wire:click="markConversationUnread">
                                                Mark Unread
                                            </x-filament::button>
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <h3 class="text-sm font-semibold">Conversation State</h3>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach (\App\Models\Conversation::STATUSES as $key => $label)
                                                <x-filament::button
                                                    size="sm"
                                                    :color="$conversation->status === $key ? ($statusColors[$key] ?? 'primary') : 'gray'"
                                                    :outlined="$conversation->status !== $key"
                                                    wire:click="setConversationStatus('{{ $key }}')"
                                                >
                                                    {{ $label }}
                                                </x-filament::button>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <h3 class="text-sm font-semibold">AI Assistant</h3>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ $conversation->ai_enabled ? 'Enabled' : 'Paused' }}
                                            </p>
                                        </div>
                                        <x-filament::toggle
                                            wire:key="mobile-ai-toggle-{{ $conversation->getKey() }}"
                                            :state="$conversation->ai_enabled ? 'true' : 'false'"
                                            wire:click="toggleAi"
                                            aria-label="Toggle AI assistant"
                                            on-icon="heroicon-m-check"
                                        />
                                    </div>
                                @endif

                                @if ($conversation->channel)
                                    <div class="space-y-2 border-t border-gray-200 pt-3 dark:border-white/10">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <h3 class="text-sm font-semibold">Channel Health</h3>
                                            <x-filament::badge :color="$healthColor">{{ $channelStatus }}</x-filament::badge>
                                        </div>
                                        @if ($conversation->channel->last_error)
                                            <p class="break-words text-sm text-danger-600">{{ $conversation->channel->last_error }}</p>
                                        @elseif ($conversation->channel->last_inbound_at)
                                            @php
                                                $mobileLastInboundAt = $conversation->channel->last_inbound_at->copy()->timezone($conversationTimezone);
                                            @endphp
                                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                                Last inbound
                                                <time
                                                    datetime="{{ $mobileLastInboundAt->toIso8601String() }}"
                                                    aria-label="{{ $mobileLastInboundAt->format('d M Y, H:i:s T') }}"
                                                    title="{{ $mobileLastInboundAt->format('d M Y, H:i:s T') }}"
                                                >
                                                    {{ $mobileLastInboundAt->diffForHumans() }}
                                                </time>
                                            </p>
                                        @endif
                                        @if ($isSuperAdmin)
                                            <x-filament::link
                                                :href="\App\Filament\Resources\ConversationChannels\ConversationChannelResource::getUrl('edit', ['record' => $conversation->channel])"
                                                icon="heroicon-o-cog-6-tooth"
                                            >
                                                Channel Settings
                                            </x-filament::link>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </x-filament::section>

                        <div
                            x-ref="messageLog"
                            role="log"
                            aria-live="polite"
                            aria-relevant="additions text"
                            aria-label="Messages with {{ $displayName }}"
                            x-on:scroll.passive="stickToBottom = nearBottom()"
                            class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto pe-1"
                        >
                            <div
                                @class([
                                    'flex min-h-full min-w-0 flex-col gap-[5px]',
                                    'justify-center' => $conversation->messages->isEmpty(),
                                    'justify-end' => $conversation->messages->isNotEmpty(),
                                ])
                            >
                            @if ($conversation->hasOlderMessages && $messageLimit < 500)
                                <div class="flex justify-center">
                                    <x-filament::button
                                        color="gray"
                                        outlined
                                        size="sm"
                                        icon="heroicon-o-arrow-up"
                                        wire:click="loadOlderMessages"
                                        x-on:click="rememberScrollPosition()"
                                    >
                                        Load older messages
                                    </x-filament::button>
                                </div>
                            @elseif ($conversation->hasOlderMessages)
                                <x-filament::callout
                                    color="gray"
                                    icon="heroicon-o-information-circle"
                                    heading="Showing the latest 500 messages"
                                    description="Use the permanent conversation archive for older history."
                                />
                            @endif

                            @forelse ($conversation->messages as $message)
                                @php
                                    $previous = $loop->first ? null : $conversation->messages[$loop->index - 1];
                                    $next = $loop->last ? null : $conversation->messages[$loop->index + 1];
                                    $messageAt = $message->sent_at->copy()->timezone($conversationTimezone);
                                    $previousAt = $previous?->sent_at?->copy()->timezone($conversationTimezone);
                                    $nextAt = $next?->sent_at?->copy()->timezone($conversationTimezone);
                                    $showDay = ! $previousAt || ! $messageAt->isSameDay($previousAt);
                                    $dayEndsAfterThis = ! $nextAt || ! $messageAt->isSameDay($nextAt);
                                    $messageDayLabel = $messageAt->isToday() ? 'Today' : ($messageAt->isYesterday() ? 'Yesterday' : $messageAt->format('d M Y'));
                                    $isNote = $message->type === 'note';
                                    $groupedWithPrevious = ! $showDay && $previous && ! $isNote && $previous->type !== 'note' && $previous->direction === $message->direction;
                                    $groupedWithNext = ! $dayEndsAfterThis && $next && ! $isNote && $next->type !== 'note' && $next->direction === $message->direction;
                                @endphp

                                @if ($showDay)
                                    <div
                                        class="flex items-center justify-center"
                                        role="separator"
                                        aria-label="Messages from {{ $messageAt->format('d F Y') }}"
                                    >
                                        <x-filament::badge color="gray">
                                            {{ $messageDayLabel }}
                                        </x-filament::badge>
                                    </div>
                                @endif

                                <article
                                    wire:key="message-{{ $message->getKey() }}"
                                    @class([
                                        'zz-msg-row flex w-full min-w-0 [content-visibility:auto] [contain-intrinsic-size:auto_8rem]',
                                        'justify-end' => $message->direction === 'outgoing',
                                        'justify-start' => $message->direction !== 'outgoing',
                                        'zz-msg-tight' => $groupedWithPrevious,
                                        'zz-msg-start' => ! $groupedWithPrevious,
                                    ])
                                    aria-label="{{ $message->direction === 'outgoing' ? 'Outgoing' : 'Incoming' }} {{ \App\Models\ConversationMessage::TYPES[$message->type] ?? $message->type }} message"
                                >
                                    <div
                                        @class([
                                            'zz-bubble w-fit min-w-0 max-w-[88%] overflow-hidden sm:max-w-[82%] px-3 py-2',
                                            'zz-bubble-note' => $isNote,
                                            'zz-bubble-out' => ! $isNote && $message->direction === 'outgoing',
                                            'zz-bubble-in' => ! $isNote && $message->direction !== 'outgoing',
                                            'zz-bubble-tail-out' => ! $isNote && $message->direction === 'outgoing' && ! $groupedWithNext,
                                            'zz-bubble-tail-in' => ! $isNote && $message->direction !== 'outgoing' && ! $groupedWithNext,
                                        ])
                                    >
                                        @if ($isNote)
                                            <p class="zz-bubble-note-label">
                                                <x-filament::icon icon="heroicon-o-document-text" class="h-3.5 w-3.5" />
                                                Internal note
                                            </p>
                                        @endif

                                        <div class="min-w-0 space-y-2 overflow-hidden">
                                            @if ($imageUrl = $message->mediaImageUrl())
                                                <a
                                                    href="{{ $imageUrl }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    @class([
                                                        'block max-w-full overflow-hidden rounded-lg',
                                                        'w-48' => $message->type === 'order_form',
                                                        'w-fit' => $message->type !== 'order_form',
                                                    ])
                                                >
                                                    <img
                                                        src="{{ $imageUrl }}"
                                                        alt="{{ $message->type === 'order_form' ? 'Product image' : 'Image attached to this message' }}"
                                                        width="{{ $message->type === 'order_form' ? 192 : 480 }}"
                                                        height="{{ $message->type === 'order_form' ? 128 : 320 }}"
                                                        loading="lazy"
                                                        x-on:load="if (stickToBottom) $nextTick(() => scrollBottom())"
                                                        @if ($message->type === 'order_form') data-product-thumbnail @endif
                                                        @class([
                                                            'max-w-full rounded-lg',
                                                            'h-32 w-48 object-cover' => $message->type === 'order_form',
                                                            'h-auto max-h-72 w-auto object-contain' => $message->type !== 'order_form',
                                                        ])
                                                    >
                                                </a>
                                            @elseif ($mediaUrl = $message->mediaDownloadUrl())
                                                @if ($message->type === 'video' || str_starts_with((string) $message->media_mime, 'video/'))
                                                    <video
                                                        controls
                                                        playsinline
                                                        preload="metadata"
                                                        src="{{ $mediaUrl }}"
                                                        class="h-auto max-h-72 w-full max-w-full rounded-lg"
                                                        aria-label="Video attached to this message"
                                                    ></video>
                                                @elseif ($message->type === 'audio' || str_starts_with((string) $message->media_mime, 'audio/'))
                                                    <audio
                                                        controls
                                                        preload="none"
                                                        src="{{ $mediaUrl }}"
                                                        class="w-64 max-w-full"
                                                        aria-label="Audio attached to this message"
                                                    ></audio>
                                                @else
                                                    <x-filament::link
                                                        :href="$mediaUrl"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        icon="heroicon-o-paper-clip"
                                                    >
                                                        Open attachment
                                                    </x-filament::link>
                                                @endif
                                            @elseif (in_array($message->type, ['image', 'video', 'audio', 'document', 'sticker'], true) && data_get($message->raw_payload, 'source') === 'whatsapp_web')
                                                <p class="text-xs italic opacity-70">{{ __('The file is not saved (yet). WhatsApp may no longer share very old media.') }}</p>
                                            @endif

                                            @if (filled($message->body))
                                                <p data-message-body data-message-wrap="anywhere" class="min-w-0 whitespace-pre-wrap break-words text-sm [overflow-wrap:anywhere] [&_a]:break-all">{!! $message->bodyHtml() !!}</p>
                                            @endif

                                            <div class="flex flex-wrap items-center justify-end gap-2 text-xs opacity-80">
                                                @if ($message->revoked_at)
                                                    <span class="inline-flex items-center gap-1" title="{{ __('Deleted for everyone on WhatsApp - the ERP keeps the original copy') }}">
                                                        <x-filament::icon icon="heroicon-o-trash" class="h-3 w-3" />
                                                        {{ __('Deleted on WhatsApp') }}
                                                    </span>
                                                @endif
                                                @if ($message->generated_by === 'ai')
                                                    <span>AI assistant</span>
                                                @elseif ($message->generated_by === 'page')
                                                    Page / Business Suite
                                                @elseif ($message->generated_by === 'phone_app')
                                                    <span class="inline-flex items-center gap-1" title="Sent from the WhatsApp Business App on the phone, synced in via Coexistence">
                                                        <x-filament::icon icon="heroicon-o-device-phone-mobile" class="h-3 w-3" />
                                                        Phone app
                                                    </span>
                                                @elseif ($message->sender)
                                                    <span>{{ $message->sender->name }}</span>
                                                @endif
                                                <time
                                                    datetime="{{ $messageAt->toIso8601String() }}"
                                                    aria-label="{{ $messageAt->format('d M Y, H:i:s T') }}"
                                                    title="{{ $messageAt->format('d M Y, H:i:s T') }}"
                                                >
                                                    {{ $messageAt->format('H:i') }}
                                                </time>
                                                @if ($message->direction === 'outgoing')
                                                    @php $tick = $deliveryTick($message->delivery_status); @endphp
                                                    <span class="sr-only">{{ $deliveryLabels[$message->delivery_status] ?? ucfirst($message->delivery_status ?: 'pending') }}</span>
                                                    @if ($tick['mark'])
                                                        <span class="zz-tick zz-tick-{{ $tick['tone'] }}" aria-hidden="true">
                                                            @if ($tick['mark'] === 'single')
                                                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9l4 4L14 3"/></svg>
                                                            @elseif ($tick['mark'] === 'double')
                                                                <svg viewBox="0 0 28 16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1 8l4 4L14 2"/><path d="M8 8l4 4L27 2"/></svg>
                                                            @else
                                                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v4M8 11h.01"/></svg>
                                                            @endif
                                                        </span>
                                                    @endif
                                                    @if ($message->delivery_channel === 'sms')
                                                        <span class="zz-fallback-badge">
                                                            <x-filament::icon icon="heroicon-o-device-phone-mobile" class="h-3 w-3" />
                                                            via SMS
                                                        </span>
                                                    @endif
                                                @endif
                                            </div>

                                            @if ($message->delivery_status === 'failed')
                                                <div class="flex flex-wrap items-center justify-end gap-2">
                                                    @if ($errorMessage = data_get($message->raw_payload, 'error.message'))
                                                        <span class="text-xs" role="alert">{{ $errorMessage }}</span>
                                                    @endif
                                                    @if ($canManage)
                                                        <x-filament::button
                                                            color="danger"
                                                            outlined
                                                            size="sm"
                                                            icon="heroicon-o-arrow-path"
                                                            wire:click="retryMessage({{ $message->getKey() }})"
                                                        >
                                                            Retry
                                                        </x-filament::button>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </article>
                            @empty
                                <x-filament::empty-state
                                    icon="heroicon-o-chat-bubble-left-ellipsis"
                                    heading="No messages yet"
                                    description="Incoming messages and replies will appear here."
                                />
                            @endforelse
                            </div>
                        </div>

                        @if ($canManage)
                            <div class="mt-[5px] space-y-[5px] border-t border-gray-200 pt-[5px] dark:border-white/10">
                            @if ($isExternalConversation && ! $replyWindowOpen)
                                <x-filament::callout
                                    color="warning"
                                    icon="heroicon-o-clock"
                                    heading="The free-form reply window is closed"
                                    description="WhatsApp only accepts an approved template now. You can still add an internal note to this conversation."
                                />
                            @endif

                            @if ($isExternalConversation)
                                <x-filament::tabs contained label="Composer mode">
                                    <x-filament::tabs.item
                                        :active="$composerMode === 'reply'"
                                        icon="heroicon-o-paper-airplane"
                                        wire:click="$set('composerMode', 'reply')"
                                    >
                                        Reply
                                    </x-filament::tabs.item>
                                    <x-filament::tabs.item
                                        :active="$composerMode === 'note'"
                                        icon="heroicon-o-document-text"
                                        wire:click="$set('composerMode', 'note')"
                                    >
                                        Internal note
                                    </x-filament::tabs.item>
                                </x-filament::tabs>
                            @else
                                <x-filament::badge color="gray" icon="heroicon-o-document-text">Internal activity</x-filament::badge>
                            @endif

                            @if ($showCatalogPanel && $composerMode === 'reply')
                                <x-filament::fieldset id="inbox-catalog-panel" label="Send a catalog order link" class="space-y-3">
                                    <div>
                                        <label for="product-search" class="mb-1 block text-sm font-medium">Find a product</label>
                                        <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                                            <x-filament::input
                                                id="product-search"
                                                type="search"
                                                name="product_search"
                                                wire:model.live.debounce.300ms="productSearch"
                                                placeholder="Product name or SKU…"
                                                autocomplete="off"
                                            />
                                        </x-filament::input.wrapper>
                                    </div>

                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                                        <div class="sm:col-span-3">
                                            <label for="order-product" class="mb-1 block text-sm font-medium">Product</label>
                                            <x-filament::input.wrapper :valid="! $errors->has('orderFormProductId')">
                                                <x-filament::input.select
                                                    id="order-product"
                                                    name="order_product"
                                                    wire:model.live="orderFormProductId"
                                                    :aria-describedby="$errors->has('orderFormProductId') ? 'order-product-error' : null"
                                                    :aria-invalid="$errors->has('orderFormProductId') ? 'true' : 'false'"
                                                >
                                                    <option value="">Select a product…</option>
                                                    @foreach ($this->products as $product)
                                                        <option value="{{ $product->getKey() }}">
                                                            {{ $product->name }} — {{ \App\Support\MoneyFormatter::currency((float) $product->sale_price, $conversationCurrency) }}
                                                        </option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                            @error('orderFormProductId')
                                                <p id="order-product-error" class="mt-1 text-sm text-danger-600" role="alert">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div>
                                            <label for="order-quantity" class="mb-1 block text-sm font-medium">Quantity</label>
                                            <x-filament::input.wrapper :valid="! $errors->has('orderFormQuantity')">
                                                <x-filament::input
                                                    id="order-quantity"
                                                    type="number"
                                                    name="order_quantity"
                                                    min="1"
                                                    max="999"
                                                    inputmode="numeric"
                                                    wire:model="orderFormQuantity"
                                                    :aria-describedby="$errors->has('orderFormQuantity') ? 'order-quantity-error' : null"
                                                    :aria-invalid="$errors->has('orderFormQuantity') ? 'true' : 'false'"
                                                />
                                            </x-filament::input.wrapper>
                                            @error('orderFormQuantity')
                                                <p id="order-quantity-error" class="mt-1 text-sm text-danger-600" role="alert">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    @if ($selectedProduct = $this->selectedProduct)
                                        <x-filament::callout
                                            color="info"
                                            icon="heroicon-o-shopping-bag"
                                            :heading="$selectedProduct->name"
                                            :description="\App\Support\MoneyFormatter::currency((float) $selectedProduct->sale_price, $conversationCurrency).' � A secure order link will be added to the thread.'"
                                        />
                                    @endif

                                    <div class="flex justify-end gap-2">
                                        <x-filament::button
                                            type="button"
                                            color="gray"
                                            wire:click="$set('showCatalogPanel', false)"
                                        >
                                            Cancel
                                        </x-filament::button>
                                        <x-filament::button
                                            type="button"
                                            color="success"
                                            icon="heroicon-o-link"
                                            wire:click="sendOrderForm"
                                            :disabled="! $replyWindowOpen"
                                        >
                                            {{ $isExternalConversation ? 'Send Order Link' : 'Add Order Link' }}
                                        </x-filament::button>
                                    </div>
                                </x-filament::fieldset>
                            @endif

                            @if ($showQuickReplyPanel && $composerMode === 'reply')
                                <x-filament::fieldset id="inbox-quick-reply-panel" label="Insert a quick reply" class="space-y-2">
                                    @forelse ($this->quickReplies as $quickReply)
                                        <button
                                            type="button"
                                            wire:click="insertQuickReply({{ $quickReply->getKey() }})"
                                            class="block w-full rounded-lg border border-gray-200 p-[5px] text-left text-sm hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5"
                                        >
                                            <span class="block font-medium">{{ $quickReply->shortcut }}</span>
                                            <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $quickReply->body }}</span>
                                        </button>
                                    @empty
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            No quick replies saved yet. Add some from CRM → Quick Replies.
                                        </p>
                                    @endforelse
                                </x-filament::fieldset>
                            @endif

                            <form wire:submit="sendReply" class="zz-inbox-composer space-y-[5px]">
                                <label for="reply-body" class="sr-only">
                                    {{ $composerMode === 'note' || ! $isExternalConversation ? 'Internal note' : 'Message reply' }}
                                </label>
                                <x-filament::input.wrapper
                                    :valid="! $errors->has('replyBody')"
                                    :prefix-icon="$composerMode === 'note' || ! $isExternalConversation ? 'heroicon-o-document-text' : 'heroicon-o-chat-bubble-left-ellipsis'"
                                >
                                    <textarea
                                        id="reply-body"
                                        name="message"
                                        wire:model="replyBody"
                                        rows="1"
                                        maxlength="4096"
                                        autocomplete="off"
                                        aria-describedby="reply-help @error('replyBody') reply-error @enderror"
                                        aria-invalid="{{ $errors->has('replyBody') ? 'true' : 'false' }}"
                                        placeholder="{{ $composerMode === 'note' || ! $isExternalConversation ? 'Add a private note for your team…' : 'Write a reply…' }}"
                                        @disabled($composerMode === 'reply' && ! $replyWindowOpen)
                                        x-ref="replyTextarea"
                                        x-on:input="autogrowComposer($event)"
                                        x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); $el.form.requestSubmit(); }"
                                        class="block max-h-48 min-h-[40px] w-full resize-none overflow-y-auto border-0 bg-transparent px-[5px] py-[9px] text-sm leading-5 disabled:cursor-not-allowed disabled:opacity-60"
                                    ></textarea>
                                </x-filament::input.wrapper>
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p id="reply-help" class="text-xs text-gray-500 dark:text-gray-400">
                                        Enter to send · Shift+Enter for a new line
                                    </p>
                                    <div class="flex items-center gap-2">
                                        @if ($composerMode === 'reply' && $replyWindowOpen)
                                            <x-filament::icon-button
                                                icon="heroicon-o-shopping-bag"
                                                wire:click="$toggle('showCatalogPanel')"
                                                :label="$isExternalConversation ? 'Open product catalog' : 'Add an internal order link'"
                                                color="gray"
                                                :aria-expanded="$showCatalogPanel ? 'true' : 'false'"
                                                aria-controls="inbox-catalog-panel"
                                            />
                                            <x-filament::icon-button
                                                icon="heroicon-o-bookmark"
                                                wire:click="$toggle('showQuickReplyPanel')"
                                                label="Insert a quick reply"
                                                color="gray"
                                                :aria-expanded="$showQuickReplyPanel ? 'true' : 'false'"
                                                aria-controls="inbox-quick-reply-panel"
                                            />
                                        @endif
                                        @if ($composerMode === 'note' || ! $isExternalConversation)
                                            <x-filament::icon-button
                                                type="submit"
                                                icon="heroicon-o-document-plus"
                                                color="primary"
                                                label="Add note"
                                                wire:target="sendReply"
                                            />
                                        @else
                                            <x-filament::button
                                                type="submit"
                                                icon="heroicon-o-paper-airplane"
                                                color="primary"
                                                :disabled="! $replyWindowOpen"
                                                wire:target="sendReply"
                                            >
                                                Send
                                            </x-filament::button>
                                        @endif
                                    </div>
                                </div>
                                @error('replyBody')
                                    <p id="reply-error" class="text-sm text-danger-600" role="alert">{{ $message }}</p>
                                @enderror
                            </form>
                            </div>
                        @else
                            <x-filament::callout
                                class="mt-4"
                                color="gray"
                                icon="heroicon-o-eye"
                                heading="Read-only inbox access"
                                description="You can review this conversation, but your role cannot reply or change its workflow state."
                            />
                        @endif
                    </x-filament::section>
                @else
                    <x-filament::section class="h-full">
                        <x-filament::empty-state
                            icon="heroicon-o-cursor-arrow-rays"
                            heading="Select a conversation"
                            description="Choose a chat from the list to read messages, reply, assign an owner, or add a note."
                        />
                    </x-filament::section>
                @endif
            </div>

            <aside class="hidden min-h-0 min-w-0 xl:block" aria-label="Conversation details">
                @if ($conversation)
                    <div class="h-full space-y-[5px] overflow-y-auto pe-1">
                        <x-filament::section
                            compact
                            icon="heroicon-o-user-circle"
                            heading="Contact"
                            :description="$displayName"
                        >
                            <dl class="space-y-3 text-sm">
                                @if ($conversation->contact_phone)
                                    <div>
                                        <dt class="text-gray-500 dark:text-gray-400">Phone</dt>
                                        <dd class="font-medium">{{ $conversation->contact_phone }}</dd>
                                    </div>
                                @endif
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Channel</dt>
                                    <dd class="font-medium">
                                        {{ $conversation->channel?->display_name ?: (\App\Models\Conversation::PROVIDERS[$conversation->provider] ?? ucfirst($conversation->provider)) }}
                                    </dd>
                                </div>
                                @if ($conversationCompany)
                                    <div>
                                        <dt class="text-gray-500 dark:text-gray-400">Company</dt>
                                        <dd class="font-medium">{{ $conversationCompany->name }}</dd>
                                    </div>
                                @endif
                                @if ($conversation->customer)
                                    <div>
                                        <dt class="text-gray-500 dark:text-gray-400">Customer</dt>
                                        <dd class="font-medium">{{ $conversation->customer->name }}</dd>
                                    </div>
                                @elseif ($conversation->lead)
                                    <div>
                                        <dt class="text-gray-500 dark:text-gray-400">Lead</dt>
                                        <dd class="font-medium">{{ preg_match('/^Chat contact \d+$/i', $conversation->lead->name) ? $conversation->profileDisplayName() : $conversation->lead->name }}</dd>
                                    </div>
                                @endif
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Owner</dt>
                                    <dd class="font-medium">{{ $conversation->assignedUser?->name ?: 'Unassigned' }}</dd>
                                </div>
                            </dl>
                        </x-filament::section>

                        @if ($canManage)
                            <x-filament::section compact icon="heroicon-o-user-plus" heading="Assignment">
                                <div class="flex flex-wrap gap-2">
                                    @if ($conversation->assigned_to === auth()->id())
                                        <x-filament::button color="gray" outlined wire:click="unassignConversation">
                                            Unassign
                                        </x-filament::button>
                                    @else
                                        <x-filament::button icon="heroicon-o-user-plus" wire:click="assignToMe">
                                            Assign to me
                                        </x-filament::button>
                                    @endif
                                    <x-filament::button color="gray" outlined wire:click="markConversationUnread">
                                        Mark unread
                                    </x-filament::button>
                                </div>
                            </x-filament::section>

                            <x-filament::section compact icon="heroicon-o-adjustments-horizontal" heading="Conversation state">
                                <div class="flex flex-wrap gap-2">
                                    @foreach (\App\Models\Conversation::STATUSES as $key => $label)
                                        <x-filament::button
                                            size="sm"
                                            :color="$conversation->status === $key ? ($statusColors[$key] ?? 'primary') : 'gray'"
                                            :outlined="$conversation->status !== $key"
                                            wire:click="setConversationStatus('{{ $key }}')"
                                        >
                                            {{ $label }}
                                        </x-filament::button>
                                    @endforeach
                                </div>
                            </x-filament::section>

                            <x-filament::section
                                compact
                                icon="heroicon-o-sparkles"
                                heading="AI assistant"
                                description="Pause automation whenever a human takes over."
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-sm font-medium">{{ $conversation->ai_enabled ? 'Enabled' : 'Paused' }}</span>
                                    <x-filament::toggle
                                        wire:key="desktop-ai-toggle-{{ $conversation->getKey() }}"
                                        :state="$conversation->ai_enabled ? 'true' : 'false'"
                                        wire:click="toggleAi"
                                        aria-label="Toggle AI assistant"
                                        on-icon="heroicon-m-check"
                                    />
                                </div>
                            </x-filament::section>
                        @endif

                        @if ($conversation->channel)
                            <x-filament::section
                                compact
                                icon="heroicon-o-signal"
                                heading="Channel health"
                                :description="$channelStatus"
                            >
                                <div class="space-y-3">
                                    <x-filament::badge :color="$healthColor">
                                        {{ $channelStatus }}
                                    </x-filament::badge>
                                    @if ($conversation->channel->last_error)
                                        <p class="text-sm text-danger-600" role="alert">{{ $conversation->channel->last_error }}</p>
                                    @elseif ($conversation->channel->last_inbound_at)
                                        @php
                                            $lastInboundAt = $conversation->channel->last_inbound_at->copy()->timezone($conversationTimezone);
                                        @endphp
                                        <p class="text-sm text-gray-600 dark:text-gray-300">
                                            Last inbound
                                            <time
                                                datetime="{{ $lastInboundAt->toIso8601String() }}"
                                                aria-label="{{ $lastInboundAt->format('d M Y, H:i:s T') }}"
                                                title="{{ $lastInboundAt->format('d M Y, H:i:s T') }}"
                                            >
                                                {{ $lastInboundAt->diffForHumans() }}
                                            </time>
                                        </p>
                                    @endif
                                    @if ($isSuperAdmin)
                                        <x-filament::link
                                            :href="\App\Filament\Resources\ConversationChannels\ConversationChannelResource::getUrl('edit', ['record' => $conversation->channel])"
                                            icon="heroicon-o-cog-6-tooth"
                                        >
                                            Channel settings
                                        </x-filament::link>
                                    @endif
                                </div>
                            </x-filament::section>
                        @endif
                    </div>
                @else
                    <x-filament::section compact icon="heroicon-o-information-circle" heading="Conversation details">
                        <p class="text-sm text-gray-600 dark:text-gray-300">Contact, assignment, AI, and channel health appear here.</p>
                    </x-filament::section>
                @endif
            </aside>
        </div>
    </div>
</x-filament-panels::page>
