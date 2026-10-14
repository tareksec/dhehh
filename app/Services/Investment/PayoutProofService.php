<?php

namespace App\Services\Investment;

use App\Models\ChannelPartnerPayout;
use App\Models\InvestmentDocument;
use App\Models\SettlementPayout;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Validation\ValidationException;

/**
 * The payment screenshot of a payout (16_INVESTOR_GROUPS_PLAN.md §3.6, owner
 * 2026-10-12): staff pay by hand, upload the screenshot, and the investor sees
 * it in the portal. Only a PAID payout takes one. A newer upload replaces the
 * one shown, and the older file stays on record.
 */
class PayoutProofService
{
    public function __construct(private AuditLogService $audit) {}

    /** @param  string  $storedPath  where the file already is, in the company's private storage */
    public function attach(SettlementPayout|ChannelPartnerPayout $payout, string $storedPath, ?User $by = null): InvestmentDocument
    {
        if ($payout->payment_status !== 'paid') {
            throw ValidationException::withMessages(['payment_proof' => __('A payment screenshot can be added only after the payout is marked paid.')]);
        }

        if (blank($storedPath)) {
            throw ValidationException::withMessages(['payment_proof' => __('Choose the screenshot to upload.')]);
        }

        $document = InvestmentDocument::query()->create([
            'company_id' => $payout->company_id,
            'documentable_type' => $payout->getMorphClass(),
            'documentable_id' => $payout->getKey(),
            'file_path' => $storedPath,
            'category' => InvestmentDocument::CATEGORY_PAYMENT_PROOF,
            'label' => __('Payment screenshot'),
            'uploaded_by' => $by?->getKey(),
        ]);

        $this->audit->record('payout_payment_proof_uploaded', $payout, null, [
            'document_id' => $document->getKey(),
            'uploaded_by' => $by?->getKey(),
        ]);

        return $document;
    }
}
