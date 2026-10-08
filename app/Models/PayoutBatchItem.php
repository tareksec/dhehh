<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One payout in a payout batch, with a copy of the payment account it is sent
 * to (the account may change later; the batch keeps what was paid to). A
 * payable is held by at most one live batch: a failed item frees it.
 */
class PayoutBatchItem extends Model
{
    use BelongsToCompany;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_PENDING => 'Pending', self::STATUS_PAID => 'Paid', self::STATUS_FAILED => 'Failed'];

    protected $fillable = [
        'company_id', 'payout_batch_id', 'payable_type', 'payable_id', 'payee_type', 'payee_id', 'investor_bank_account_id',
        'payee_name', 'account_type', 'bank_name', 'branch_name', 'routing_number', 'account_name', 'account_number',
        'mfs_provider', 'mfs_number', 'amount',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected $casts = ['amount' => 'decimal:2', 'account_number' => 'encrypted', 'paid_at' => 'date'];

    protected $hidden = ['account_number'];

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->isDirty('account_number')) {
                $digits = preg_replace('/\D+/', '', (string) $item->account_number) ?? '';
                $item->account_last4 = $digits === '' ? null : substr($digits, -4);
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function payee(): MorphTo
    {
        return $this->morphTo();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(InvestorBankAccount::class, 'investor_bank_account_id');
    }

    /** Items that still hold their payable: pending, in a batch not yet completed or cancelled. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereHas('batch', fn (Builder $batch) => $batch->whereIn('status', PayoutBatch::LIVE_STATUSES));
    }

    /** "Bank name, branch — ••••1234" or "bKash 01XXXXXXXXX". */
    public function accountDisplay(): string
    {
        if ($this->account_type === InvestorBankAccount::TYPE_MFS) {
            return trim((InvestorBankAccount::MFS_PROVIDERS[$this->mfs_provider] ?? (string) $this->mfs_provider).' '.$this->mfs_number);
        }

        return trim(collect([$this->bank_name, $this->branch_name])->filter()->implode(', ').($this->account_last4 ? ' — ••••'.$this->account_last4 : ''));
    }

    public static function statusLabel(?string $status): string
    {
        return __(self::STATUSES[$status] ?? (string) $status);
    }
}
