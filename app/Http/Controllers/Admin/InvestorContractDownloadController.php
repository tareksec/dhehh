<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvestorSecurityInstrument;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvestorContractDownloadController extends Controller
{
    public function __invoke(Request $request, int $instrument, CompanyContext $context, CompanyStorageService $storage): StreamedResponse
    {
        $user = $request->user();
        $record = InvestorSecurityInstrument::withoutGlobalScopes()->findOrFail($instrument);
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
        // Signed contracts are visible to investments.manage only (owner's rule).
        abort_unless($user->hasPermission('investments.manage'), 403);
        abort_if(blank($record->contract_document_path), 404);

        try {
            $location = $storage->locatePrivate($record->contract_document_path, $company);
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
