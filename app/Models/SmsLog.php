<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\SmsSegments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened to one order SMS (see App\Services\SmsLogService): waiting in
 * the queue, sent, refused by the gateway, or skipped (and why).
 */
class SmsLog extends Model
{
    use BelongsToCompany;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const KIND_ORDER_PLACED = 'order_placed';

    public const KIND_ORDER_STATUS = 'order_status';

    public const KIND_TEST = 'test';

    /** Every other SMS the system sends (login and recovery codes, reminders, broadcasts): counted, never stored as text. */
    public const KIND_OTHER = 'other';

    protected $fillable = [
        'company_id', 'kind', 'order_id', 'reference', 'phone', 'message', 'segments', 'status', 'reason', 'gateway_code', 'detail',
    ];

    protected function casts(): array
    {
        return ['gateway_code' => 'integer', 'segments' => 'integer'];
    }

    /** The SMS parts this row used: the stored count, else counted from the text of an older row, else one. */
    public function parts(): int
    {
        return $this->segments ?? max(SmsSegments::count((string) $this->message), 1);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }
}
