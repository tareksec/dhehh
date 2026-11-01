<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(string $type, Request $request, ReportExportService $exports): StreamedResponse
    {
        abort_unless($request->user()?->canExportReports(), 403);

        $exports->definition($type);
        $exports->audit($request, 'csv', $type);

        return $exports->download(
            $type,
            $request->query('date_from'),
            $request->query('date_to'),
            $request->integer('supplier_id') ?: null,
        );
    }
}
