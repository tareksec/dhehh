<?php

namespace App\Services;

use App\Models\ChannelPartner;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment accounts of investors and channel partners
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md Phase 3). One added from the
 * portal waits for staff; approving one makes it the default, so a bank
 * change is simply a new account that staff approve.
 */
class InvestorBankAccountService
{
    public function __construct(protected AuditLogService $audit) {}

    /** @param  array<string, mixed>  $data */
    public function add(Investor|ChannelPartner $holder, array $data, string $source = 'portal'): InvestorBankAccount
    {
        $type = $data['type'] ?? null;

        if ($type === InvestorBankAccount::TYPE_BANK) {
            foreach (['bank_name', 'branch_name', 'account_name', 'account_number', 'routing_number'] as $field) {
                if (blank($data[$field] ?? null)) {
                    throw ValidationException::withMessages(["data.{$field}" => __('This field is required for a bank account.')]);
                }
            }

            if (! preg_match('/^\d{9}$/', (string) $data['routing_number'])) {
                throw ValidationException::withMessages(['data.routing_number' => __('The routing number has 9 digits.')]);
            }
        } elseif ($type === InvestorBankAccount::TYPE_MFS) {
            if (! array_key_exists($data['mfs_provider'] ?? '', InvestorBankAccount::MFS_PROVIDERS) || ! preg_match('/^01\d{9}$/', (string) ($data['mfs_number'] ?? ''))) {
                throw ValidationException::withMessages(['data.mfs_number' => __('Choose the service and enter an 11-digit mobile number (01XXXXXXXXX).')]);
            }
        } else {
            throw ValidationException::withMessages(['data.type' => __('Choose a bank account or mobile banking.')]);
        }

        $account = new InvestorBankAccount([
            'company_id' => $holder->company_id,
            'type' => $type,
            'bank_name' => $type === InvestorBankAccount::TYPE_BANK ? trim((string) $data['bank_name']) : null,
            'branch_name' => $type === InvestorBankAccount::TYPE_BANK ? trim((string) $data['branch_name']) : null,
            'routing_number' => $type === InvestorBankAccount::TYPE_BANK ? (string) $data['routing_number'] : null,
            'account_name' => $type === InvestorBankAccount::TYPE_BANK ? trim((string) $data['account_name']) : null,
            'account_number' => $type === InvestorBankAccount::TYPE_BANK ? preg_replace('/\s+/', '', (string) $data['account_number']) : null,
            'mfs_provider' => $type === InvestorBankAccount::TYPE_MFS ? $data['mfs_provider'] : null,
            'mfs_number' => $type === InvestorBankAccount::TYPE_MFS ? $data['mfs_number'] : null,
            'source' => $source,
        ]);
        $account->holder()->associate($holder);
        $account->save();

        $this->audit->record('investor_bank_account.added', $account, null, ['holder' => class_basename($holder).' #'.$holder->getKey(), 'account' => $account->displayName()]);

        return $account;
    }

    /** Approves the account and makes it the one payments go to. */
    public function approve(InvestorBankAccount $account, User $by): void
    {
        DB::transaction(function () use ($account, $by): void {
            InvestorBankAccount::query()
                ->where('holder_type', $account->holder_type)
                ->where('holder_id', $account->holder_id)
                ->whereKeyNot($account->getKey())
                ->update(['is_default' => false]);

            $account->forceFill([
                'status' => InvestorBankAccount::STATUS_APPROVED,
                'is_default' => true,
                'approved_by' => $by->getKey(),
                'approved_at' => now(),
                'rejection_reason' => null,
            ])->save();
        });

        $this->audit->record('investor_bank_account.approved', $account, ['status' => InvestorBankAccount::STATUS_PENDING], ['status' => InvestorBankAccount::STATUS_APPROVED]);
    }

    public function reject(InvestorBankAccount $account, User $by, string $reason): void
    {
        $account->forceFill([
            'status' => InvestorBankAccount::STATUS_REJECTED,
            'is_default' => false,
            'rejection_reason' => trim($reason),
        ])->save();

        $this->audit->record('investor_bank_account.rejected', $account, null, ['reason' => trim($reason), 'by' => $by->getKey()]);
    }
}
