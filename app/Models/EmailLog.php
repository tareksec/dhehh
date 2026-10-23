<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * What happened to one email (see App\Services\EmailLogService): accepted by
 * the mail server, failed with the server's own answer, or not sent (and why).
 * The message text is never kept.
 */
class EmailLog extends Model
{
    use BelongsToCompany;

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const KIND_LOGIN_CODE = 'login_code';

    public const KIND_RESELLER_PASSWORD_RESET = 'reseller_password_reset';

    public const KIND_IMS_CODE = 'ims_code';

    public const KIND_IMS_INVITATION = 'ims_invitation';

    public const KIND_IMS_MESSAGE = 'ims_message';

    public const KIND_OWNER_DIGEST = 'owner_digest';

    public const KIND_TEST = 'test';

    public const KIND_OTHER = 'other';

    protected $fillable = ['company_id', 'kind', 'recipient', 'via', 'status', 'reason', 'detail'];
}
