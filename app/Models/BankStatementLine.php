<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One money-in line of a statement. Lines are matched to a pending
 * investment or group deposit only when the amount is exactly the same; the
 * accountant marks the match, and someone with `ims.investments.confirm`
 * then makes the investment active (IMS-V2/ROLES.md §3.3).
 */
class BankStatementLine extends Model
{
    use BelongsToCompany;

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [
        self::STATUS_UNMATCHED => 'No match',
        self::STATUS_SUGGESTED => 'Possible match',
        self::STATUS_MATCHED => 'Matched',
        self::STATUS_IGNORED => 'Ignored',
    ];

    protected $fillable = ['company_id', 'import_id', 'line_hash', 'transacted_on', 'amount', 'reference', 'counterparty', 'status', 'matched_type', 'matched_id', 'matched_by', 'matched_at'];

    protected $casts = ['transacted_on' => 'date', 'amount' => 'decimal:2', 'matched_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_UNMATCHED];

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'import_id');
    }

    public function matched(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'matched_type', 'matched_id');
    }

    public static function statusLabel(?string $status): string
    {
        return __(self::STATUSES[$status] ?? (string) $status);
    }
}
