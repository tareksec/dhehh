<?php

namespace App\Http\Controllers\InvestorPortal;

use App\Http\Controllers\Controller;
use App\Models\InvestorPortalAccount;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\InvestorPortalService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Investor-portal document download. A document is served only if it is in
 * the signed-in account's own document scope (InvestorPortalService::documents());
 * anything else is a 404, whatever its id.
 */
class InvestorPortalDocumentController extends Controller
{
    public function __invoke(int $document, InvestorPortalService $portal, CompanyContext $context, CompanyStorageService $storage): StreamedResponse
    {
        $account = Filament::auth()->user();
        abort_unless($account instanceof InvestorPortalAccount, 404);

        $record = $portal->documents($account)->whereKey($document)->first();
        $company = $context->company();

        abort_if($record === null || $company === null || blank($record->file_path), 404);

        try {
            $location = $storage->locatePrivate($record->file_path, $company);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_if($location === null, 404);

        app(AuditLogService::class)->record('investor_portal.document_downloaded', $record);

        return Storage::disk($location['disk'])->response(
            $location['path'],
            basename($location['path']),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
            'attachment',
        );
    }
}
