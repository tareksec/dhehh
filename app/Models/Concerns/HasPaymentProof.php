<?php

namespace App\Models\Concerns;

use App\Models\InvestmentDocument;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A payout can carry the screenshot (or slip) staff upload after paying it
 * (16_INVESTOR_GROUPS_PLAN.md §3.6): an `InvestmentDocument` of the category
 * `payment_proof`, kept in the company's private storage. The newest one counts.
 */
trait HasPaymentProof
{
    public function documents(): MorphMany
    {
        return $this->morphMany(InvestmentDocument::class, 'documentable');
    }

    public function paymentProof(): ?InvestmentDocument
    {
        return $this->documents()->where('category', InvestmentDocument::CATEGORY_PAYMENT_PROOF)->latest('id')->first();
    }

    public function hasPaymentProof(): bool
    {
        return $this->documents()->where('category', InvestmentDocument::CATEGORY_PAYMENT_PROOF)->exists();
    }
}
