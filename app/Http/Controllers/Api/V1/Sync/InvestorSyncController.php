<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Models\SettlementPayout;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Sync\InvestorSyncFeed;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The investors' side of the IMS link: the same read-only feed as the business
 * data, over investors, groups, projects, investments and payouts. Reachable
 * only with an "Investor sync" API key (CompanyApiKey::SCOPE_INVESTOR_SYNC).
 */
class InvestorSyncController extends SyncController
{
    public function __construct(InvestorSyncFeed $feed, CompanyContext $context, protected CompanyStorageService $storage)
    {
        parent::__construct($feed, $context);
    }

    /** The payment screenshot of one payout, as the file. */
    public function proof(int $payout): StreamedResponse
    {
        // The company scope pins this to the key's own company.
        $record = SettlementPayout::query()->findOrFail($payout);
        $document = $record->paymentProof();

        abort_if($document === null || blank($document->file_path), 404, 'This payout has no payment screenshot.');

        try {
            $location = $this->storage->locatePrivate($document->file_path, $this->context->company());
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
