<?php

namespace App\Services\Investment;

use App\Models\ChannelPartner;
use App\Models\ChannelPartnerPayout;
use App\Models\FundSource;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchItem;
use App\Models\SettlementPayout;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AuditLogService;
use App\Services\VoucherService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Payout batches (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §9, Phase 5).
 *
 * 1. Create (`investments.payout_batch_create`): pending investor and
 *    channel-partner payouts of confirmed settlements, each sent to the
 *    payee's approved default payment account (copied into the item).
 * 2. Approve (`investments.payout_batch_approve`) by a different user than
 *    the maker — even a Super Admin cannot approve their own batch.
 * 3. Export the payment list (stored privately, SHA-256 recorded, every
 *    download audited). The NCC BEFTN file waits for the bank's template.
 * 4. Record results: single items failed (the payout is free for a new
 *    batch), then the rest paid with the bank reference — one pending
 *    `investor_payout` voucher for the whole batch.
 */
class PayoutBatchService
{
    public function __construct(
        protected PayoutCompletionService $completion,
        protected VoucherService $vouchers,
        protected AuditLogService $audit,
    ) {}

    /**
     * Pending payouts that can go into a new batch, and the ones left out
     * with the reason.
     *
     * @return array{eligible: Collection<string, array<string, mixed>>, left_out: Collection<string, array<string, mixed>>}
     */
    public function payables(): array
    {
        $rows = collect();

        SettlementPayout::query()
            ->with(['settlement.project', 'investor.bankAccounts', 'cycleElection'])
            ->where('payment_status', 'pending')
            ->whereHas('settlement', fn ($query) => $query->where('status', 'confirmed'))
            ->get()
            ->each(function (SettlementPayout $payout) use ($rows): void {
                $rows->put('s:'.$payout->id, $this->row('s:'.$payout->id, $payout, $payout->investor, (float) $payout->total_payout,
                    $payout->cycleElection && $payout->cycleElection->election !== 'withdraw'
                        ? __('Has a reinvest choice — pay it from the settlement page.') : null));
            });

        ChannelPartnerPayout::query()
            ->with(['settlement.project', 'channelPartner.bankAccounts'])
            ->where('payment_status', 'pending')
            ->whereHas('settlement', fn ($query) => $query->where('status', 'confirmed'))
            ->get()
            ->each(function (ChannelPartnerPayout $payout) use ($rows): void {
                $rows->put('p:'.$payout->id, $this->row('p:'.$payout->id, $payout, $payout->channelPartner, (float) $payout->amount, null));
            });

        $held = PayoutBatchItem::query()->live()->with('batch')->get()
            ->keyBy(fn (PayoutBatchItem $item): string => ($item->payable_type === (new SettlementPayout)->getMorphClass() ? 's:' : 'p:').$item->payable_id);

        $rows = $rows->map(function (array $row, string $key) use ($held): array {
            if ($row['reason'] === null && $held->has($key)) {
                $row['reason'] = __('Already in :batch.', ['batch' => $held[$key]->batch->label()]);
            }

            return $row;
        });

        return [
            'eligible' => $rows->filter(fn (array $row): bool => $row['reason'] === null),
            'left_out' => $rows->reject(fn (array $row): bool => $row['reason'] === null),
        ];
    }

    /**
     * @param  list<string>  $keys  "s:{settlement payout id}" or "p:{channel-partner payout id}"
     */
    public function create(User $by, array $keys, ?int $fundSourceId = null, ?string $payoutDate = null, ?string $notes = null): PayoutBatch
    {
        $this->authorize($by, 'investments.payout_batch_create');

        if ($keys === []) {
            throw ValidationException::withMessages(['payables' => __('Choose at least one payout.')]);
        }

        return DB::transaction(function () use ($by, $keys, $fundSourceId, $payoutDate, $notes): PayoutBatch {
            $eligible = $this->payables()['eligible'];
            $rows = collect($keys)->unique()->map(function (string $key) use ($eligible): array {
                if (! $eligible->has($key)) {
                    throw ValidationException::withMessages(['payables' => __('A chosen payout can no longer be added. Reload the list and try again.')]);
                }

                return $eligible[$key];
            });

            if ($fundSourceId === null) {
                $sources = $rows->map(fn (array $row) => $row['payable']->settlement?->project?->payout_fund_source_id)->unique();
                $fundSourceId = $sources->count() === 1 ? $sources->first() : null;
            } elseif (! FundSource::query()->whereKey($fundSourceId)->exists()) {
                throw ValidationException::withMessages(['fund_source_id' => __('Choose a fund source of this company.')]);
            }

            $companyId = (int) $rows->first()['payable']->company_id;
            $number = (int) PayoutBatch::query()->where('company_id', $companyId)->lockForUpdate()->max('batch_number') + 1;

            $batch = PayoutBatch::query()->create([
                'company_id' => $companyId,
                'batch_number' => $number,
                'kind' => PayoutBatch::KIND_INVESTOR,
                'payout_date' => $payoutDate,
                'fund_source_id' => $fundSourceId,
                'file_format' => PayoutBatch::FORMAT_MANUAL_LIST,
                'notes' => $notes,
                'created_by' => $by->getKey(),
            ]);

            foreach ($rows as $row) {
                /** @var InvestorBankAccount $account */
                $account = $row['account'];
                $batch->items()->create([
                    'company_id' => $companyId,
                    'payable_type' => $row['payable']->getMorphClass(),
                    'payable_id' => $row['payable']->getKey(),
                    'payee_type' => $row['payee']->getMorphClass(),
                    'payee_id' => $row['payee']->getKey(),
                    'investor_bank_account_id' => $account->getKey(),
                    'payee_name' => $row['payee_name'],
                    'account_type' => $account->type,
                    'bank_name' => $account->bank_name,
                    'branch_name' => $account->branch_name,
                    'routing_number' => $account->routing_number,
                    'account_name' => $account->account_name,
                    'account_number' => $account->account_number,
                    'mfs_provider' => $account->mfs_provider,
                    'mfs_number' => $account->mfs_number,
                    'amount' => $row['amount'],
                ]);
            }

            $this->refreshTotals($batch);
            $this->audit->record('payout_batch.created', $batch, null, ['batch' => $number, 'items' => $batch->item_count, 'total' => (string) $batch->total_amount]);

            return $batch;
        });
    }

    public function removeItem(PayoutBatchItem $item, User $by): void
    {
        $this->authorize($by, 'investments.payout_batch_create');
        $batch = $item->batch;

        if ($batch->status !== PayoutBatch::STATUS_DRAFT) {
            throw ValidationException::withMessages(['batch' => __('Only a draft batch can be changed.')]);
        }

        DB::transaction(function () use ($item, $batch): void {
            $item->delete();
            $this->refreshTotals($batch);
        });

        $this->audit->record('payout_batch.item_removed', $batch, null, ['payee' => $item->payee_name, 'amount' => (string) $item->amount]);
    }

    public function approve(PayoutBatch $batch, User $by): void
    {
        $this->authorize($by, 'investments.payout_batch_approve');

        if ($batch->status !== PayoutBatch::STATUS_DRAFT) {
            throw ValidationException::withMessages(['batch' => __('Only a draft batch can be approved.')]);
        }

        if ((int) $batch->created_by === (int) $by->getKey()) {
            throw ValidationException::withMessages(['batch' => __('A batch is approved by someone other than the person who made it.')]);
        }

        DB::transaction(function () use ($batch, $by): void {
            $batch->refresh();

            if ($batch->items()->count() === 0) {
                throw ValidationException::withMessages(['batch' => __('The batch has no payouts.')]);
            }

            $this->assertPayablesStillPending($batch->items()->where('status', PayoutBatchItem::STATUS_PENDING)->get());

            $batch->forceFill(['status' => PayoutBatch::STATUS_APPROVED, 'approved_by' => $by->getKey(), 'approved_at' => now()])->save();
        });

        $this->audit->record('payout_batch.approved', $batch, null, ['batch' => $batch->batch_number]);
    }

    /**
     * Builds the payment file once (later downloads serve the same file,
     * checked against its SHA-256) and audits every download.
     *
     * @return array{name: string, contents: string}
     */
    public function export(PayoutBatch $batch, User $by): array
    {
        $this->authorize($by, 'investments.payout_batch_create');

        if (! in_array($batch->status, [PayoutBatch::STATUS_APPROVED, PayoutBatch::STATUS_EXPORTED, PayoutBatch::STATUS_COMPLETED], true)) {
            throw ValidationException::withMessages(['batch' => __('A batch is exported after it is approved.')]);
        }

        $disk = Storage::disk('local');
        $name = 'payout-batch-'.$batch->batch_number.'.csv';

        if ($batch->export_path === null) {
            $contents = $this->manualList($batch);
            $path = 'payout-batches/'.$batch->company_id.'/'.$name;
            $disk->put($path, $contents);
            $batch->forceFill([
                'export_path' => $path,
                'export_sha256' => hash('sha256', $contents),
                'exported_at' => now(),
                'status' => $batch->status === PayoutBatch::STATUS_APPROVED ? PayoutBatch::STATUS_EXPORTED : $batch->status,
            ])->save();
        } else {
            $contents = (string) $disk->get($batch->export_path);

            if (! hash_equals((string) $batch->export_sha256, hash('sha256', $contents))) {
                throw ValidationException::withMessages(['batch' => __('The stored payment file has changed since it was made. It was not downloaded.')]);
            }
        }

        $this->audit->record('payout_batch.downloaded', $batch, null, ['batch' => $batch->batch_number, 'sha256' => $batch->export_sha256]);

        return ['name' => $name, 'contents' => $contents];
    }

    public function markItemFailed(PayoutBatchItem $item, User $by, string $reason): void
    {
        $this->authorize($by, 'investments.payout_batch_create');
        $batch = $item->batch;

        if (! in_array($batch->status, [PayoutBatch::STATUS_APPROVED, PayoutBatch::STATUS_EXPORTED], true) || $item->status !== PayoutBatchItem::STATUS_PENDING) {
            throw ValidationException::withMessages(['item' => __('Only a pending payout of an approved batch can be marked failed.')]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages(['failure_reason' => __('Write why the payment failed.')]);
        }

        $item->forceFill(['status' => PayoutBatchItem::STATUS_FAILED, 'failure_reason' => trim($reason)])->save();
        $this->refreshTotals($batch);
        $this->audit->record('payout_batch.item_failed', $batch, null, ['payee' => $item->payee_name, 'amount' => (string) $item->amount, 'reason' => trim($reason)]);
    }

    /**
     * The bank (or the person paying by hand) sent every remaining item:
     * each payout is marked paid with the bank reference, and one pending
     * payout voucher is booked for the batch from its fund source.
     */
    public function complete(PayoutBatch $batch, User $by, string $bankReference, ?string $paidOn = null): void
    {
        $this->authorize($by, 'investments.payout_batch_create');

        if (! in_array($batch->status, [PayoutBatch::STATUS_APPROVED, PayoutBatch::STATUS_EXPORTED], true)) {
            throw ValidationException::withMessages(['batch' => __('Only an approved batch can be recorded as paid.')]);
        }

        if (blank($bankReference)) {
            throw ValidationException::withMessages(['bank_reference' => __('Enter the bank or payment reference.')]);
        }

        $paidOn ??= now()->toDateString();

        DB::transaction(function () use ($batch, $by, $bankReference, $paidOn): void {
            $items = $batch->items()->where('status', PayoutBatchItem::STATUS_PENDING)->with('payable.settlement')->get();
            $this->assertPayablesStillPending($items);
            $payables = [];

            foreach ($items as $item) {
                $this->completion->applyPaid($item->payable, [
                    'payment_method' => $this->paymentMethod($item),
                    'payment_reference' => trim($bankReference),
                    'paid_at' => $paidOn,
                ]);
                $item->forceFill(['status' => PayoutBatchItem::STATUS_PAID, 'bank_reference' => trim($bankReference), 'paid_at' => $paidOn])->save();
                $payables[] = $item->payable;
            }

            $paidTotal = round((float) $items->sum('amount'), 2);
            $voucher = null;
            $fundSource = $batch->fund_source_id ? FundSource::query()->find($batch->fund_source_id) : null;

            if ($fundSource && $paidTotal > 0) {
                $methods = $items->map(fn (PayoutBatchItem $item): string => $this->paymentMethod($item))->unique();
                $voucher = $this->vouchers->submit([
                    'company_id' => $batch->company_id,
                    'type' => Voucher::TYPE_DEBIT,
                    'transaction_type' => 'investor_payout',
                    'amount' => $paidTotal,
                    'fund_source_id' => $fundSource->id,
                    'account_id' => $fundSource->account_id,
                    'payment_method' => $methods->count() === 1 ? $methods->first() : 'other',
                    'transaction_id' => trim($bankReference),
                    'confirmation_source' => 'manual',
                    'purpose' => "Mudarabah payout batch #{$batch->batch_number} — {$items->count()} payouts",
                ], $by);

                foreach ($payables as $payable) {
                    $payable->forceFill(['voucher_id' => $voucher->id])->saveQuietly();
                }
            }

            $batch->forceFill([
                'status' => PayoutBatch::STATUS_COMPLETED,
                'bank_reference' => trim($bankReference),
                'completed_by' => $by->getKey(),
                'completed_at' => now(),
                'voucher_id' => $voucher?->id,
            ])->save();
        });

        $this->audit->record('payout_batch.completed', $batch, null, ['batch' => $batch->batch_number, 'reference' => trim($bankReference), 'voucher_id' => $batch->voucher_id]);
    }

    public function cancel(PayoutBatch $batch, User $by, string $reason): void
    {
        if (! $by->hasPermission('investments.payout_batch_create') && ! $by->hasPermission('investments.payout_batch_approve')) {
            throw ValidationException::withMessages(['batch' => __('You do not have permission to do this.')]);
        }

        if (! $batch->isLive()) {
            throw ValidationException::withMessages(['batch' => __('A completed or cancelled batch cannot be cancelled.')]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages(['cancel_reason' => __('Write why the batch is cancelled.')]);
        }

        $batch->forceFill(['status' => PayoutBatch::STATUS_CANCELLED, 'cancelled_by' => $by->getKey(), 'cancelled_at' => now(), 'cancel_reason' => trim($reason)])->save();
        $this->audit->record('payout_batch.cancelled', $batch, null, ['batch' => $batch->batch_number, 'reason' => trim($reason)]);
    }

    /** CSV payment list: one row per pending or paid item. */
    public function manualList(PayoutBatch $batch): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['SL', 'Payee', 'Account type', 'Bank', 'Branch', 'Routing number', 'Account name', 'Account number', 'Mobile banking', 'Mobile number', 'Amount (BDT)'], escape: '');

        $serial = 0;
        $batch->items()->where('status', '!=', PayoutBatchItem::STATUS_FAILED)->orderBy('id')->get()
            ->each(function (PayoutBatchItem $item) use ($handle, &$serial): void {
                fputcsv($handle, array_map($this->csvCell(...), [
                    ++$serial,
                    $item->payee_name,
                    $item->account_type === InvestorBankAccount::TYPE_MFS ? 'MFS' : 'Bank',
                    $item->bank_name,
                    $item->branch_name,
                    $item->routing_number,
                    $item->account_name,
                    $item->account_number,
                    $item->mfs_provider ? (InvestorBankAccount::MFS_PROVIDERS[$item->mfs_provider] ?? $item->mfs_provider) : null,
                    $item->mfs_number,
                    number_format((float) $item->amount, 2, '.', ''),
                ]), escape: '');
            });

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /** @return array<string, mixed> */
    protected function row(string $key, SettlementPayout|ChannelPartnerPayout $payable, Investor|ChannelPartner|null $payee, float $amount, ?string $reason): array
    {
        $account = $payee?->bankAccounts
            ->first(fn (InvestorBankAccount $account): bool => $account->status === InvestorBankAccount::STATUS_APPROVED && $account->is_default);

        $reason ??= match (true) {
            $payee === null => __('The payee record is missing.'),
            $amount <= 0 => __('Nothing to pay.'),
            $account === null => __('No approved payment account.'),
            default => null,
        };

        return [
            'key' => $key,
            'payable' => $payable,
            'payee' => $payee,
            'payee_name' => (string) ($payee?->name ?? '-'),
            'kind' => $payable instanceof SettlementPayout ? __('Investor') : __('Channel partner'),
            'project' => (string) ($payable->settlement?->project?->project_code ?? ''),
            'amount' => round($amount, 2),
            'account' => $account,
            'reason' => $reason,
        ];
    }

    protected function refreshTotals(PayoutBatch $batch): void
    {
        $items = $batch->items()->where('status', '!=', PayoutBatchItem::STATUS_FAILED);
        $batch->forceFill(['item_count' => (clone $items)->count(), 'total_amount' => round((float) (clone $items)->sum('amount'), 2)])->save();
    }

    /** @param  Collection<int, PayoutBatchItem>  $items */
    protected function assertPayablesStillPending(Collection $items): void
    {
        foreach ($items as $item) {
            if ($item->payable?->payment_status !== 'pending') {
                throw ValidationException::withMessages(['batch' => __(':name\'s payout is no longer pending. Mark that item failed or cancel the batch.', ['name' => $item->payee_name])]);
            }
        }
    }

    /** The payment method stored on the payout: the payout screens' own options. */
    protected function paymentMethod(PayoutBatchItem $item): string
    {
        if ($item->account_type === InvestorBankAccount::TYPE_BANK) {
            return 'bank';
        }

        $options = $item->payable_type === (new SettlementPayout)->getMorphClass() ? ['bkash'] : array_keys(ChannelPartner::PAYMENT_METHODS);

        return in_array($item->mfs_provider, $options, true) ? $item->mfs_provider : 'other';
    }

    /** A spreadsheet must never read a name as a formula. */
    protected function csvCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    protected function authorize(User $by, string $permission): void
    {
        if (! $by->hasPermission($permission)) {
            throw ValidationException::withMessages(['batch' => __('You do not have permission to do this.')]);
        }
    }
}
