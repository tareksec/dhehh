<?php

namespace App\Http\Controllers;

use App\Models\CourierProvider;
use App\Services\CompanySettingsService;
use App\Services\CourierHandoverSheetService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The printable courier handover sheet (see CourierHandoverSheetService). */
class CourierHandoverSheetController extends Controller
{
    public function __invoke(Request $request, CourierHandoverSheetService $sheets): View
    {
        abort_unless($request->user()?->hasPermission('sales.view'), 403);

        $data = $request->validate([
            'provider' => ['required', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // CourierProvider is company-scoped: another company's id is a 404.
        $provider = CourierProvider::query()->findOrFail((int) $data['provider']);
        $company = $provider->company;

        return view('courier.handover-sheet', [
            'provider' => $provider,
            'company' => app(CompanySettingsService::class)->profile($company),
            'sheet' => $sheets->build($provider, $data['date'] ?? null),
            'autoPrint' => $request->boolean('print'),
        ]);
    }
}
