<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailAttachment;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads one file of an email to staff of the same company who may use CRM (owner request 2026-11-02). The file is always
 * sent as a download, never shown in the page, whatever its type: a received HTML or SVG file must not run on our domain.
 */
class EmailAttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, int $attachment, CompanyContext $context, CompanyStorageService $storage): StreamedResponse
    {
        $user = $request->user();
        $file = EmailAttachment::withoutGlobalScopes()->findOrFail($attachment);
        $company = $context->company();

        abort_unless(
            $user?->is_active
                && $company
                && (int) $file->company_id === (int) $company->getKey()
                && $user->canAccessCompany($company->getKey())
                && $user->hasPermission('crm.view'),
            404,
        );

        try {
            $location = $storage->locatePrivate($file->path, $company);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_if($location === null, 404);

        return Storage::disk($location['disk'])->download($location['path'], $file->filename, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
