<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
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

    protected $fillable = [
        'company_id', 'kind', 'order_id', 'reference', 'phone', 'message', 'status', 'reason', 'gateway_code', 'detail',
    ];

    protected function casts(): array
    {
        return ['gateway_code' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }
}
