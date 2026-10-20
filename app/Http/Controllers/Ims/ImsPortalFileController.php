<?php

namespace App\Http\Controllers\Ims;

use App\Http\Controllers\Controller;
use App\Ims\Files\ImsFiles;
use App\Ims\Reports\ProjectReportService;
use App\Ims\Transparency\InvestorProjectSummary;
use App\Models\FeedPostImage;
use App\Models\InvestorKycDocument;
use App\Models\InvestorPortalAccount;
use App\Models\ProjectReport;
use App\Models\ProjectUpdatePhoto;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * IMS files in the investor portal (IMS-V2/TRD.md §9): the signed-in
 * investor's own KYC pictures, photos of updates on projects they have money
 * in, published feed pictures and published project reports. Always for the
 * logged-in account, never an id from the browser alone.
 */
class ImsPortalFileController extends Controller
{
    public function __construct(private ImsFiles $files, private CompanyContext $context) {}

    public function kyc(int $document): StreamedResponse
    {
        $record = InvestorKycDocument::query()->findOrFail($document);
        abort_unless((int) $record->investor_id === (int) $this->account()->investor_id, 404);

        return $this->files->response($this->context->company(), $record->path, $record->type);
    }

    public function updatePhoto(int $photo): StreamedResponse
    {
        $record = ProjectUpdatePhoto::query()->with('projectUpdate.project')->findOrFail($photo);
        $update = $record->projectUpdate;

        abort_unless($update?->visible_to_investors && app(InvestorProjectSummary::class)->hasMoneyIn($update->project, $this->account()->investor), 404);

        return $this->files->response($this->context->company(), $record->path, 'photo-'.$record->getKey());
    }

    public function feedImage(int $image): StreamedResponse
    {
        $record = FeedPostImage::query()->with('post')->findOrFail($image);
        abort_unless($record->post !== null && $record->post->published_at !== null && $record->post->published_at->isPast(), 404);

        return $this->files->response($this->context->company(), $record->path, 'image-'.$record->getKey());
    }

    public function projectReport(int $report): Response
    {
        $record = ProjectReport::query()->with('project')->findOrFail($report);
        abort_unless($record->isPublished() && app(InvestorProjectSummary::class)->hasMoneyIn($record->project, $this->account()->investor), 404);

        return response(app(ProjectReportService::class)->pdf($record), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="report-'.$record->getKey().'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function account(): InvestorPortalAccount
    {
        $account = Filament::auth()->user();
        abort_unless($account instanceof InvestorPortalAccount && $account->isInvestor(), 404);

        return $account;
    }
}
