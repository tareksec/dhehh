<?php

namespace App\Services\Investment;

use App\Models\Investment;
use App\Models\Investor;
use App\Models\InvestorGroupMember;
use App\Models\ProjectSettlement;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an investor together with what only exists because of them (owner
 * 2026-10-28: "remove all the investors and import them again, properly").
 *
 * It is a clean-up for investors whose money was never paid out or booked: an
 * investor with a withdrawal, a payout, a capital return, a voucher on any
 * deposit or an investment in a settled project is never deleted (`blocker()`
 * says why), because that history is the accounts' record. Everything else of
 * theirs goes: investments, group member copies, nominees, paper copies,
 * KYC rows, bank accounts, requests, the portal login. Files already stored on
 * the private disk are not removed. Each deletion is written to the audit log.
 */
class InvestorDeletionService
{
    /** Why this investor must be kept (null = may be deleted). */
    public function blocker(Investor $investor): ?string
    {
        $investments = Investment::query()->where('investor_id', $investor->getKey());

        if ((clone $investments)->where(fn ($query) => $query
            ->where('status', Investment::STATUS_WITHDRAWN)
            ->orWhereNotNull('withdrawal_voucher_id'))->exists()) {
            return __('has a withdrawn investment');
        }

        if ((clone $investments)->whereNotNull('voucher_id')->exists()
            || InvestorGroupMember::query()->where('investor_id', $investor->getKey())
                ->where(fn ($query) => $query->whereNotNull('voucher_id')->orWhereNotNull('withdrawal_voucher_id'))->exists()) {
            return __('has money booked in the accounts (a voucher)');
        }

        if ($investor->settlementPayouts()->exists()) {
            return __('has a profit payout');
        }

        if ($investor->capitalReturns()->exists()) {
            return __('has a capital return');
        }

        if (ProjectSettlement::query()->whereIn('project_id', (clone $investments)->select('project_id'))->exists()) {
            return __('is in a settled project');
        }

        return null;
    }

    /** @throws \RuntimeException when the investor has to be kept */
    public function delete(Investor $investor): void
    {
        if (($reason = $this->blocker($investor)) !== null) {
            throw new \RuntimeException("{$investor->name} {$reason}.");
        }

        DB::transaction(function () use ($investor): void {
            app(AuditLogService::class)->record('investor_deleted', $investor, [
                'name' => $investor->name,
                'phone' => $investor->phone,
                'investments' => $investor->investments()->count(),
                'invested' => round((float) $investor->investments()->sum('amount'), 2),
                'group_memberships' => $investor->groupMemberships()->count(),
            ]);

            $investor->portalAccount?->delete();
            $investor->documents()->delete();

            // Plain deletes: the model refuses to drop a member of a running group, and
            // the foreign keys take the rest (nominees, KYC rows, bank accounts, requests).
            DB::table('investments')->where('investor_id', $investor->getKey())->delete();
            DB::table('investor_group_members')->where('investor_id', $investor->getKey())->delete();

            $investor->delete();
        });
    }
}
