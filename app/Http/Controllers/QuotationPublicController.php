<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use Illuminate\View\View;

class QuotationPublicController extends Controller
{
    /**
     * The route requires a valid signature (see routes/web.php), so the
     * quotation number itself can't be guessed/enumerated. We additionally
     * restrict to quotations that have actually been shared with a customer
     * (sent/accepted) — a draft link can't have leaked, and a rejected or
     * expired quotation has nothing useful left to show publicly.
     */
    public function show(string $quotationNumber): View
    {
        $quotation = Quotation::query()
            ->withoutGlobalScopes()
            // A deleted quotation (in Trash) is gone for the customer too; Restore turns the link back on.
            ->withoutTrashed()
            ->where('quotation_number', $quotationNumber)
            ->whereIn('status', ['sent', 'accepted'])
            ->with(['items.product', 'items.productVariant', 'company', 'customer', 'lead'])
            ->firstOrFail();

        return view('quotations.public', compact('quotation'));
    }
}
