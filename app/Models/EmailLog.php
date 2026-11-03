<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * What happened to one email (see App\Services\EmailLogService): accepted by
 * the mail server, failed with the server's own answer, or not sent (and why).
 * With Amazon SES event tracking on, a sent row later becomes delivered, bounced
 * or complained (App\Services\Ses\SesEventService).
 * The message text is never kept.
 */
class EmailLog extends Model
{
    use BelongsToCompany;

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /** Amazon SES reported the message reached the recipient's mail server (needs bounce tracking on). */
    public const STATUS_DELIVERED = 'delivered';

    /** Amazon SES reported that the recipient's mail server refused the message. */
    public const STATUS_BOUNCED = 'bounced';

    /** The recipient marked the message as spam. */
    public const STATUS_COMPLAINED = 'complained';

    public const KIND_LOGIN_CODE = 'login_code';

    public const KIND_RESELLER_PASSWORD_RESET = 'reseller_password_reset';

    public const KIND_IMS_CODE = 'ims_code';

    public const KIND_IMS_INVITATION = 'ims_invitation';

    public const KIND_IMS_MESSAGE = 'ims_message';

    public const KIND_OWNER_DIGEST = 'owner_digest';

    /** An order email written on Settings → Email Templates (placed, confirmed, shipped ...). */
    public const KIND_ORDER = 'order';

    /** One of the owner's own templates, sent by hand from an order. */
    public const KIND_TEMPLATE = 'template';

    /** A message a staff member wrote in the Email inbox (a reply, a forward or a new message). */
    public const KIND_STAFF_REPLY = 'staff_reply';

    public const KIND_TEST = 'test';

    public const KIND_OTHER = 'other';

    protected $fillable = ['company_id', 'kind', 'recipient', 'via', 'message_id', 'status', 'reason', 'detail'];
}
