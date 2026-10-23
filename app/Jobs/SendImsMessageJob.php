<?php

namespace App\Jobs;

use App\Mail\ImsMessage;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\SmsTemplateService;
use App\Services\StorefrontNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one IMS news item by SMS and / or e-mail, through the company's own
 * gateway and SMTP (IMS-V2/TRD.md §8). Sets and clears CompanyContext, as
 * every queued job must.
 */
class SendImsMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param  list<string>  $lines */
    public function __construct(
        public int $companyId,
        public string $phone,
        public ?string $sms,
        public ?string $email,
        public string $heading,
        public array $lines,
        public ?string $link = null,
        public ?string $pdfName = null,
        public ?string $pdfBase64 = null,
    ) {}

    public function handle(SmsTemplateService $templates, StorefrontNotificationService $notifications): void
    {
        $company = Company::query()->find($this->companyId);

        if (! $company) {
            return;
        }

        $context = app(CompanyContext::class);
        $context->set($company);

        try {
            if ($this->sms !== null && $this->phone !== '' && ($gateway = $templates->gatewayFor($this->companyId)) !== null) {
                $notifications->sendSms($gateway, $this->phone, $this->sms);
            }

            if ($this->email !== null) {
                $setting = StorefrontSetting::primaryFor($this->companyId);

                if ($setting !== null) {
                    $notifications->sendMailable($setting, $this->email, new ImsMessage($company->name, $this->heading, $this->lines, $this->link, $this->pdfName, $this->pdfBase64), EmailLog::KIND_IMS_MESSAGE);
                }
            }
        } finally {
            $context->clear();
        }
    }
}
