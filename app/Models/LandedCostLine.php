<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cost of an import beyond the goods (SUMMARY.md §4 report 3), on the
 * LC file's landed cost worksheet, with where the figure came from (PLAN.md
 * §2: duty, insurance and C&F from the C&F agent; truck fare and loading from
 * the owner). Not part of the purchase total or the supplier's due.
 */
class LandedCostLine extends Model
{
    use BelongsToCompany;

    /** Where a figure came from. */
    public const SOURCES = [
        'cnf_agent' => 'C&F agent',
        'owner' => 'Owner',
        'bank' => 'Bank',
        'other' => 'Other',
    ];

    /**
     * The kinds of cost, in the order of the worksheet (SUMMARY.md §4 report 3),
     * each with its usual source. "other" takes its own name.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const KINDS = [
        'bank' => ['Bank charges, LC commission, SWIFT', 'bank'],
        'insurance' => ['Marine insurance premium', 'cnf_agent'],
        'duty' => ['Customs duty, AIT, ATV, RD', 'cnf_agent'],
        'port' => ['Port and shipping line charges', 'cnf_agent'],
        'cnf' => ['C&F agent commission and clearing', 'cnf_agent'],
        'psi' => ['Inspection (PSI) fee', 'other'],
        'truck' => ['Truck fare', 'owner'],
        'loading' => ['Loading and unloading', 'owner'],
        'other' => ['Other cost', 'other'],
    ];

    protected $fillable = ['company_id', 'lc_file_id', 'kind', 'label', 'source', 'amount', 'paid_on', 'note'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
    ];

    public function lcFile(): BelongsTo
    {
        return $this->belongsTo(LcFile::class);
    }

    public static function sourceLabel(?string $source): string
    {
        return __(self::SOURCES[$source] ?? (string) $source);
    }

    /** The line's name as shown: the kind's name, or its own for "other". */
    public function displayLabel(): string
    {
        return $this->kind === 'other' || ! isset(self::KINDS[$this->kind]) ? (string) $this->label : __(self::KINDS[$this->kind][0]);
    }
}
