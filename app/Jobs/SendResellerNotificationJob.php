<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\ResellerNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one SMS to a reseller (see ResellerNotificationService). It runs once:
 * a failed send is logged, never retried, so a reseller cannot get the same
 * message twice.
 */
class SendResellerNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public int $companyId,
        public string $event,
        public int $resellerId,
        public ?int $subjectId = null,
        public ?string $once = null,
    ) {}

    public function handle(CompanyContext $context, ResellerNotificationService $notifications): void
    {
        $company = Company::query()->find($this->companyId);

        if (! $company) {
            return;
        }

        // On a real queue worker there is no company yet; with the sync queue the job runs inside
        // the request that caused it, so what that request had set is put back afterwards.
        $previous = [$context->company(), $context->isAllCompanies(), $context->deniesCompanyAccess()];
        $context->set($company);

        try {
            $notifications->send($this->companyId, $this->event, $this->resellerId, $this->subjectId, $this->once);
        } finally {
            match (true) {
                $previous[1] => $context->all(),
                $previous[2] => $context->none(),
                default => $context->set($previous[0]),
            };
        }
    }
}
