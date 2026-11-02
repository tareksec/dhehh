{{--
    CRM → Email (owner request 2026-11-02): the shared mailbox laid out like Gmail. Folders and labels | list | open
    conversation; on a phone one of them at a time (the open conversation replaces the list, the folders slide in over it).
    The styles are the .zz-mail-* rules in resources/css/filament/admin/theme.css. Mail from strangers is never drawn
    into this page: HTML goes into a sandboxed frame (EmailMessageView), plain text is escaped.
--}}
@php
    $mailbox = $this->mailbox;
    $company = $this->company();
@endphp
<x-filament-panels::page class="zz-mail-page">
    @if ($mailbox === null)
        <x-filament::section>
            <div class="zz-mail-empty">
                <x-filament::icon icon="heroicon-o-envelope" class="zz-mail-empty-icon" aria-hidden="true" />
                <h2>{{ __('No mailbox yet') }}</h2>
                <p>{{ __('A mailbox is an email address the company reads here. Once one is added, the mail sent to it appears in this page.') }}</p>
            </div>
        </x-filament::section>
    @else
        @php
            $counts = $this->counts;
            $threads = $this->threads;
            $rows = $threads->take($this->limit);
            $thread = $this->thread;
            $canManage = $this->canManage();
            $folders = [
                ['inbox', __('Inbox'), 'heroicon-o-inbox', $counts['inbox']],
                ['starred', __('Starred'), 'heroicon-o-star', 0],
                ['sent', __('Sent'), 'heroicon-o-paper-airplane', 0],
                ['drafts', __('Drafts'), 'heroicon-o-document-text', $counts['drafts']],
                ['spam', __('Spam'), 'heroicon-o-exclamation-triangle', $counts['spam']],
                ['trash', __('Trash'), 'heroicon-o-trash', 0],
                ['all', __('All mail'), 'heroicon-o-archive-box', 0],
            ];
            $filterNames = [
                'unread' => __('Unread'),
                'needs_reply' => __('Needs reply'),
                'mine' => __('Mine'),
                'files' => __('Has files'),
            ];
            $activeLabel = $this->label();
            $viewName = $activeLabel ? $activeLabel->name : (collect($folders)->firstWhere(0, $this->folder)[1] ?? __('Inbox'));
            $selectedCount = count($this->selected);
            $inTrash = $this->folder === 'trash';
            $inSpam = $this->folder === 'spam';
        @endphp

        <div class="zz-mail"
                x-data="{
                    menu: false,
                    wide: false,
                    // Remembered per browser; the first time, open on a big screen only.
                    cust: (() => { try { const saved = localStorage.getItem('zzMailCustomer'); return saved === null ? window.innerWidth >= 1600 : saved === '1' } catch (e) { return false } })(),
                }"
                x-init="if (window.ResizeObserver) { new ResizeObserver((entries) => { wide = entries[0].contentRect.width >= 1300 }).observe($refs.body) }"
                x-effect="(() => { try { localStorage.setItem('zzMailCustomer', cust ? '1' : '0') } catch (e) {} })()"
                wire:poll.30s="checkNew"
                x-on:keydown.escape.window="menu = false">
            {{-- Top bar: mailbox, search, status --}}
            <div class="zz-mail-top">
                <button type="button" class="zz-mail-ib zz-mail-menu-btn" x-on:click="menu = ! menu" aria-label="{{ __('Folders and labels') }}" x-bind:aria-expanded="menu.toString()">
                    <x-filament::icon icon="heroicon-o-bars-3" class="zz-mail-i" aria-hidden="true" />
                </button>

                @if ($this->mailboxes->count() > 1)
                    <x-filament::dropdown placement="bottom-start" class="zz-mail-mbx-dd">
                        <x-slot name="trigger">
                            <button type="button" class="zz-mail-mbx" aria-label="{{ __('Switch mailbox') }}">
                                <span class="zz-mail-dot" aria-hidden="true"></span>
                                <span class="zz-mail-mbx-name">{{ $mailbox->address }}</span>
                                <x-filament::icon icon="heroicon-m-chevron-down" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />
                            </button>
                        </x-slot>
                        <x-filament::dropdown.list>
                            @foreach ($this->mailboxes as $other)
                                <x-filament::dropdown.list.item wire:click="switchMailbox({{ $other->id }})" wire:key="mbx-{{ $other->id }}" :icon="$other->is($mailbox) ? 'heroicon-m-check' : null">
                                    {{ $other->name }} · {{ $other->address }}
                                </x-filament::dropdown.list.item>
                            @endforeach
                        </x-filament::dropdown.list>
                    </x-filament::dropdown>
                @else
                    <span class="zz-mail-mbx zz-mail-mbx-static">
                        <span class="zz-mail-dot" aria-hidden="true"></span>
                        <span class="zz-mail-mbx-name">{{ $mailbox->address }}</span>
                    </span>
                @endif

                <label class="zz-mail-search">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="zz-mail-i" aria-hidden="true" />
                    <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search mail, customers and order numbers') }}" aria-label="{{ __('Search mail') }}" autocomplete="off">
                </label>

                <span class="zz-mail-spacer"></span>

                @if ($this->sendingIsSetUp())
                    <span class="zz-mail-pill"><x-filament::icon icon="heroicon-m-check-circle" class="zz-mail-i zz-mail-i-s zz-mail-good" aria-hidden="true" />{{ __('Sending is set up') }}</span>
                @else
                    <span class="zz-mail-pill zz-mail-pill-warn"><x-filament::icon icon="heroicon-m-exclamation-circle" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />{{ __('Sending is not set up') }}</span>
                @endif

                <button type="button" class="zz-mail-ib" wire:click="$refresh" aria-label="{{ __('Refresh') }}">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="zz-mail-i" aria-hidden="true" wire:loading.class="zz-mail-spin" wire:target="$refresh" />
                </button>
            </div>

            <div @class(['zz-mail-body', 'has-thread' => $thread]) x-ref="body" x-bind:class="{ 'has-cust': cust && {{ $thread ? 'true' : 'false' }}, 'is-wide': wide }">
                <div class="zz-mail-scrim" x-show="menu" x-on:click="menu = false" x-cloak></div>

                {{-- Folders and labels --}}
                <aside class="zz-mail-folders" x-bind:class="{ 'is-open': menu }" aria-label="{{ __('Folders and labels') }}">
                    @if ($canManage)
                        <button type="button" class="zz-mail-compose" wire:click="composeNew" x-on:click="menu = false">
                            <x-filament::icon icon="heroicon-o-pencil-square" class="zz-mail-i" aria-hidden="true" />
                            {{ __('Compose') }}
                        </button>
                    @endif

                    @foreach ($folders as [$key, $name, $icon, $count])
                        @php $on = $this->folder === $key && $activeLabel === null; @endphp
                        <button type="button" class="zz-mail-nav @if ($on) is-on @endif" wire:click="showFolder('{{ $key }}')" wire:key="folder-{{ $key }}" x-on:click="menu = false" @if ($on) aria-current="page" @endif>
                            <x-filament::icon :icon="$icon" class="zz-mail-i" aria-hidden="true" />
                            <span class="zz-mail-nav-name">{{ $name }}</span>
                            @if ($count > 0)<span class="zz-mail-n">{{ $count }}</span>@endif
                        </button>
                    @endforeach

                    <div class="zz-mail-sec">
                        <span>{{ __('Labels') }}</span>
                        @if ($canManage)
                            <button type="button" class="zz-mail-ib zz-mail-ib-sm" wire:click="$toggle('showLabelForm')" aria-label="{{ __('Create label') }}">
                                <x-filament::icon icon="heroicon-m-plus" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />
                            </button>
                        @endif
                    </div>

                    @if ($this->showLabelForm && $canManage)
                        <form class="zz-mail-labelform" wire:submit="createLabel">
                            <input type="text" wire:model="labelName" maxlength="60" placeholder="{{ __('Label name') }}" aria-label="{{ __('Label name') }}" autofocus>
                            @error('labelName')<p class="zz-mail-err">{{ $message }}</p>@enderror
                            <div class="zz-mail-swatches" role="radiogroup" aria-label="{{ __('Label colour') }}">
                                @foreach (\App\Models\EmailLabel::COLORS as $color)
                                    <label class="zz-mail-swatch zz-mail-c-{{ $color }}">
                                        <input type="radio" wire:model="labelColor" value="{{ $color }}" class="sr-only" aria-label="{{ $color }}">
                                        <span></span>
                                    </label>
                                @endforeach
                            </div>
                            <button type="submit" class="zz-mail-btn">{{ __('Create label') }}</button>
                        </form>
                    @endif

                    @forelse ($this->labels as $label)
                        <button type="button" class="zz-mail-nav @if ($activeLabel?->is($label)) is-on @endif" wire:click="showLabel({{ $label->id }})" wire:key="label-{{ $label->id }}" x-on:click="menu = false" @if ($activeLabel?->is($label)) aria-current="page" @endif>
                            <span class="zz-mail-lb zz-mail-c-{{ $label->color }}" aria-hidden="true"></span>
                            <span class="zz-mail-nav-name">{{ $label->name }}</span>
                            @if (($counts['labels'][$label->id] ?? 0) > 0)<span class="zz-mail-n">{{ $counts['labels'][$label->id] }}</span>@endif
                        </button>
                    @empty
                        <p class="zz-mail-hint">{{ __('No labels yet.') }}</p>
                    @endforelse

                    <div class="zz-mail-status">
                        <b>{{ __('Mailbox status') }}</b>
                        @if ($mailbox->last_received_at)
                            <span><span class="zz-mail-dot" aria-hidden="true"></span>{{ __('Receiving, last mail :time', ['time' => $mailbox->last_received_at->diffForHumans()]) }}</span>
                        @else
                            <span><span class="zz-mail-dot zz-mail-dot-idle" aria-hidden="true"></span>{{ __('Waiting for the first email') }}</span>
                        @endif
                    </div>
                </aside>

                {{-- The list --}}
                <section class="zz-mail-list" aria-label="{{ __('Conversations') }}">
                    <div class="zz-mail-bar">
                        @if ($selectedCount > 0 && $canManage)
                            <button type="button" class="zz-mail-ib" wire:click="selectAll" aria-label="{{ __('Clear selection') }}">
                                <x-filament::icon icon="heroicon-m-check-circle" class="zz-mail-i zz-mail-on" aria-hidden="true" />
                            </button>
                            <span class="zz-mail-count">{{ __(':count selected', ['count' => $selectedCount]) }}</span>
                            <span class="zz-mail-sep"></span>
                            @if ($inTrash)
                                <button type="button" class="zz-mail-tb" wire:click="performSelected('restore')">{{ __('Move to Inbox') }}</button>
                                <button type="button" class="zz-mail-tb zz-mail-danger" wire:click="performSelected('delete')" wire:confirm="{{ __('Delete these conversations for good? This cannot be undone.') }}">{{ __('Delete forever') }}</button>
                            @elseif ($inSpam)
                                <button type="button" class="zz-mail-tb" wire:click="performSelected('notspam')">{{ __('Not spam') }}</button>
                                <button type="button" class="zz-mail-tb" wire:click="performSelected('trash')">{{ __('Delete') }}</button>
                            @else
                                <button type="button" class="zz-mail-ib" wire:click="performSelected('archive')" aria-label="{{ __('Archive') }}" title="{{ __('Archive') }}"><x-filament::icon icon="heroicon-o-archive-box-arrow-down" class="zz-mail-i" aria-hidden="true" /></button>
                                <button type="button" class="zz-mail-ib" wire:click="performSelected('spam')" aria-label="{{ __('Report spam') }}" title="{{ __('Report spam') }}"><x-filament::icon icon="heroicon-o-exclamation-triangle" class="zz-mail-i" aria-hidden="true" /></button>
                                <button type="button" class="zz-mail-ib" wire:click="performSelected('trash')" aria-label="{{ __('Delete') }}" title="{{ __('Delete') }}"><x-filament::icon icon="heroicon-o-trash" class="zz-mail-i" aria-hidden="true" /></button>
                                <span class="zz-mail-sep"></span>
                                <button type="button" class="zz-mail-ib" wire:click="performSelected('read')" aria-label="{{ __('Mark as read') }}" title="{{ __('Mark as read') }}"><x-filament::icon icon="heroicon-o-envelope-open" class="zz-mail-i" aria-hidden="true" /></button>
                                <button type="button" class="zz-mail-ib" wire:click="performSelected('unread')" aria-label="{{ __('Mark as unread') }}" title="{{ __('Mark as unread') }}"><x-filament::icon icon="heroicon-o-envelope" class="zz-mail-i" aria-hidden="true" /></button>
                                <button type="button" class="zz-mail-ib" wire:click="performSelected('star')" aria-label="{{ __('Add star') }}" title="{{ __('Add star') }}"><x-filament::icon icon="heroicon-o-star" class="zz-mail-i" aria-hidden="true" /></button>
                                @if ($this->labels->isNotEmpty())
                                    <x-filament::dropdown placement="bottom-start">
                                        <x-slot name="trigger">
                                            <button type="button" class="zz-mail-ib" aria-label="{{ __('Labels') }}" title="{{ __('Labels') }}"><x-filament::icon icon="heroicon-o-tag" class="zz-mail-i" aria-hidden="true" /></button>
                                        </x-slot>
                                        <x-filament::dropdown.list>
                                            @foreach ($this->labels as $label)
                                                <x-filament::dropdown.list.item wire:click="applyLabelToSelected({{ $label->id }})" wire:key="bulk-label-{{ $label->id }}">{{ $label->name }}</x-filament::dropdown.list.item>
                                            @endforeach
                                        </x-filament::dropdown.list>
                                    </x-filament::dropdown>
                                @endif
                            @endif
                        @else
                            @if ($canManage)
                                <label class="zz-mail-ib zz-mail-check" aria-label="{{ __('Select all') }}">
                                    <input type="checkbox" wire:click="selectAll" @checked($rows->isNotEmpty() && count($this->selected) >= $rows->count())>
                                </label>
                            @endif
                            <h2 class="zz-mail-viewname">{{ $viewName }}</h2>
                            @if ($inTrash && $canManage && $rows->isNotEmpty())
                                <button type="button" class="zz-mail-tb zz-mail-danger" wire:click="emptyTrash" wire:confirm="{{ __('Delete everything in Trash for good? This cannot be undone.') }}">{{ __('Empty Trash') }}</button>
                            @endif
                            <span class="zz-mail-count">{{ $this->hasMore() ? __(':count+ conversations', ['count' => $rows->count()]) : trans_choice(':count conversation|:count conversations', $rows->count()) }}</span>
                        @endif
                    </div>

                    <div class="zz-mail-chips" role="group" aria-label="{{ __('Quick filters') }}">
                        @foreach ($filterNames as $key => $name)
                            <button type="button" class="zz-mail-fc @if (in_array($key, $this->filters, true)) is-on @endif" wire:click="toggleFilter('{{ $key }}')" aria-pressed="{{ in_array($key, $this->filters, true) ? 'true' : 'false' }}" wire:key="filter-{{ $key }}">{{ $name }}</button>
                        @endforeach
                    </div>

                    @if ($rows->isEmpty())
                        <div class="zz-mail-none">
                            <x-filament::icon icon="heroicon-o-inbox" class="zz-mail-empty-icon" aria-hidden="true" />
                            <p>
                                @if ($this->search !== '' || $this->filters !== [])
                                    {{ __('No conversation matches.') }}
                                @elseif ($this->folder === 'inbox' && $activeLabel === null)
                                    {{ __('Nothing in the Inbox.') }}
                                @else
                                    {{ __('Nothing here.') }}
                                @endif
                            </p>
                        </div>
                    @else
                        <ul class="zz-mail-rows">
                            @foreach ($rows as $row)
                                @php
                                    $unread = $row->unread_count > 0;
                                    $isDraftOnly = $row->message_count === 0;
                                    $who = $this->folder === 'sent' || $isDraftOnly
                                        ? __('To: :name', ['name' => $row->contact_name ?: ($row->contact_email ?: __('(no recipient)'))])
                                        : ($row->contact_name ?: ($row->contact_email ?: __('(unknown sender)')));
                                @endphp
                                <li class="zz-mail-row @if ($unread) is-unread @endif @if ($thread?->is($row)) is-sel @endif" wire:key="thread-{{ $row->id }}">
                                    @if ($canManage)
                                        <input type="checkbox" wire:model.live="selected" value="{{ $row->id }}" aria-label="{{ __('Select conversation') }}">
                                    @else
                                        <span></span>
                                    @endif
                                    <button type="button" class="zz-mail-star @if ($row->is_starred) is-on @endif" @if ($canManage) wire:click="toggleStar({{ $row->id }})" @else disabled @endif aria-label="{{ $row->is_starred ? __('Remove star') : __('Add star') }}" aria-pressed="{{ $row->is_starred ? 'true' : 'false' }}">
                                        <x-filament::icon :icon="$row->is_starred ? 'heroicon-s-star' : 'heroicon-o-star'" class="zz-mail-i" aria-hidden="true" />
                                    </button>
                                    <button type="button" class="zz-mail-main" wire:click="openThread({{ $row->id }})" @if ($thread?->is($row)) aria-current="true" @endif>
                                        <span class="zz-mail-l1">
                                            <span class="zz-mail-snd">{{ $who }}@if ($row->message_count > 1) <span class="zz-mail-cnt">{{ $row->message_count }}</span>@endif</span>
                                            <span class="zz-mail-tm">{{ $this->shortTime($row->last_message_at ?? $row->updated_at) }}</span>
                                        </span>
                                        <span class="zz-mail-sub">{{ $row->subject !== '' ? $row->subject : __('(no subject)') }}</span>
                                        <span class="zz-mail-snip">{{ $isDraftOnly ? __('Draft').' · ' : '' }}{{ $row->snippet }}</span>
                                        @if ($row->labels->isNotEmpty() || $row->has_attachments || ($row->status !== 'open') || $row->assignee)
                                            <span class="zz-mail-chiprow">
                                                @foreach ($row->labels as $label)
                                                    <span class="zz-mail-chip"><i class="zz-mail-c-{{ $label->color }}" aria-hidden="true"></i>{{ $label->name }}</span>
                                                @endforeach
                                                @if ($row->has_attachments)
                                                    <span class="zz-mail-chip" title="{{ __('Has files') }}"><x-filament::icon icon="heroicon-m-paper-clip" class="zz-mail-i" aria-hidden="true" /><span class="sr-only">{{ __('Has files') }}</span></span>
                                                @endif
                                                @if ($row->status !== 'open')
                                                    <span class="zz-mail-chip">{{ $row->status === 'closed' ? __('Closed') : __('Pending') }}</span>
                                                @endif
                                                @if ($row->assignee)
                                                    <span class="zz-mail-chip">{{ $row->assignee->name }}</span>
                                                @endif
                                            </span>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>

                        @if ($this->hasMore())
                            <div class="zz-mail-more">
                                <button type="button" class="zz-mail-btn zz-mail-btn-quiet" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore">{{ __('Load more conversations') }}</button>
                            </div>
                        @endif
                    @endif
                </section>

                {{-- The open conversation --}}
                <section class="zz-mail-read" aria-label="{{ __('Conversation') }}">
                    @if ($thread)
                        @php
                            $messages = $this->messages;
                            // The newest real message: not a note, not a draft.
                            $lastId = $messages->where('kind', 'message')->last()?->id;
                        @endphp
                        <div class="zz-mail-bar">
                            <button type="button" class="zz-mail-ib zz-mail-back" wire:click="closeThread" aria-label="{{ __('Back to the list') }}">
                                <x-filament::icon icon="heroicon-o-arrow-left" class="zz-mail-i" aria-hidden="true" />
                            </button>
                            @if ($canManage)
                                @if ($thread->folder === 'trash')
                                    <button type="button" class="zz-mail-tb" wire:click="perform('restore', [{{ $thread->id }}])">{{ __('Move to Inbox') }}</button>
                                    <button type="button" class="zz-mail-tb zz-mail-danger" wire:click="perform('delete', [{{ $thread->id }}])" wire:confirm="{{ __('Delete this conversation for good? This cannot be undone.') }}">{{ __('Delete forever') }}</button>
                                @elseif ($thread->folder === 'spam')
                                    <button type="button" class="zz-mail-tb" wire:click="perform('notspam', [{{ $thread->id }}])">{{ __('Not spam') }}</button>
                                    <button type="button" class="zz-mail-ib" wire:click="perform('trash', [{{ $thread->id }}])" aria-label="{{ __('Delete') }}" title="{{ __('Delete') }}"><x-filament::icon icon="heroicon-o-trash" class="zz-mail-i" aria-hidden="true" /></button>
                                @else
                                    @if ($thread->folder === 'inbox')
                                        <button type="button" class="zz-mail-ib" wire:click="perform('archive', [{{ $thread->id }}])" aria-label="{{ __('Archive') }}" title="{{ __('Archive') }}"><x-filament::icon icon="heroicon-o-archive-box-arrow-down" class="zz-mail-i" aria-hidden="true" /></button>
                                    @else
                                        <button type="button" class="zz-mail-ib" wire:click="perform('inbox', [{{ $thread->id }}])" aria-label="{{ __('Move to Inbox') }}" title="{{ __('Move to Inbox') }}"><x-filament::icon icon="heroicon-o-inbox-arrow-down" class="zz-mail-i" aria-hidden="true" /></button>
                                    @endif
                                    <button type="button" class="zz-mail-ib" wire:click="perform('spam', [{{ $thread->id }}])" aria-label="{{ __('Report spam') }}" title="{{ __('Report spam') }}"><x-filament::icon icon="heroicon-o-exclamation-triangle" class="zz-mail-i" aria-hidden="true" /></button>
                                    <button type="button" class="zz-mail-ib" wire:click="perform('trash', [{{ $thread->id }}])" aria-label="{{ __('Delete') }}" title="{{ __('Delete') }}"><x-filament::icon icon="heroicon-o-trash" class="zz-mail-i" aria-hidden="true" /></button>
                                @endif
                                <span class="zz-mail-sep"></span>
                                <button type="button" class="zz-mail-ib" wire:click="perform('unread', [{{ $thread->id }}])" aria-label="{{ __('Mark as unread') }}" title="{{ __('Mark as unread') }}"><x-filament::icon icon="heroicon-o-envelope" class="zz-mail-i" aria-hidden="true" /></button>
                                @if ($this->labels->isNotEmpty())
                                    <x-filament::dropdown placement="bottom-start">
                                        <x-slot name="trigger">
                                            <button type="button" class="zz-mail-ib" aria-label="{{ __('Labels') }}" title="{{ __('Labels') }}"><x-filament::icon icon="heroicon-o-tag" class="zz-mail-i" aria-hidden="true" /></button>
                                        </x-slot>
                                        <x-filament::dropdown.list>
                                            @foreach ($this->labels as $label)
                                                @php $has = $thread->labels->contains('id', $label->id); @endphp
                                                <x-filament::dropdown.list.item wire:click="applyLabel({{ $label->id }}, [{{ $thread->id }}], {{ $has ? 'false' : 'true' }})" wire:key="open-label-{{ $label->id }}" :icon="$has ? 'heroicon-m-check' : null">{{ $label->name }}</x-filament::dropdown.list.item>
                                            @endforeach
                                        </x-filament::dropdown.list>
                                    </x-filament::dropdown>
                                @endif
                            @endif
                            <span class="zz-mail-spacer"></span>

                            @php
                                $statusNames = ['open' => __('Open'), 'pending' => __('Pending'), 'closed' => __('Closed')];
                                $assignee = $thread->assignee;
                            @endphp
                            @if ($canManage)
                                <x-filament::dropdown placement="bottom-end">
                                    <x-slot name="trigger">
                                        <button type="button" class="zz-mail-sel" aria-label="{{ __('Assign to') }}" title="{{ __('Assign to') }}">
                                            <span class="zz-mail-av zz-mail-av-s {{ $assignee ? $this->avatarClass($assignee->email) : 'zz-mail-a6' }}" aria-hidden="true">{{ $assignee ? $this->initials($assignee->name, $assignee->email) : '–' }}</span>
                                            <span class="zz-mail-sel-text">{{ $assignee?->name ?? __('Unassigned') }}</span>
                                            <x-filament::icon icon="heroicon-m-chevron-down" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />
                                        </button>
                                    </x-slot>
                                    <x-filament::dropdown.list>
                                        <x-filament::dropdown.list.item wire:click="assignThread({{ auth()->id() }})" wire:key="assign-me">{{ __('Assign to me') }}</x-filament::dropdown.list.item>
                                        <x-filament::dropdown.list.item wire:click="assignThread(null)" wire:key="assign-none" :icon="$assignee === null ? 'heroicon-m-check' : null">{{ __('Unassigned') }}</x-filament::dropdown.list.item>
                                        @foreach ($this->staff as $member)
                                            <x-filament::dropdown.list.item wire:click="assignThread({{ $member->id }})" wire:key="assign-{{ $member->id }}" :icon="$assignee?->is($member) ? 'heroicon-m-check' : null">{{ $member->name }}</x-filament::dropdown.list.item>
                                        @endforeach
                                    </x-filament::dropdown.list>
                                </x-filament::dropdown>

                                <x-filament::dropdown placement="bottom-end">
                                    <x-slot name="trigger">
                                        <button type="button" class="zz-mail-sel zz-mail-sel-{{ $thread->status }}" aria-label="{{ __('Status') }}" title="{{ __('Status') }}">
                                            <span class="zz-mail-sel-text">{{ $statusNames[$thread->status] ?? $thread->status }}</span>
                                            <x-filament::icon icon="heroicon-m-chevron-down" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />
                                        </button>
                                    </x-slot>
                                    <x-filament::dropdown.list>
                                        @foreach ($statusNames as $key => $name)
                                            <x-filament::dropdown.list.item wire:click="setThreadStatus('{{ $key }}')" wire:key="status-{{ $key }}" :icon="$thread->status === $key ? 'heroicon-m-check' : null">{{ $name }}</x-filament::dropdown.list.item>
                                        @endforeach
                                    </x-filament::dropdown.list>
                                </x-filament::dropdown>
                            @else
                                <span class="zz-mail-chip">{{ $assignee?->name ?? __('Unassigned') }}</span>
                                <span class="zz-mail-chip">{{ $statusNames[$thread->status] ?? $thread->status }}</span>
                            @endif

                            <button type="button" class="zz-mail-ib" x-on:click="cust = ! cust" x-bind:aria-pressed="cust.toString()" aria-label="{{ __('Customer') }}" title="{{ __('Customer') }}">
                                <x-filament::icon icon="heroicon-o-user-circle" class="zz-mail-i" aria-hidden="true" />
                            </button>
                            <button type="button" class="zz-mail-star @if ($thread->is_starred) is-on @endif" @if ($canManage) wire:click="toggleStar({{ $thread->id }})" @else disabled @endif aria-label="{{ $thread->is_starred ? __('Remove star') : __('Add star') }}" aria-pressed="{{ $thread->is_starred ? 'true' : 'false' }}">
                                <x-filament::icon :icon="$thread->is_starred ? 'heroicon-s-star' : 'heroicon-o-star'" class="zz-mail-i" aria-hidden="true" />
                            </button>
                        </div>

                        <div class="zz-mail-thread">
                            <div class="zz-mail-subj">
                                <h2>{{ $thread->subject !== '' ? $thread->subject : __('(no subject)') }}</h2>
                                <div class="zz-mail-chiprow">
                                    @foreach ($thread->labels as $label)
                                        <span class="zz-mail-chip" wire:key="thread-label-{{ $label->id }}">
                                            <i class="zz-mail-c-{{ $label->color }}" aria-hidden="true"></i>{{ $label->name }}
                                            @if ($canManage)
                                                <button type="button" class="zz-mail-chipx" wire:click="applyLabel({{ $label->id }}, [{{ $thread->id }}], false)" aria-label="{{ __('Remove label :name', ['name' => $label->name]) }}">×</button>
                                            @endif
                                        </span>
                                    @endforeach
                                    @if ($thread->folder === 'spam')<span class="zz-mail-chip">{{ __('Spam') }}</span>@endif
                                    @if ($thread->folder === 'trash')<span class="zz-mail-chip">{{ __('Trash') }}</span>@endif
                                </div>
                            </div>

                            @foreach ($messages as $message)
                                @include('filament.pages.email-inbox-message', ['message' => $message, 'isLast' => $message->id === $lastId])
                            @endforeach

                            @if ($canManage && $lastId && ! in_array($thread->folder, ['spam', 'trash'], true))
                                @php $target = $messages->firstWhere('id', $lastId); @endphp
                                <div class="zz-mail-actions" wire:key="thread-actions-{{ $thread->id }}">
                                    <button type="button" class="zz-mail-btn" wire:click="composeReply({{ $lastId }})">
                                        <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />{{ __('Reply') }}
                                    </button>
                                    @if ($this->replyAllMakesSense($target))
                                        <button type="button" class="zz-mail-btn zz-mail-btn-quiet" wire:click="composeReply({{ $lastId }}, true)">
                                            <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />{{ __('Reply all') }}
                                        </button>
                                    @endif
                                    <button type="button" class="zz-mail-btn zz-mail-btn-quiet" wire:click="composeForward({{ $lastId }})">
                                        <x-filament::icon icon="heroicon-o-arrow-uturn-right" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />{{ __('Forward') }}
                                    </button>
                                    <button type="button" class="zz-mail-btn zz-mail-btn-quiet" wire:click="$toggle('noteOpen')" aria-expanded="{{ $this->noteOpen ? 'true' : 'false' }}">
                                        <x-filament::icon icon="heroicon-o-lock-closed" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />{{ __('Add note') }}
                                    </button>
                                </div>

                                @if ($this->noteOpen)
                                    <form class="zz-mail-noteform" wire:submit="addNote" wire:key="note-form">
                                        <label for="zz-mail-note-text">{{ __('Internal note, only staff see it') }}</label>
                                        <textarea id="zz-mail-note-text" wire:model="noteText" rows="3" maxlength="5000" placeholder="{{ __('Write a note for your team') }}"></textarea>
                                        <div class="zz-mail-noteform-row">
                                            <button type="submit" class="zz-mail-btn">{{ __('Save note') }}</button>
                                            <button type="button" class="zz-mail-btn zz-mail-btn-quiet" wire:click="$set('noteOpen', false)">{{ __('Cancel') }}</button>
                                        </div>
                                    </form>
                                @endif
                            @endif
                        </div>
                    @else
                        <div class="zz-mail-none zz-mail-pick">
                            <x-filament::icon icon="heroicon-o-envelope-open" class="zz-mail-empty-icon" aria-hidden="true" />
                            <p>{{ __('Select a conversation to read it.') }}</p>
                        </div>
                    @endif
                </section>

                @if ($thread)
                    @include('filament.pages.email-inbox-customer', ['contact' => $this->contact])
                @endif
            </div>

            @if ($this->composeId !== null && $canManage)
                @include('filament.pages.email-inbox-compose')
            @endif

            @if ($this->sentUndo)
                <div class="zz-mail-toast" role="status" wire:key="sent-{{ $this->sentUndo['id'] }}"
                    x-data="{ left: {{ (int) $this->sentUndo['seconds'] }} }"
                    x-init="const timer = setInterval(() => { left--; if (left <= 0) { clearInterval(timer); $wire.deliverDue() } }, 1000)">
                    <span>{{ __('Message sent') }}</span>
                    <button type="button" wire:click="undoSend({{ (int) $this->sentUndo['id'] }})">{{ __('Undo') }} <span x-text="left" aria-hidden="true"></span></button>
                </div>
            @elseif ($this->undo)
                <div class="zz-mail-toast" role="status" x-data x-init="setTimeout(() => $wire.dismissUndo(), 8000)" wire:key="undo-{{ md5(json_encode($this->undo)) }}">
                    <span>{{ $this->undo['message'] }}</span>
                    <button type="button" wire:click="undoMove">{{ __('Undo') }}</button>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
