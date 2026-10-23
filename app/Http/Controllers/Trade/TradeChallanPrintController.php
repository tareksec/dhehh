<?php

namespace App\Http\Controllers\Trade;

use App\Filament\Trade\Pages\BusinessProfile;
use App\Http\Controllers\Controller;
use App\Models\DeliveryChallan;
use App\Support\CompanyMedia;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET trade/challans/{challan}/print: the Delivery Challan as a page to print
 * on A4, laid out like the owner's pad (docs/tasneem-samples/delivery-challan-sample.webp):
 * the letterhead image (or the business name and factory address), the
 * challan BIN, the lines, the money and the two signatures. The paper is in
 * English like the pad, whatever language the portal is in. The open company
 * is the portal's (CompanyScope), so another company's challan is a 404.
 */
class TradeChallanPrintController extends Controller
{
    public function __invoke(Request $request, int $challan): View
    {
        abort_unless($request->user()?->hasPermission('trade.sales'), 403);

        $challan = DeliveryChallan::query()->with(['items', 'customer', 'serials'])->findOrFail($challan);
        $company = $challan->company;

        app()->setLocale('en');

        return view('trade.challan-print', [
            'challan' => $challan,
            'company' => $company,
            'letterhead' => filled($company?->letterhead_path) ? CompanyMedia::publicUrl($company->letterhead_path, $company) : null,
            'terms' => trim((string) data_get($company?->settings, BusinessProfile::CHALLAN_TERMS_SETTING, '')),
        ]);
    }
}
