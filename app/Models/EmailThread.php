<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One conversation in the Email inbox: the messages that belong together (joined by their In-Reply-To /
 * References headers, else by subject and sender), with its folder, stars, assignee, status and the customer,
 * lead and order it is about. Sent, Drafts, Starred, Snoozed and All mail are views over these rows, not folders.
 * A snoozed conversation stays in the Inbox folder but is hidden from the Inbox view until `snoozed_until`.
 */
class EmailThread extends Model
{
    use BelongsToCompany;

    public const FOLDER_INBOX = 'inbox';

    public const FOLDER_ARCHIVED = 'archived';

    public const FOLDER_SPAM = 'spam';

    public const FOLDER_TRASH = 'trash';

    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLOSED = 'closed';

    public const FOLDERS = [self::FOLDER_INBOX, self::FOLDER_ARCHIVED, self::FOLDER_SPAM, self::FOLDER_TRASH];

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_PENDING, self::STATUS_CLOSED];

    protected $fillable = [
        'company_id', 'mailbox_id', 'subject', 'snippet', 'folder', 'is_starred', 'unread_count', 'message_count',
        'has_attachments', 'last_message_at', 'last_inbound_at', 'last_outbound_at', 'contact_email', 'contact_name',
        'assigned_to', 'status', 'customer_id', 'lead_id', 'order_id', 'snoozed_until', 'snoozed_by', 'trashed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_starred' => 'boolean',
            'has_attachments' => 'boolean',
            'unread_count' => 'integer',
            'message_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'trashed_at' => 'datetime',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(EmailMailbox::class, 'mailbox_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'thread_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(EmailLabel::class, 'email_label_thread', 'thread_id', 'label_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Hidden from the Inbox until the snooze time (the Snoozed view lists it meanwhile). */
    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null;
    }

    public function isUnread(): bool
    {
        return $this->unread_count > 0;
    }

    /** The last word was the customer's: nobody has answered yet ("Needs reply"). */
    public function needsReply(): bool
    {
        return $this->last_inbound_at !== null
            && ($this->last_outbound_at === null || $this->last_inbound_at->gt($this->last_outbound_at));
    }
}
