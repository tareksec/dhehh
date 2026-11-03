{{--
    One entry of the open conversation in CRM → Email: an internal note, a draft of the signed-in person, or a message
    (received, sent, waiting out the undo window, failed). Older messages are one line until opened; the newest is open.
    HTML mail is drawn only inside a sandboxed frame (no scripts, no remote pictures unless asked) -- see EmailMessageView.
--}}
@php
    $viewer = $this->messageView();
    $mine = $message->direction === 'out';
    $addresses = fn ($list) => collect((array) $list)->map(fn ($entry) => \App\Support\EmailAddressList::label((array) $entry))->implode(', ');
@endphp

@if ($message->kind === 'note')
    <article class="zz-mail-note" wire:key="msg-{{ $message->id }}">
        <x-filament::icon icon="heroicon-o-lock-closed" class="zz-mail-i" aria-hidden="true" />
        <div>
            <b>{{ $message->from_name ?: $message->from_address }}</b>
            <small>{{ __('Internal note, only staff see it') }} · {{ $this->shortTime($message->sent_at ?? $message->created_at) }}</small>
            <p>{!! nl2br(e((string) $message->body_text), false) !!}</p>
        </div>
    </article>
@elseif ($message->kind === 'draft')
    <article class="zz-mail-msg is-draft" wire:key="msg-{{ $message->id }}">
        <div class="zz-mail-msghead">
            <span class="zz-mail-av zz-mail-a6">{{ $this->initials($message->from_name, $message->from_address) }}</span>
            <div class="zz-mail-who">
                <b class="zz-mail-draftmark">{{ __('Draft') }}</b>
                <span class="zz-mail-to">{{ __('To: :names', ['names' => $addresses($message->to_addresses) ?: __('(no recipient)')]) }}</span>
            </div>
        </div>
        <div class="zz-mail-msgbody">
            <div class="zz-mail-text">{!! $viewer->text($message) !!}</div>
            @if ($this->canManage() && $message->user_id === auth()->id())
                <div><button type="button" class="zz-mail-btn zz-mail-btn-quiet" wire:click="editDraft({{ $message->id }})">{{ __('Edit draft') }}</button></div>
            @endif
        </div>
    </article>
@else
    @php
        $open = $this->isOpen($message, $isLast);
        $name = $message->from_name ?: $message->from_address;
        $pictures = $this->picturesAreShown($message);
        $files = $message->attachments->where('is_inline', false);
    @endphp
    <article class="zz-mail-msg @if ($open) is-open @else is-min @endif" wire:key="msg-{{ $message->id }}">
        <button type="button" class="zz-mail-msghead" wire:click="toggleMessage({{ $message->id }}, {{ $isLast ? 'true' : 'false' }})" aria-expanded="{{ $open ? 'true' : 'false' }}">
            <span class="zz-mail-av {{ $this->avatarClass($message->from_address) }}" aria-hidden="true">{{ $this->initials($message->from_name, $message->from_address) }}</span>
            <span class="zz-mail-who">
                <b>{{ $mine ? __('Me') : $name }}</b>
                @if ($open)
                    <span class="zz-mail-to">{{ __('to :names', ['names' => $addresses($message->to_addresses) ?: __('(no recipient)')]) }}</span>
                @else
                    <span class="zz-mail-sn">{{ \Illuminate\Support\Str::limit(\App\Support\EmailText::fromHtml((string) $message->body_html) ?: (string) $message->body_text, 120) }}</span>
                @endif
            </span>
            <span class="zz-mail-meta">
                @if ($message->status === 'pending' || $message->status === 'sending')
                    <span class="zz-mail-state">{{ __('Sending…') }}</span>
                @elseif ($message->status === 'failed')
                    <span class="zz-mail-state zz-mail-bad">{{ __('Not sent') }}</span>
                @elseif ($mine)
                    <span class="zz-mail-state zz-mail-ok"><x-filament::icon icon="heroicon-m-check" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />{{ __('Sent') }}</span>
                @endif
                @if ($files->isNotEmpty())<x-filament::icon icon="heroicon-m-paper-clip" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />@endif
                <time datetime="{{ ($message->sent_at ?? $message->created_at)?->toIso8601String() }}" title="{{ $this->longTime($message->sent_at ?? $message->created_at) }}">{{ $this->shortTime($message->sent_at ?? $message->created_at) }}</time>
            </span>
        </button>

        @if ($open)
            <div class="zz-mail-msgbody">
                @if ($message->status === 'failed' && $message->error)
                    <p class="zz-mail-alert" role="alert">{{ $message->error }}</p>
                @endif
                @if ($this->canManage() && $message->status === 'failed')
                    <div><button type="button" class="zz-mail-btn zz-mail-btn-quiet" wire:click="retryMessage({{ $message->id }})">{{ __('Send again') }}</button></div>
                @endif
                @if ($this->canManage() && $message->status === 'pending' && $message->user_id === auth()->id())
                    <div><button type="button" class="zz-mail-btn zz-mail-btn-quiet" wire:click="undoSend({{ $message->id }})">{{ __('Undo send') }}</button></div>
                @endif

                <dl class="zz-mail-details">
                    <div><dt>{{ __('From') }}</dt><dd>{{ $addresses([['address' => $message->from_address, 'name' => $message->from_name]]) }}</dd></div>
                    <div><dt>{{ __('To') }}</dt><dd>{{ $addresses($message->to_addresses) ?: '—' }}</dd></div>
                    @if (! empty($message->cc_addresses))<div><dt>{{ __('Cc') }}</dt><dd>{{ $addresses($message->cc_addresses) }}</dd></div>@endif
                    @if (! empty($message->bcc_addresses))<div><dt>{{ __('Bcc') }}</dt><dd>{{ $addresses($message->bcc_addresses) }}</dd></div>@endif
                    @if (! empty($message->reply_to_addresses))<div><dt>{{ __('Reply-To') }}</dt><dd>{{ $addresses($message->reply_to_addresses) }}</dd></div>@endif
                </dl>

                @if ($viewer->hasHtml($message))
                    @if (! $pictures && $viewer->hasRemoteImages($message))
                        <p class="zz-mail-notice">
                            <span>{{ __('Pictures from the internet are hidden. Showing them tells the sender you opened this email.') }}</span>
                            <button type="button" wire:click="showPictures({{ $message->id }})">{{ __('Show pictures') }}</button>
                        </p>
                    @endif
                    <iframe
                        class="zz-mail-frame"
                        title="{{ __('Email from :name', ['name' => $name]) }}"
                        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                        referrerpolicy="no-referrer"
                        srcdoc="{{ $viewer->document($message, $company, $pictures) }}"
                        wire:key="frame-{{ $message->id }}-{{ $pictures ? 1 : 0 }}"
                        x-data
                        x-on:load="const doc = $el.contentDocument; if (! doc) { return } const fit = () => { $el.style.height = Math.max(doc.documentElement.scrollHeight, 40) + 'px' }; fit(); if (window.ResizeObserver) { new ResizeObserver(fit).observe(doc.documentElement) }"
                    ></iframe>
                @else
                    <div class="zz-mail-text">{!! $viewer->text($message) !!}</div>
                @endif

                @if ($files->isNotEmpty())
                    <ul class="zz-mail-files" aria-label="{{ __('Files') }}">
                        @foreach ($files as $file)
                            <li wire:key="file-{{ $file->id }}">
                                <a class="zz-mail-file" href="{{ route('email-attachments.download', $file) }}" download>
                                    <x-filament::icon icon="heroicon-o-paper-clip" class="zz-mail-i" aria-hidden="true" />
                                    <span class="zz-mail-filename">{{ $file->filename }}</span>
                                    <small>{{ \Illuminate\Support\Number::fileSize($file->size, 1) }}</small>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </article>
@endif
