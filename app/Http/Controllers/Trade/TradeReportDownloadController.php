<?php

namespace App\Http\Controllers\Trade;

use App\Http\Controllers\Controller;
use App\Services\CompanyContext;
use App\Services\TradeReportService;
use App\Support\TradeReportFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET trade/reports/{report}/download/{format}?from=&to=: a Trade Portal
 * report as a print page in the portal's language (format "print": the
 * browser prints it or saves it as a PDF, with Bangla drawn correctly), a PDF
 * file in English (A4, landscape for wide reports), or an Excel file (amounts
 * as numbers, so they can be added up there). Same data as the screen
 * (TradeReportService), for the open company only.
 */
class TradeReportDownloadController extends Controller
{
    public function __invoke(Request $request, string $report, string $format, TradeReportService $reports): Response
    {
        abort_unless($request->user()?->hasPermission('trade.reports'), 403);
        abort_unless(array_key_exists($report, TradeReportService::REPORTS), 404);

        $company = app(CompanyContext::class)->company();

        // DomPDF cannot shape Bangla (vowel signs land in the wrong place), so the
        // PDF file is in English; the print page is in the portal's language and
        // the browser draws Bangla correctly (and can save it as a PDF too).
        if ($format === 'pdf') {
            app()->setLocale('en');
        }

        $data = $reports->build($report, $request->query('from'), $request->query('to'));
        $filename = Str::slug(TradeReportService::REPORTS[$report][0].' '.$data['period']);

        if ($format === 'xlsx') {
            return $this->excel($data, $company?->name, $filename.'.xlsx');
        }

        if ($format === 'print') {
            return response()->view('trade.reports.print', ['report' => $data, 'company' => $company]);
        }

        return Pdf::loadView('trade.reports.pdf', ['report' => $data, 'company' => $company])
            ->setPaper('a4', count($data['columns']) > 6 ? 'landscape' : 'portrait')
            ->stream($filename.'.pdf');
    }

    /** @param  array<string, mixed>  $data */
    protected function excel(array $data, ?string $companyName, string $filename): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'trade-report-');
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->setFontBold();

        $writer->addRow(Row::fromValues([trim($companyName.' — '.$data['title'])], $bold));
        $writer->addRow(Row::fromValues([$data['period']]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(array_column($data['columns'], 'label'), $bold));

        foreach ($data['rows'] as $row) {
            $writer->addRow(Row::fromValues(array_map(fn (array $column) => TradeReportFormat::excel($row[$column['key']] ?? null, $column['type']), $data['columns'])));
        }

        if ($data['totals']) {
            $writer->addRow(Row::fromValues(array_map(fn (array $column) => TradeReportFormat::excel($data['totals'][$column['key']] ?? null, $column['type']), $data['columns']), $bold));
        }

        foreach ($data['notes'] as $note) {
            $writer->addRow(Row::fromValues([$note]));
        }

        $writer->close();

        return response()->download($path, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }
}
