<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\AuditLogService;
use App\Services\CompanySettingsService;
use App\Services\SupplierReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class SupplierReportPdfController extends Controller
{
    public function __invoke(
        Supplier $supplier,
        Request $request,
        SupplierReportService $reports,
        AuditLogService $auditLogs,
        CompanySettingsService $settings,
    ): Response {
        abort_unless($request->user()?->canExportReports(), 403);

        $filters = $request->validate([
            'product' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $productId = isset($filters['product']) ? (int) $filters['product'] : null;
        $report = $reports->build(
            $supplier,
            filled($filters['date_from'] ?? null) ? Carbon::parse($filters['date_from']) : null,
            filled($filters['date_to'] ?? null) ? Carbon::parse($filters['date_to']) : null,
            $productId,
        );

        $auditLogs->record('report_exported', SupplierReportService::class, null, [
            'format' => 'pdf',
            'type' => 'supplier',
            'supplier_id' => $supplier->getKey(),
            'product_id' => $productId,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
        ], $request);

        // dompdf cannot shape Bengali text, so the PDF is always English
        // (the on-screen report follows the user's language).
        app()->setLocale('en');

        $filename = 'supplier-report-'.$supplier->getKey()
            .($productId ? '-product-'.$productId : '')
            .'-'.now()->format('Ymd').'.pdf';

        return Pdf::loadView('reports.supplier-pdf', [
            'report' => $report,
            'company' => $settings->profile(),
        ])->setPaper('a4', 'landscape')->download($filename);
    }
}
