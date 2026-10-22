<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One bKash / Nagad / Rocket / bank statement file read in for matching (IMS-V2/PRD.md FR-24). */
class BankStatementImport extends Model
{
    use BelongsToCompany;

    public const SOURCES = ['bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank' => 'Bank'];

    protected $fillable = ['company_id', 'source', 'file_name', 'lines_count', 'matched_count', 'imported_by'];

    protected $casts = ['lines_count' => 'integer', 'matched_count' => 'integer'];

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'import_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
