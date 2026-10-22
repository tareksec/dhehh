<?php

namespace App\Ims\Reconciliation;

use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Investment;
use App\Models\InvestorGroupMember;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Matches statement lines to money that is waiting to be confirmed
 * (IMS-V2/PRD.md FR-24, ROLES §3.3). A line can only match a waiting
 * investment or group deposit of exactly the same amount. When the
 * transaction id is the one the investor gave, or only one waiting record
 * has that amount, it is suggested. The accountant marks the match; making
 * the investment active stays with someone who has `ims.investments.confirm`.
 */
class Matcher
{
    public function __construct(private AuditLogService $audit) {}

    /** @return int lines with a suggestion */
    public function suggest(BankStatementImport $import): int
    {
        $suggested = 0;

        foreach ($import->lines()->where('status', BankStatementLine::STATUS_UNMATCHED)->get() as $line) {
            $candidate = $this->bestCandidate($line);

            if ($candidate !== null) {
                $line->forceFill(['status' => BankStatementLine::STATUS_SUGGESTED, 'matched_type' => $candidate->getMorphClass(), 'matched_id' => $candidate->getKey()])->save();
                $suggested++;
            }
        }

        return $suggested;
    }

    /** @return Collection<int, Investment|InvestorGroupMember> waiting records of exactly this amount, not matched yet */
    public function candidates(BankStatementLine $line): Collection
    {
        $taken = BankStatementLine::query()->where('status', BankStatementLine::STATUS_MATCHED)->whereKeyNot($line->getKey())
            ->get(['matched_type', 'matched_id'])->map(fn (BankStatementLine $row): string => $row->matched_type.'#'.$row->matched_id)->all();

        $amount = number_format((float) $line->amount, 2, '.', '');

        return InvestorGroupMember::query()->pending()->where('amount', $amount)->with('investor')->get()
            ->concat(Investment::query()->pending()->where('amount', $amount)->with('investor')->get())
            ->reject(fn ($record): bool => in_array($record->getMorphClass().'#'.$record->getKey(), $taken, true))
            ->values();
    }

    public function bestCandidate(BankStatementLine $line): Investment|InvestorGroupMember|null
    {
        $candidates = $this->candidates($line);

        if (filled($line->reference)) {
            $byReference = $candidates->first(fn ($record): bool => filled($record->payment_reference) && strcasecmp(trim((string) $record->payment_reference), trim((string) $line->reference)) === 0);

            if ($byReference !== null) {
                return $byReference;
            }
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    public function markMatched(BankStatementLine $line, Investment|InvestorGroupMember $record, User $by): BankStatementLine
    {
        if (! $by->hasPermission('ims.reconcile')) {
            throw ValidationException::withMessages(['line' => __('You do not have permission to match statements.')]);
        }

        if (! $record->isPending() || round((float) $record->amount, 2) !== round((float) $line->amount, 2)) {
            throw ValidationException::withMessages(['line' => __('A line can be matched only to waiting money of exactly the same amount.')]);
        }

        if (! $this->candidates($line)->contains(fn ($candidate): bool => $candidate->is($record))) {
            throw ValidationException::withMessages(['line' => __('This money is already matched to another line.')]);
        }

        $line->forceFill([
            'status' => BankStatementLine::STATUS_MATCHED,
            'matched_type' => $record->getMorphClass(),
            'matched_id' => $record->getKey(),
            'matched_by' => $by->getKey(),
            'matched_at' => now(),
        ])->save();

        $this->audit->record('ims.statement_line_matched', $line, null, ['matched' => class_basename($record).' #'.$record->getKey(), 'by' => $by->getKey()]);

        return $line;
    }

    public function ignore(BankStatementLine $line, User $by): void
    {
        if (! $by->hasPermission('ims.reconcile')) {
            throw ValidationException::withMessages(['line' => __('You do not have permission to match statements.')]);
        }

        $line->forceFill(['status' => BankStatementLine::STATUS_IGNORED, 'matched_type' => null, 'matched_id' => null])->save();
    }
}
