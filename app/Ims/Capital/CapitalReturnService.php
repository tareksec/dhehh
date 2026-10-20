<?php

namespace App\Ims\Capital;

use App\Ims\Files\ImsFiles;
use App\Ims\Notifications\ImsNotifier;
use App\Models\CapitalReturn;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestorGroupMember;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AuditLogService;
use App\Services\VoucherService;
use App\Support\ImsNumbers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Capital paid back (IMS-V2/PRD.md FR-53–55, TRD §4.3): at maturity, or on
 * exit, in part or in full, never more than what is still outstanding after
 * losses. Every return books a debit voucher (like a withdrawal) and, once
 * nothing is left, the investment or deposit is marked withdrawn with it.
 * A return is never deleted; a mistake is voided with a reason, which also
 * cancels its voucher while that is still pending.
 */
class CapitalReturnService
{
    public function __construct(
        private CapitalCalculator $capital,
        private VoucherService $vouchers,
        private ImsFiles $files,
        private ImsNotifier $notifier,
        private AuditLogService $audit,
    ) {}

    /**
     * @param  array{amount: float|int|string, returned_on: string, fund_source_id: int|string, method?: string|null, reference?: string|null, note?: string|null}  $data
     */
    public function record(Investment|InvestorGroupMember $target, array $data, User $by, UploadedFile|string|null $proof = null): CapitalReturn
    {
        if (! $by->hasPermission('ims.capital_returns.manage')) {
            throw ValidationException::withMessages(['amount' => __('You do not have permission to pay back capital.')]);
        }

        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount greater than zero.')]);
        }

        $company = Company::query()->findOrFail($target->company_id);
        $proofPath = $proof !== null ? $this->files->store($company, 'capital-returns', $proof, ImsFiles::IMAGES_AND_PDF, 'proof')['path'] : null;

        $return = DB::transaction(function () use ($target, $data, $amount, $by, $proofPath): CapitalReturn {
            $locked = $target::query()->lockForUpdate()->findOrFail($target->getKey());

            if ($locked->isPending() || $locked->isWithdrawn()) {
                throw ValidationException::withMessages(['amount' => __('There is no capital at work here to pay back.')]);
            }

            $figures = $locked instanceof InvestorGroupMember ? $this->capital->forMember($locked) : $this->capital->forInvestment($locked);

            if ($amount > $figures['outstanding'] + 0.001) {
                throw ValidationException::withMessages(['amount' => __('The amount is more than what is left. At most :amount.', ['amount' => ImsNumbers::money($figures['outstanding'])])]);
            }

            $fundSource = FundSource::query()->find($data['fund_source_id'] ?? null);

            if (! $fundSource || ! $fundSource->account_id) {
                throw ValidationException::withMessages(['fund_source_id' => __('Pick a fund source that has a linked account.')]);
            }

            $locked->loadMissing('investor');

            $voucher = $this->vouchers->submit([
                'company_id' => $locked->company_id,
                'type' => Voucher::TYPE_DEBIT,
                'transaction_type' => 'investor_payout',
                'amount' => $amount,
                'fund_source_id' => $fundSource->getKey(),
                'account_id' => $fundSource->account_id,
                'payment_method' => $data['method'] ?? 'bank',
                'transaction_id' => $data['reference'] ?? null,
                'confirmation_source' => 'manual',
                'purpose' => "Mudarabah capital returned — {$locked->investor?->name} — returned on {$data['returned_on']}",
                'remarks' => $data['note'] ?? null,
            ], $by);

            $return = CapitalReturn::query()->create([
                'company_id' => $locked->company_id,
                'investor_id' => $locked->investor_id,
                'investment_id' => $locked instanceof Investment ? $locked->getKey() : null,
                'group_member_id' => $locked instanceof InvestorGroupMember ? $locked->getKey() : null,
                'amount' => $amount,
                'returned_on' => $data['returned_on'],
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'proof_path' => $proofPath,
                'voucher_id' => $voucher->getKey(),
                'created_by' => $by->getKey(),
            ]);

            // Nothing left: the money has gone back, with this voucher.
            if (round($figures['outstanding'] - $amount, 2) <= 0) {
                $locked->update([
                    'status' => $locked instanceof InvestorGroupMember ? InvestorGroupMember::STATUS_WITHDRAWN : Investment::STATUS_WITHDRAWN,
                    'withdrawn_at' => $data['returned_on'],
                    'withdrawal_voucher_id' => $voucher->getKey(),
                    'withdrawal_note' => __('Capital paid back in full'),
                ]);
            }

            $this->audit->record('ims.capital_returned', $return, null, ['amount' => $amount, 'by' => $by->getKey()]);

            return $return;
        });

        $this->notifier->send('capital_returned', $return->investor, ['amount' => $amount]);

        return $return;
    }

    public function void(CapitalReturn $return, string $reason, User $by): CapitalReturn
    {
        if (! $by->hasPermission('ims.capital_returns.manage')) {
            throw ValidationException::withMessages(['reason' => __('You do not have permission to pay back capital.')]);
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => __('Write a reason of at least 10 letters for voiding.')]);
        }

        return DB::transaction(function () use ($return, $reason, $by): CapitalReturn {
            $locked = CapitalReturn::query()->lockForUpdate()->findOrFail($return->getKey());

            if ($locked->status !== CapitalReturn::STATUS_PAID) {
                throw ValidationException::withMessages(['reason' => __('This return is already voided.')]);
            }

            $locked->forceFill(['status' => CapitalReturn::STATUS_VOIDED, 'void_reason' => trim($reason), 'voided_by' => $by->getKey(), 'voided_at' => now()])->save();

            // The voucher too, while Finance has not acted on it; that also puts a
            // fully paid-back investment back to active (InvestmentWithdrawalService).
            $voucher = $locked->voucher;

            if ($voucher?->isPending()) {
                $this->vouchers->cancel($voucher);
            } else {
                $this->reopenTarget($locked);
            }

            $this->audit->record('ims.capital_return_voided', $locked, ['status' => CapitalReturn::STATUS_PAID], ['status' => CapitalReturn::STATUS_VOIDED, 'reason' => trim($reason), 'by' => $by->getKey()]);

            $return->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /** Called when a return's voucher is rejected, cancelled or trashed in Finance. */
    public function voucherVoided(Voucher $voucher): void
    {
        CapitalReturn::query()->where('voucher_id', $voucher->getKey())->where('status', CapitalReturn::STATUS_PAID)->get()
            ->each(fn (CapitalReturn $return) => $return->forceFill(['status' => CapitalReturn::STATUS_VOIDED, 'void_reason' => __('The voucher was voided in Finance.'), 'voided_at' => now()])->save());
    }

    private function reopenTarget(CapitalReturn $return): void
    {
        $target = $return->investment ?? $return->groupMember;

        if ($target !== null && $target->isWithdrawn() && (int) $target->withdrawal_voucher_id === (int) $return->voucher_id) {
            $target->forceFill(['status' => $target instanceof InvestorGroupMember ? InvestorGroupMember::STATUS_ACTIVE : Investment::STATUS_ACTIVE])->saveQuietly();
        }
    }
}
