<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Reseller portal Phase 4 (13_RESELLER_PORTAL_PLAN.md §3 row 3): lets a
 * logged-in reseller download a product's own photos (featured image +
 * gallery and the images of its variations) as a zip, for posting on Facebook/WhatsApp. Reachable only under
 * the portal path (so RestrictPortalHosts lets it through) and only
 * for the product's own company -- the same company the portal host and the
 * logged-in reseller both resolve to.
 */
class ResellerProductPhotosController extends Controller
{
    public function __invoke(Request $request, Product $product): StreamedResponse
    {
        $reseller = Auth::guard('customer')->user();

        abort_unless($reseller instanceof Customer, 403);

        $company = app(CompanyContext::class)->company();

        abort_unless($company !== null && (int) $product->company_id === (int) $company->getKey(), 404);

        $storage = app(CompanyStorageService::class);
        $paths = $product->allImagePaths();

        abort_if($paths === [], 404);

        $tempZip = tempnam(sys_get_temp_dir(), 'reseller-photos-');

        $zip = new ZipArchive;
        abort_unless($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500);

        $added = 0;

        foreach ($paths as $index => $path) {
            $location = $storage->locatePublic($path, $company);

            if ($location === null) {
                continue;
            }

            $contents = Storage::disk($location['disk'])->get($location['path']);

            if ($contents === null) {
                continue;
            }

            $extension = pathinfo($location['path'], PATHINFO_EXTENSION) ?: 'jpg';
            $zip->addFromString(sprintf('%02d.%s', $index + 1, $extension), $contents);
            $added++;
        }

        $zip->close();

        if ($added === 0) {
            @unlink($tempZip);
            abort(404);
        }

        $filename = Str::slug($product->name ?: $product->sku).'-photos.zip';

        return response()->streamDownload(function () use ($tempZip): void {
            readfile($tempZip);
            @unlink($tempZip);
        }, $filename, ['Content-Type' => 'application/zip']);
    }
}
