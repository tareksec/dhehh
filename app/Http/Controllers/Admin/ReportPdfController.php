<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CompanySettingsService;
use App\Services\ReportExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportPdfController extends Controller
{
    public function __invoke(
        string $type,
        Request $request,
        ReportExportService $exports,
        CompanySettingsService $settings,
    ): Response {
        abort_unless($request->user()?->canExportReports(), 403);

        $exports->definition($type);
        $exports->audit($request, 'pdf', $type);

        $export = $exports->export(
            $type,
            $request->query('date_from'),
            $request->query('date_to'),
            $request->integer('supplier_id') ?: null,
        );

        $filename = str($export['filename'])->replaceEnd('.csv', '.pdf')->toString();

        return Pdf::loadView('reports.pdf', [
            'export' => $export,
            'title' => $export['title'],
            'company' => $settings->profile(),
        ])->setPaper('a4', 'landscape')->download($filename);
    }
}
