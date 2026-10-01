<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvestmentDocument;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvestmentDocumentDownloadController extends Controller
{
    public function __invoke(Request $request, int $document, CompanyContext $context, CompanyStorageService $storage): StreamedResponse
    {
        $user = $request->user();
        $record = InvestmentDocument::withoutGlobalScopes()->findOrFail($document);
        $company = $context->company();

        if (! $company && $context->isAllCompanies() && $user?->isSuperAdmin()) {
            $company = $record->company()->first();
        }

        abort_unless(
            $user?->is_active
            && $company
            && (int) $record->company_id === (int) $company->getKey()
            && $user->canAccessCompany((int) $company->getKey()),
            404,
        );
        abort_unless($user->hasPermission('investments.view'), 403);
        // NID copies and contracts: investments.manage only (owner's rule).
        abort_if($record->isSensitive() && ! $user->hasPermission('investments.manage'), 403);
        abort_if(blank($record->file_path), 404);

        try {
            $location = $storage->locatePrivate($record->file_path, $company);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_if($location === null, 404);

        return Storage::disk($location['disk'])->response(
            $location['path'],
            basename($location['path']),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
            'attachment',
        );
    }
}
