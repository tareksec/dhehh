<?php

namespace App\Filament\Pages\Concerns;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\QuickReply;
use App\Services\Email\Inbox\EmailSendException;
use App\Services\Email\Inbox\OutboundEmailService;
use App\Support\EmailAddressList;
use App\Support\EmailText;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Writing in the Email page (owner request 2026-11-02): the compose window for a new message, a reply, a reply to all
 * and a forward; the draft that is saved by itself while the person types; files; Quick Replies; Send with its
 * undo window; a message that failed going again; internal notes. All of it goes through OutboundEmailService, which
 * checks every record against the current company; this trait only holds what is on screen and who may press what
 * (`crm.manage`).
 */
trait ComposesEmail
{
    /** The draft in the compose window; null while the window is closed. */
    public ?int $composeId = null;

    public string $composeTo = '';

    public string $composeCc = '';

    public string $composeBcc = '';

    public string $composeSubject = '';

    /** @var array<string, mixed> The editor's own state (a rich-text document); composeBodyHtml() is the HTML of it. */
    public ?array $composeData = ['body' => ''];

    public bool $composeShowCc = false;

    public bool $composeShowBcc = false;

    /** @var array<int, string> Addresses the person typed that are not valid. */
    public array $composeInvalid = [];

    public ?string $composeSavedAt = null;

    /** What a draft the window just made looked like before anyone typed in it ('' for one opened again); an unchanged one is thrown away on Close. */
    public string $composeBaseline = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $composeUploads = [];

    public bool $showQuickReplies = false;

    public string $quickReplySearch = '';

    /** The message just sent, while it can still be taken back: {id: int, seconds: int}. */
    public ?array $sentUndo = null;

    public string $noteText = '';

    public bool $noteOpen = false;

    public function composeForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('composeData')
            ->components([
                RichEditor::make('body')
                    ->hiddenLabel()
                    ->toolbarButtons([['bold', 'italic', 'underline', 'strike', 'link'], ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']])
                    ->extraAttributes(['class' => 'zz-mail-editor'])
                    ->placeholder(__('Write your message')),
            ]);
    }

    // --- Opening the window -----------------------------------------------------------------------------

    public function composeNew(): void
    {
        $this->authorizeManage();

        if ($this->mailbox === null) {
            return;
        }

        $this->loadCompose($this->outbound()->startNew($this->mailbox, $this->user()));
    }

    public function composeReply(int $messageId, bool $all = false): void
    {
        $this->authorizeManage();
        $original = $this->messageOfOpenThread($messageId);

        if ($original !== null) {
            $this->loadCompose($this->outbound()->startReply($original, $this->user(), $all));
        }
    }

    public function composeForward(int $messageId): void
    {
        $this->authorizeManage();
        $original = $this->messageOfOpenThread($messageId);

        if ($original !== null) {
            $this->loadCompose($this->outbound()->startForward($original, $this->user()));
        }
    }

    /** Opens one of the signed-in person's own drafts again. */
    public function editDraft(int $messageId): void
    {
        $this->authorizeManage();
        $draft = $this->ownDraft($messageId);

        if ($draft !== null) {
            $this->loadCompose($draft);
        }
    }

    protected function loadCompose(EmailMessage $draft): void
    {
        $label = fn (?array $list): string => collect((array) $list)->map(fn ($entry): string => EmailAddressList::label((array) $entry))->implode(', ');

        $this->composeId = $draft->getKey();
        $this->composeBaseline = $draft->wasRecentlyCreated ? $this->fingerprint($draft) : '';
        $this->composeTo = $label($draft->to_addresses);
        $this->composeCc = $label($draft->cc_addresses);
        $this->composeBcc = $label($draft->bcc_addresses);
        $this->composeSubject = (string) $draft->subject;
        $this->composeShowCc = $this->composeCc !== '';
        $this->composeShowBcc = $this->composeBcc !== '';
        $this->composeInvalid = [];
        $this->composeSavedAt = null;
        $this->showQuickReplies = false;
        $this->composeUploads = [];
        $this->composeForm->fill(['body' => (string) $draft->body_html]);
        $this->forgetData();
    }

    // --- The draft --------------------------------------------------------------------------------------

    /** Saves what is typed. Called by the page every couple of seconds while the person types, and before Send and Close. */
    public function saveCompose(): void
    {
        $this->authorizeManage();
        $draft = $this->composeDraft();

        if ($draft === null) {
            return;
        }

        $result = $this->outbound()->saveDraft($draft, [
            'to' => $this->composeTo,
            'cc' => $this->composeCc,
            'bcc' => $this->composeBcc,
            'subject' => $this->composeSubject,
            'body_html' => $this->composeBodyHtml(),
        ]);

        $this->composeInvalid = $result['invalid'];
        $this->composeSavedAt = $this->shortTime(now());
        $this->forgetData();
    }

    /** Closes the window and keeps the draft; a draft nobody typed anything in is thrown away. */
    public function closeCompose(): void
    {
        $this->authorizeManage();

        if ($this->composeId !== null) {
            $this->saveCompose();
            $draft = $this->composeDraft();

            if ($draft !== null && $this->composeBaseline !== '' && $this->composeBaseline === $this->fingerprint($draft)) {
                $this->outbound()->discardDraft($draft);
            }
        }

        $this->resetCompose();
    }

    public function discardCompose(): void
    {
        $this->authorizeManage();
        $draft = $this->composeDraft();

        if ($draft !== null) {
            $this->outbound()->discardDraft($draft);
        }

        $this->resetCompose();
    }

    protected function resetCompose(): void
    {
        $this->composeId = null;
        $this->composeTo = $this->composeCc = $this->composeBcc = $this->composeSubject = '';
        $this->composeShowCc = $this->composeShowBcc = $this->showQuickReplies = false;
        $this->composeInvalid = [];
        $this->composeSavedAt = null;
        $this->composeUploads = [];
        $this->composeForm->fill(['body' => '']);

        // A conversation that was only the discarded draft is gone; do not stay on it.
        if ($this->threadId !== null && $this->thread === null) {
            $this->threadId = null;
        }

        $this->forgetData();
    }

    // --- Files ------------------------------------------------------------------------------------------

    public function updatedComposeUploads(): void
    {
        $this->authorizeManage();
        $draft = $this->composeDraft();

        if ($draft === null) {
            $this->composeUploads = [];

            return;
        }

        foreach ($this->composeUploads as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            try {
                $this->outbound()->addAttachment($draft, $file->getClientOriginalName(), (string) $file->getMimeType(), (string) file_get_contents($file->getRealPath()));
            } catch (EmailSendException $exception) {
                Notification::make()->title($exception->getMessage())->danger()->send();
            }
        }

        $this->composeUploads = [];
        $this->forgetData();
    }

    public function removeComposeFile(int $attachmentId): void
    {
        $this->authorizeManage();
        $draft = $this->composeDraft();
        $file = $draft?->attachments()->find($attachmentId);

        if ($file instanceof EmailAttachment) {
            $this->outbound()->removeAttachment($file);
            $this->forgetData();
        }
    }

    /** @return Collection<int, EmailAttachment> */
    #[Computed]
    public function composeFiles(): Collection
    {
        return $this->composeId === null ? new Collection : ($this->composeDraft()?->attachments()->orderBy('id')->get() ?? new Collection);
    }

    // --- Quick Replies ----------------------------------------------------------------------------------

    /** @return Collection<int, QuickReply> */
    #[Computed]
    public function quickReplies(): Collection
    {
        if (! $this->showQuickReplies) {
            return new Collection;
        }

        $search = trim($this->quickReplySearch);

        return QuickReply::query()
            ->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('shortcut', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('body', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('shortcut')
            ->limit(40)
            ->get(['id', 'shortcut', 'body']);
    }

    /**
     * Puts the saved text into the message where the person's cursor is (at the top when they have not clicked into it
     * yet, above the signature and the quoted mail), as paragraphs. The editor does it in the browser.
     */
    public function insertQuickReply(int $quickReplyId): void
    {
        $this->authorizeManage();
        $reply = QuickReply::query()->where('is_active', true)->find($quickReplyId);

        if ($reply === null || $this->composeId === null) {
            return;
        }

        $html = '<p>'.EmailText::toHtml((string) $reply->body).'</p>';
        $editor = collect($this->composeForm->getFlatComponents())->first(fn ($component): bool => $component instanceof RichEditor);

        if ($editor instanceof RichEditor) {
            $editor->runCommands([EditorCommand::make('insertContent', [$html])]);
        } else {
            $this->composeForm->fill(['body' => $this->composeBodyHtml().$html]);
        }

        $this->showQuickReplies = false;
        $this->quickReplySearch = '';
        unset($this->quickReplies);
    }

    // --- Sending ----------------------------------------------------------------------------------------

    public function sendCompose(): void
    {
        $this->authorizeManage();
        $this->saveCompose();
        $draft = $this->composeDraft();

        if ($draft === null) {
            return;
        }

        if ($this->composeInvalid !== []) {
            Notification::make()->title(__('Check these addresses: :list', ['list' => implode(', ', $this->composeInvalid)]))->danger()->send();

            return;
        }

        try {
            $message = $this->outbound()->queue($draft, $this->user());
        } catch (EmailSendException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $undo = max(0, (int) $this->mailbox?->setting('undo_send_seconds'));
        $this->sentUndo = $message->status === EmailMessage::STATUS_SENT || $undo === 0 ? null : ['id' => $message->getKey(), 'seconds' => $undo];

        if ($message->status === EmailMessage::STATUS_SENT) {
            Notification::make()->title(__('Message sent'))->success()->send();
        } elseif ($message->status === EmailMessage::STATUS_FAILED) {
            Notification::make()->title(__('The message was not sent: :reason', ['reason' => (string) $message->error]))->danger()->send();
        }

        $this->resetCompose();
        $this->threadId ??= $message->thread_id;
        $this->forgetData();
    }

    /** Takes the message just sent back into the compose window, if its undo window is still open. */
    public function undoSend(int $messageId): void
    {
        $this->authorizeManage();
        $message = EmailMessage::query()->where('user_id', $this->user()->getKey())->find($messageId);
        $this->sentUndo = null;

        if ($message !== null && $this->outbound()->undo($message)) {
            $this->loadCompose($message->fresh());
            Notification::make()->title(__('Sending cancelled. Your message is open again.'))->success()->send();

            return;
        }

        Notification::make()->title(__('Too late to take it back. The message has been sent.'))->warning()->send();
    }

    /** Called by the page when the undo window is over: sends what is due and says if it did not go. */
    public function deliverDue(): void
    {
        $this->authorizeManage();
        $watched = $this->sentUndo['id'] ?? null;
        $this->sentUndo = null;
        $this->outbound()->deliverDue();

        if ($watched !== null) {
            $message = EmailMessage::query()->find($watched);

            if ($message?->status === EmailMessage::STATUS_FAILED) {
                Notification::make()->title(__('The message was not sent: :reason', ['reason' => (string) $message->error]))->danger()->send();
            } elseif ($message?->status === EmailMessage::STATUS_SENT) {
                Notification::make()->title(__('Message sent'))->success()->send();
            }
        }

        $this->forgetData();
    }

    /** A message that failed goes again. */
    public function retryMessage(int $messageId): void
    {
        $this->authorizeManage();
        $message = $this->messageOfOpenThread($messageId);

        if ($message === null) {
            return;
        }

        if ($this->outbound()->retry($message)) {
            Notification::make()->title(__('Message sent'))->success()->send();
        } else {
            Notification::make()->title(__('The message was not sent: :reason', ['reason' => (string) $message->fresh()?->error]))->danger()->send();
        }

        $this->forgetData();
    }

    // --- Notes ------------------------------------------------------------------------------------------

    public function addNote(): void
    {
        $this->authorizeManage();

        if ($this->thread === null) {
            return;
        }

        try {
            $this->outbound()->addNote($this->thread, $this->user(), $this->noteText);
        } catch (EmailSendException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $this->noteText = '';
        $this->noteOpen = false;
        $this->forgetData();
    }

    // --- Helpers ----------------------------------------------------------------------------------------

    /** The message the editor holds, as HTML (the editor keeps a document of its own in the page's state). */
    protected function composeBodyHtml(): string
    {
        return (string) ($this->composeForm->getState()['body'] ?? '');
    }

    protected function outbound(): OutboundEmailService
    {
        return app(OutboundEmailService::class);
    }

    /** The draft in the window, if it is still the signed-in person's own draft. */
    protected function composeDraft(): ?EmailMessage
    {
        return $this->composeId === null ? null : $this->ownDraft($this->composeId);
    }

    protected function ownDraft(int $messageId): ?EmailMessage
    {
        return EmailMessage::query()
            ->where('kind', EmailMessage::KIND_DRAFT)
            ->where('status', EmailMessage::STATUS_DRAFT)
            ->where('user_id', $this->user()->getKey())
            ->where('mailbox_id', $this->mailbox?->getKey())
            ->find($messageId);
    }

    /** A message of the open conversation (only one the person may see). */
    protected function messageOfOpenThread(int $messageId): ?EmailMessage
    {
        $thread = $this->thread;

        return $thread === null ? null : EmailMessage::query()->where('thread_id', $thread->getKey())->where('kind', EmailMessage::KIND_MESSAGE)->find($messageId);
    }

    /** What identifies the content of a draft, without the editor's spacing: who, what subject, what text, how many files. */
    protected function fingerprint(EmailMessage $draft): string
    {
        return md5(json_encode([
            $draft->to_addresses, $draft->cc_addresses, $draft->bcc_addresses,
            trim((string) $draft->subject),
            preg_replace('/\s+/u', ' ', trim((string) $draft->body_text)),
            $draft->attachments()->count(),
        ]));
    }
}
