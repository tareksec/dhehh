<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CompanySettingsService;
use App\Services\ReportExportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Self-contained printable HTML (open in a tab, print or Save as PDF from the
 * browser). Browser text rendering handles Bangla correctly, which dompdf
 * does not.
 */
class ReportPrintController extends Controller
{
    public function __invoke(
        string $type,
        Request $request,
        ReportExportService $exports,
        CompanySettingsService $settings,
    ): View {
        abort_unless($request->user()?->canExportReports(), 403);

        $exports->definition($type);
        $exports->audit($request, 'print', $type);

        $supplierId = $request->integer('supplier_id') ?: null;
        $export = $exports->export($type, $request->query('date_from'), $request->query('date_to'), $supplierId);
        $printable = $exports->printable($type, $request->query('date_from'), $request->query('date_to'), $supplierId);

        return view('reports.print', [
            'title' => $export['title'],
            'company' => $settings->profile(),
            'dateFrom' => $export['uses_dates'] ? $export['date_from'] : null,
            'dateTo' => $export['uses_dates'] ? $export['date_to'] : null,
            'asOf' => $export['as_of'] ? $export['date_to'] : null,
            'summary' => $printable['summary'],
            'summaryTitle' => $printable['summary_title'],
            'columns' => $printable['columns'],
            'rows' => $printable['rows'],
            'totals' => $printable['totals'],
            'autoPrint' => $request->query('print') === '1',
        ]);
    }
}
