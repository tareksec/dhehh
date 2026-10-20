<?php

namespace App\Ims\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

/**
 * IMS PDFs (statements, project reports) with the self-hosted Hind Siliguri
 * font (IMS-V2/TRD.md §1). dompdf keeps its font metrics in storage/fonts,
 * which a fresh server may not have yet.
 */
final class ImsPdf
{
    /** @param  array<string, mixed>  $data */
    public static function render(string $view, array $data): string
    {
        File::ensureDirectoryExists(storage_path('fonts'));

        return Pdf::loadView($view, $data)->setPaper('a4')->output();
    }
}
