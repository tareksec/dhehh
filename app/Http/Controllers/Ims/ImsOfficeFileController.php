<?php

namespace App\Http\Controllers\Ims;

use App\Http\Controllers\Controller;
use App\Ims\Files\ImsFiles;
use App\Ims\Reports\ProjectReportService;
use App\Models\CapitalReturn;
use App\Models\FeedPostImage;
use App\Models\InvestorKycDocument;
use App\Models\ProjectReport;
use App\Models\ProjectUpdatePhoto;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * IMS files for the office (IMS-V2/TRD.md §9): only the company's own
 * records (CompanyScope), only with the right permission, never cached.
 * Opening a KYC picture is logged.
 */
class ImsOfficeFileController extends Controller
{
    public function __construct(private ImsFiles $files, private CompanyContext $context) {}

    public function kyc(int $document): StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('ims.kyc.review') || $user?->hasPermission('investments.manage'), 403);

        $record = InvestorKycDocument::query()->findOrFail($document);
        app(AuditLogService::class)->record('ims.kyc_picture_viewed', $record, null, ['type' => $record->type]);

        return $this->files->response($this->context->company(), $record->path, $record->type.'.'.$this->extension($record->mime));
    }

    public function updatePhoto(int $photo): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('investments.view'), 403);
        $record = ProjectUpdatePhoto::query()->findOrFail($photo);

        return $this->files->response($this->context->company(), $record->path, 'photo-'.$record->getKey());
    }

    public function feedImage(int $image): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('investments.view'), 403);
        $record = FeedPostImage::query()->findOrFail($image);

        return $this->files->response($this->context->company(), $record->path, 'image-'.$record->getKey());
    }

    public function capitalReturnProof(int $return): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('investments.view'), 403);
        $record = CapitalReturn::query()->findOrFail($return);
        abort_if(blank($record->proof_path), 404);

        return $this->files->response($this->context->company(), $record->proof_path, 'capital-return-'.$record->getKey(), 'attachment');
    }

    public function projectReport(int $report): Response
    {
        abort_unless(auth()->user()?->hasPermission('investments.view'), 403);
        $record = ProjectReport::query()->findOrFail($report);

        return response(app(ProjectReportService::class)->pdf($record), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="report-'.$record->getKey().'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function extension(?string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'jpg',
        };
    }
}
