<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * A file kept for transparency against a project, its settlement, an investor
 * payout or a direct-cost line (v3 P1.1). Stored in the owning company's
 * private storage; served only through InvestmentDocumentDownloadController.
 */
class InvestmentDocument extends Model
{
    use BelongsToCompany;

    public const CATEGORY_INVESTOR_NID = 'investor_nid';

    public const CATEGORY_NOMINEE_NID = 'nominee_nid';

    public const CATEGORY_PROJECT_CONTRACT = 'project_contract';

    public const CATEGORIES = [
        self::CATEGORY_INVESTOR_NID => 'Investor NID / Passport copy',
        self::CATEGORY_NOMINEE_NID => 'Nominee NID / Passport copy',
        self::CATEGORY_PROJECT_CONTRACT => 'Project contract (same for all investors)',
        'cheque_copy' => 'Cheque copy',
        'stamp_copy' => 'Stamp copy',
        'settlement_sheet' => 'Settlement Sheet',
        'investor_report' => 'Investor Report',
        'cost_receipt' => 'Cost Receipt / Invoice',
        'purchase_document' => 'Purchase Document',
        'bank_slip' => 'Bank Slip / Transfer Proof',
        'signed_contract' => 'Signed Contract',
        'other' => 'Other',
    ];

    /**
     * Identity papers and contracts: only users with `investments.manage`
     * may list or download them (owner's rule).
     */
    public const SENSITIVE_CATEGORIES = [self::CATEGORY_INVESTOR_NID, self::CATEGORY_NOMINEE_NID, self::CATEGORY_PROJECT_CONTRACT, 'signed_contract', 'cheque_copy', 'stamp_copy'];

    protected $fillable = ['company_id', 'documentable_type', 'documentable_id', 'file_path', 'file_type', 'category', 'label', 'uploaded_by'];

    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            $document->uploaded_by ??= Auth::id();
        });

        static::saving(function (self $document): void {
            $document->company_id ??= $document->documentable?->company_id;

            if (blank($document->file_path) || ! $document->isDirty('file_path')) {
                return;
            }

            $company = $document->company_id
                ? Company::query()->find($document->company_id)
                : $document->documentable?->company;

            if (! $company || ! str_starts_with((string) $document->file_path, $company->storageRoot().'/private/')) {
                throw new LogicException('Investment documents must use the owning company private storage path.');
            }
        });
    }

    public function isSensitive(): bool
    {
        return in_array($this->category, self::SENSITIVE_CATEGORIES, true) || $this->documentable_type === (new Investor)->getMorphClass();
    }

    public static function categoryLabel(?string $category): string
    {
        return __(self::CATEGORIES[$category] ?? (string) $category);
    }

    /** @return array<string, string> */
    public static function categoryOptions(): array
    {
        return collect(self::CATEGORIES)->map(fn (string $label): string => __($label))->all();
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
