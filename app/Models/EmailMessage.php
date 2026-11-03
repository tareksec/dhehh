<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message in an email thread: received, sent (or waiting out the undo-send window), a draft, or an internal
 * note that is never sent. The body text and the cleaned body HTML are kept; the raw message is not.
 */
class EmailMessage extends Model
{
    use BelongsToCompany;

    public const IN = 'in';

    public const OUT = 'out';

    public const KIND_MESSAGE = 'message';

    public const KIND_NOTE = 'note';

    public const KIND_DRAFT = 'draft';

    public const STATUS_RECEIVED = 'received';

    /** Written and sent, waiting out the undo-send window. */
    public const STATUS_PENDING = 'pending';

    /** Claimed by the one process that is handing it to the mail server right now. */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_NOTE = 'note';

    protected $fillable = [
        'company_id', 'thread_id', 'mailbox_id', 'direction', 'kind', 'status', 'message_id', 'in_reply_to',
        'references', 'from_address', 'from_name', 'reply_to_addresses', 'to_addresses', 'cc_addresses', 'bcc_addresses', 'subject',
        'body_text', 'body_html', 'is_read', 'sent_at', 'send_after', 'user_id', 'provider_message_id', 'error', 'raw_size',
    ];

    protected function casts(): array
    {
        return [
            'reply_to_addresses' => 'array',
            'to_addresses' => 'array',
            'cc_addresses' => 'array',
            'bcc_addresses' => 'array',
            'is_read' => 'boolean',
            'sent_at' => 'datetime',
            'send_after' => 'datetime',
            'raw_size' => 'integer',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'thread_id');
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(EmailMailbox::class, 'mailbox_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class, 'message_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === self::IN;
    }

    public function isNote(): bool
    {
        return $this->kind === self::KIND_NOTE;
    }

    public function isDraft(): bool
    {
        return $this->kind === self::KIND_DRAFT;
    }
}
