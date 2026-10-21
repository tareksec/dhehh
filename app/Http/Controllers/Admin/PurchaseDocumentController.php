<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Services\CompanySettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PurchaseDocumentController extends Controller
{
    public const TYPES = ['pi', 'ci', 'pl'];

    public function __invoke(Purchase $purchase, string $type, Request $request, CompanySettingsService $settings): Response
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        abort_unless($request->user()?->canPerformModelAbility('view', Purchase::class), 403);

        $purchase->load(['company', 'supplier', 'items.product']);

        return Pdf::loadView("purchases.documents.{$type}", [
            'purchase' => $purchase,
            'company' => $settings->profile($purchase->company),
            'companyRecord' => $purchase->company,
            'supplier' => $purchase->supplier,
            'companySignaturePath' => $settings->documentImagePath($purchase->company?->signature_path, $purchase->company),
            'supplierSignaturePath' => $settings->documentImagePath($purchase->supplier?->signature_path, $purchase->company),
        ])->setPaper('a4')->stream("{$purchase->purchase_number}-{$type}.pdf");
    }
}
