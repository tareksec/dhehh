{{--
    The compose window of CRM → Email (owner request 2026-11-02): a new message, a reply, a reply to all or a forward. A
    window at the bottom right of the page on a desktop, the whole screen on a phone. The draft is saved by itself a
    couple of seconds after the person stops typing (saveCompose). The body is Filament's rich-text editor; its HTML is
    cleaned again on the server. See App\Filament\Pages\Concerns\ComposesEmail.
--}}
@php
    $files = $this->composeFiles;
    $total = (int) $files->sum('size');
@endphp
<section class="zz-mail-composer" role="dialog" aria-label="{{ __('Compose') }}" aria-modal="false"
    x-data
    x-on:input.debounce.2000ms="$wire.saveCompose()"
    x-on:keydown.ctrl.enter.prevent="$wire.sendCompose()"
    x-on:keydown.meta.enter.prevent="$wire.sendCompose()">
    <header class="zz-mail-composer-head">
        <h2>{{ $this->composeSubject !== '' ? $this->composeSubject : __('New message') }}</h2>
        <span class="zz-mail-saved" aria-live="polite">
            <span wire:loading wire:target="saveCompose">{{ __('Saving…') }}</span>
            <span wire:loading.remove wire:target="saveCompose">{{ $this->composeSavedAt ? __('Saved to Drafts at :time', ['time' => $this->composeSavedAt]) : '' }}</span>
        </span>
        <button type="button" class="zz-mail-ib zz-mail-ib-sm" wire:click="closeCompose" aria-label="{{ __('Save and close') }}" title="{{ __('Save and close') }}">
            <x-filament::icon icon="heroicon-m-x-mark" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />
        </button>
    </header>

    <div class="zz-mail-composer-body">
        <div class="zz-mail-field">
            <span class="zz-mail-label">{{ __('From') }}</span>
            <span class="zz-mail-from">{{ $mailbox->name }} &lt;{{ $mailbox->address }}&gt;</span>
        </div>

        <div class="zz-mail-field">
            <label class="zz-mail-label" for="zz-mail-to">{{ __('To') }}</label>
            <input id="zz-mail-to" type="text" wire:model="composeTo" autocomplete="off" spellcheck="false" placeholder="name@example.com" @class(['is-bad' => $this->composeInvalid !== []])>
            <span class="zz-mail-ccs">
                @if (! $this->composeShowCc)<button type="button" wire:click="$set('composeShowCc', true)">{{ __('Cc') }}</button>@endif
                @if (! $this->composeShowBcc)<button type="button" wire:click="$set('composeShowBcc', true)">{{ __('Bcc') }}</button>@endif
            </span>
        </div>
        @if ($this->composeShowCc)
            <div class="zz-mail-field">
                <label class="zz-mail-label" for="zz-mail-cc">{{ __('Cc') }}</label>
                <input id="zz-mail-cc" type="text" wire:model="composeCc" autocomplete="off" spellcheck="false">
            </div>
        @endif
        @if ($this->composeShowBcc)
            <div class="zz-mail-field">
                <label class="zz-mail-label" for="zz-mail-bcc">{{ __('Bcc') }}</label>
                <input id="zz-mail-bcc" type="text" wire:model="composeBcc" autocomplete="off" spellcheck="false">
            </div>
        @endif
        @if ($this->composeInvalid !== [])
            <p class="zz-mail-err" role="alert">{{ __('Check these addresses: :list', ['list' => implode(', ', $this->composeInvalid)]) }}</p>
        @endif

        <div class="zz-mail-field">
            <label class="zz-mail-label" for="zz-mail-subject">{{ __('Subject') }}</label>
            <input id="zz-mail-subject" type="text" wire:model="composeSubject" maxlength="255" autocomplete="off">
        </div>

        <div class="zz-mail-editorwrap" wire:ignore.self>
            {{ $this->composeForm }}
        </div>

        @if ($files->isNotEmpty())
            <ul class="zz-mail-files" aria-label="{{ __('Files') }}">
                @foreach ($files as $file)
                    <li wire:key="compose-file-{{ $file->id }}">
                        <span class="zz-mail-file">
                            <x-filament::icon icon="heroicon-o-paper-clip" class="zz-mail-i" aria-hidden="true" />
                            <span class="zz-mail-filename">{{ $file->filename }}</span>
                            <small>{{ \Illuminate\Support\Number::fileSize($file->size, 1) }}</small>
                            <button type="button" class="zz-mail-chipx" wire:click="removeComposeFile({{ $file->id }})" aria-label="{{ __('Remove file :name', ['name' => $file->filename]) }}">×</button>
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="zz-mail-hint">{{ __(':used of :max MB used', ['used' => number_format($total / 1048576, 1), 'max' => (int) (\App\Models\EmailAttachment::MAX_TOTAL_BYTES / 1048576)]) }}</p>
        @endif
    </div>

    <footer class="zz-mail-composer-foot">
        <button type="button" class="zz-mail-send" wire:click="sendCompose" wire:loading.attr="disabled" wire:target="sendCompose, composeUploads">
            <x-filament::icon icon="heroicon-o-paper-airplane" class="zz-mail-i" aria-hidden="true" />{{ __('Send') }}
        </button>

        <label class="zz-mail-ib" title="{{ __('Attach files') }}" aria-label="{{ __('Attach files') }}">
            <x-filament::icon icon="heroicon-o-paper-clip" class="zz-mail-i" aria-hidden="true" />
            <input type="file" class="sr-only" multiple wire:model="composeUploads">
        </label>
        <span class="zz-mail-saved" wire:loading wire:target="composeUploads">{{ __('Uploading…') }}</span>

        <div class="zz-mail-qr">
            <button type="button" class="zz-mail-tb" wire:click="$toggle('showQuickReplies')" aria-expanded="{{ $this->showQuickReplies ? 'true' : 'false' }}">
                <x-filament::icon icon="heroicon-o-bolt" class="zz-mail-i zz-mail-i-s" aria-hidden="true" />{{ __('Quick replies') }}
            </button>
            @if ($this->showQuickReplies)
                <div class="zz-mail-qr-pop" wire:key="quick-replies" x-on:keydown.escape.stop="$wire.set('showQuickReplies', false)">
                    <input type="search" wire:model.live.debounce.300ms="quickReplySearch" placeholder="{{ __('Search quick replies') }}" aria-label="{{ __('Search quick replies') }}" autofocus>
                    <ul>
                        @forelse ($this->quickReplies as $reply)
                            <li wire:key="qr-{{ $reply->id }}">
                                <button type="button" wire:click="insertQuickReply({{ $reply->id }})">
                                    <b>{{ $reply->shortcut }}</b>
                                    <span>{{ \Illuminate\Support\Str::limit((string) $reply->body, 90) }}</span>
                                </button>
                            </li>
                        @empty
                            <li class="zz-mail-hint">{{ __('No quick replies yet. Add them in CRM → Quick Replies.') }}</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>

        <span class="zz-mail-spacer"></span>
        <button type="button" class="zz-mail-ib" wire:click="discardCompose" wire:confirm="{{ __('Discard this draft?') }}" aria-label="{{ __('Discard draft') }}" title="{{ __('Discard draft') }}">
            <x-filament::icon icon="heroicon-o-trash" class="zz-mail-i" aria-hidden="true" />
        </button>
    </footer>
</section>
