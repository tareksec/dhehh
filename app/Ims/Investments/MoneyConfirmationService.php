<?php

namespace App\Ims\Investments;

use App\Ims\Kyc\KycService;
use App\Ims\Notifications\ImsNotifier;
use App\Models\Investment;
use App\Models\InvestorGroupMember;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Investment\InvestmentLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Waiting for the money" → active (IMS-V2/PRD.md FR-22, FR-24, ROLES §3.3).
 * Someone with `ims.investments.confirm` says the money arrived (after
 * looking at the bank / bKash statement); the investment becomes active, the
 * receiving voucher is booked, and the investor is told. While IMS is on, a
 * new investor's money becomes active only with a verified KYC (FR-14).
 * A group deposit can be confirmed only while its group is still Open.
 */
class MoneyConfirmationService
{
    public function __construct(
        private InvestmentLedgerService $ledger,
        private ImsNotifier $notifier,
        private AuditLogService $audit,
    ) {}

    public function confirm(Investment|InvestorGroupMember $record, User $by): Investment|InvestorGroupMember
    {
        if (! $by->hasPermission('ims.investments.confirm')) {
            throw ValidationException::withMessages(['status' => __('You do not have permission to confirm money received.')]);
        }

        $confirmed = DB::transaction(function () use ($record, $by): Investment|InvestorGroupMember {
            $locked = $record::query()->lockForUpdate()->findOrFail($record->getKey());

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['status' => __('This money is already confirmed.')]);
            }

            $investor = $locked->investor()->firstOrFail();

            if (KycService::blocksNewMoney($investor)) {
                throw ValidationException::withMessages(['status' => __('The investor\'s KYC is not verified yet. Verify the KYC first, then confirm the money.')]);
            }

            if ($locked instanceof InvestorGroupMember && ! $locked->group?->isOpen()) {
                throw ValidationException::withMessages(['status' => __('This group has already started, so new money cannot join it. Put the money in an open group instead.')]);
            }

            $locked->forceFill([
                'status' => $locked instanceof InvestorGroupMember ? InvestorGroupMember::STATUS_ACTIVE : Investment::STATUS_ACTIVE,
                'confirmed_at' => now(),
                'confirmed_by' => $by->getKey(),
                'received_by' => $locked->received_by ?? $by->getKey(),
            ])->save();

            $this->audit->record('ims.money_confirmed', $locked, ['status' => 'pending'], ['status' => 'active', 'by' => $by->getKey()]);

            return $locked;
        });

        // After the commit, so a voucher failure never undoes the confirmation.
        if ($confirmed instanceof InvestorGroupMember) {
            $this->ledger->recordGroupDepositReceived($confirmed);
        } else {
            $this->ledger->recordInvestmentReceived($confirmed);
        }

        $this->notifier->send('investment_confirmed', $confirmed->investor, ['amount' => (float) $confirmed->amount]);

        $record->setRawAttributes($confirmed->refresh()->getAttributes(), true);

        return $confirmed;
    }

    /** Removes a pending record that will never be paid (no voucher was ever booked for it). */
    public function discard(Investment|InvestorGroupMember $record, User $by, string $reason): void
    {
        if (! $by->hasPermission('ims.investments.confirm')) {
            throw ValidationException::withMessages(['status' => __('You do not have permission to confirm money received.')]);
        }

        if (! $record->isPending()) {
            throw ValidationException::withMessages(['status' => __('Only money that is still waiting can be removed.')]);
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => __('Write a reason of at least 5 letters.')]);
        }

        $this->audit->record('ims.money_discarded', $record, $record->only(['investor_id', 'amount', 'status']), ['reason' => trim($reason), 'by' => $by->getKey()]);
        $record->delete();
    }
}
