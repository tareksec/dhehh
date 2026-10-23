<?php

namespace App\Http\Controllers\Trade;

use App\Http\Controllers\Controller;
use App\Models\LcFile;
use App\Services\CompanySettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET trade/lc-files/{lcFile}/papers/{document}: one import paper of an LC
 * file as an A4 PDF (docs/tasneem-samples/SUMMARY.md, documents 2, 6, 7, 8, 9),
 * with the samples' English wording and fixed clauses, filled from the LC
 * file, its purchase, the supplier and the business profile. The PDFs use
 * the same engine and styles as the ERP's PI / CI / PL.
 *
 * A paper with empty fields is not printed: a page lists what is missing
 * (LcFile::missingFor(); SUMMARY.md §5.7 — no Shipment Advice without its
 * cover note). The open company is the portal's, so another company's file
 * is a 404.
 */
class TradeLcDocumentController extends Controller
{
    public function __invoke(Request $request, int $lcFile, string $document, CompanySettingsService $settings): Response
    {
        abort_unless($request->user()?->hasPermission('trade.lc'), 403);
        abort_unless(array_key_exists($document, LcFile::DOCUMENTS), 404);

        $file = LcFile::query()->with(['purchase.supplier', 'purchase.company', 'purchase.items.product'])->findOrFail($lcFile);
        $purchase = $file->purchase;
        $company = $purchase->company;
        $missing = $file->missingFor($document);

        if ($missing !== []) {
            return response()->view('trade.lc.missing', [
                'file' => $file,
                'title' => __(LcFile::DOCUMENTS[$document]),
                'missing' => $missing,
            ], 422);
        }

        app()->setLocale('en');

        $filename = Str::slug(LcFile::DOCUMENTS[$document].' '.($purchase->lc_number ?: $purchase->purchase_number)).'.pdf';

        return Pdf::loadView('trade.lc.'.$document, [
            'file' => $file,
            'purchase' => $purchase,
            'supplier' => $purchase->supplier,
            'company' => $company,
            'letterheadPath' => $settings->documentImagePath($company?->letterhead_path, $company),
            'signaturePath' => $settings->documentImagePath($company?->signature_path, $company),
            'supplierSignaturePath' => $settings->documentImagePath($purchase->supplier?->signature_path, $company),
        ])->setPaper('a4')->stream($filename);
    }
}
