<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A KYC picture (IMS-V2/PRD.md FR-10): the NID card's front and back and a
 * selfie, on the private disk. The OCR result is encrypted at rest.
 */
class InvestorKycDocument extends Model
{
    use BelongsToCompany;

    public const TYPE_NID_FRONT = 'nid_front';

    public const TYPE_NID_BACK = 'nid_back';

    public const TYPE_SELFIE = 'selfie';

    public const TYPE_OTHER = 'other';

    /** The three pictures a KYC needs. */
    public const REQUIRED = [self::TYPE_NID_FRONT, self::TYPE_NID_BACK, self::TYPE_SELFIE];

    public const TYPES = [
        self::TYPE_NID_FRONT => 'NID front',
        self::TYPE_NID_BACK => 'NID back',
        self::TYPE_SELFIE => 'Selfie',
        self::TYPE_OTHER => 'Other document',
    ];

    protected $fillable = ['company_id', 'investor_id', 'type', 'path', 'mime', 'ocr_result', 'uploaded_by_type', 'uploaded_by_id'];

    protected $casts = ['ocr_result' => 'encrypted:array'];

    protected $hidden = ['path', 'ocr_result'];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public static function typeLabel(?string $type): string
    {
        return __(self::TYPES[$type] ?? (string) $type);
    }
}
