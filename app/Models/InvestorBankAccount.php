<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\ValidationException;

/**
 * A bank or mobile-money account an investor or channel partner is paid to
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §5, Phase 3). One added from the
 * portal stays pending until staff approve it; an approved account is never
 * edited — a change is a new account.
 */
class InvestorBankAccount extends Model
{
    use BelongsToCompany;

    public const TYPE_BANK = 'bank';

    public const TYPE_MFS = 'mfs';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING => 'Pending', self::STATUS_APPROVED => 'Approved', self::STATUS_REJECTED => 'Rejected'];

    public const MFS_PROVIDERS = ['bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket'];

    protected $fillable = ['company_id', 'holder_type', 'holder_id', 'type', 'bank_name', 'branch_name', 'routing_number', 'account_name', 'account_number', 'mfs_provider', 'mfs_number', 'source'];

    protected $attributes = ['status' => self::STATUS_PENDING, 'source' => 'portal'];

    protected $casts = ['account_number' => 'encrypted', 'is_default' => 'boolean', 'approved_at' => 'datetime'];

    protected $hidden = ['account_number'];

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if ($account->isDirty('account_number')) {
                $digits = preg_replace('/\D+/', '', (string) $account->account_number) ?? '';
                $account->account_last4 = $digits === '' ? null : substr($digits, -4);
            }

            $holder = $account->holder_type ? $account->holder()->withoutGlobalScopes()->first() : null;

            if (! $holder || (int) $holder->company_id !== (int) $account->company_id) {
                throw ValidationException::withMessages(['holder_id' => __('The account holder must belong to the same company.')]);
            }

            if ($account->exists && $account->getOriginal('status') === self::STATUS_APPROVED
                && $account->isDirty(['type', 'bank_name', 'branch_name', 'routing_number', 'account_name', 'account_number', 'mfs_provider', 'mfs_number'])) {
                throw ValidationException::withMessages(['account_number' => __('An approved payment account cannot be changed. Add a new one instead.')]);
            }
        });
    }

    public function holder(): MorphTo
    {
        return $this->morphTo();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** "Bank name, branch — ••••1234" or "bKash 01XXXXXXXXX". */
    public function displayName(): string
    {
        if ($this->type === self::TYPE_MFS) {
            return trim((self::MFS_PROVIDERS[$this->mfs_provider] ?? (string) $this->mfs_provider).' '.$this->mfs_number);
        }

        return trim(collect([$this->bank_name, $this->branch_name])->filter()->implode(', ').($this->account_last4 ? ' — ••••'.$this->account_last4 : ''));
    }

    public static function statusLabel(?string $status): string
    {
        return __(self::STATUSES[$status] ?? (string) $status);
    }
}
