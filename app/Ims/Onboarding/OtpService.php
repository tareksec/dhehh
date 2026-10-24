<?php

namespace App\Ims\Onboarding;

use App\Mail\ImsMessage;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\InvestorOtpCode;
use App\Models\StorefrontSetting;
use App\Services\AuditLogService;
use App\Services\EmailLogService;
use App\Services\SmsTemplateService;
use App\Services\StorefrontNotificationService;
use Illuminate\Support\Facades\Hash;

/**
 * Six-digit one-time codes for joining IMS (IMS-V2/PRD.md FR-04, old IMS §6):
 * valid 3 minutes at most (InvestorOtpCode::VALID_MINUTES), five wrong tries, at most one send a minute and five an
 * hour per contact. Only the hash is kept. A phone gets the code by SMS (the
 * gateway's OTP format), an e-mail address by e-mail.
 */
class OtpService
{
    public const MAX_SENDS_PER_HOUR = 5;

    public function __construct(
        private SmsTemplateService $templates,
        private StorefrontNotificationService $notifications,
        private AuditLogService $audit,
    ) {}

    /**
     * Sends a fresh code, or keeps the one sent less than a minute ago. False
     * when the hourly limit is reached or nothing could be sent.
     */
    public function send(Company $company, string $contact, string $contactType, string $purpose = InvestorOtpCode::PURPOSE_INVITE): bool
    {
        $latest = $this->latest($company, $contact, $purpose);

        if ($latest?->last_sent_at?->isAfter(now()->subSeconds(InvestorOtpCode::RESEND_SECONDS)) && $latest->expires_at->isFuture() && $latest->used_at === null) {
            return true;
        }

        $sentThisHour = InvestorOtpCode::query()->withoutGlobalScopes()
            ->where('company_id', $company->getKey())->where('contact', $contact)->where('purpose', $purpose)
            ->where('last_sent_at', '>', now()->subHour())->count();

        if ($sentThisHour >= self::MAX_SENDS_PER_HOUR) {
            return false;
        }

        $code = (string) random_int(100000, 999999);

        InvestorOtpCode::query()->withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'contact' => $contact,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(InvestorOtpCode::VALID_MINUTES),
            'last_sent_at' => now(),
        ]);

        $sent = $contactType === 'email' ? $this->sendByEmail($company, $contact, $code) : $this->sendBySms($company, $contact, $code);

        $this->audit->record('ims.otp_sent', $company, null, ['purpose' => $purpose, 'channel' => $contactType, 'sent' => $sent]);

        return $sent;
    }

    /** Checks a code; five wrong tries end it. A right code is used up. */
    public function verify(Company $company, string $contact, string $code, string $purpose = InvestorOtpCode::PURPOSE_INVITE): bool
    {
        $row = $this->latest($company, $contact, $purpose);

        if (! $row || $row->used_at !== null || $row->expires_at->isPast() || $row->attempts >= InvestorOtpCode::MAX_ATTEMPTS) {
            return false;
        }

        if (! Hash::check(trim($code), $row->code_hash)) {
            $row->forceFill(['attempts' => $row->attempts + 1])->save();

            return false;
        }

        $row->forceFill(['used_at' => now()])->save();

        return true;
    }

    private function latest(Company $company, string $contact, string $purpose): ?InvestorOtpCode
    {
        return InvestorOtpCode::query()->withoutGlobalScopes()
            ->where('company_id', $company->getKey())->where('contact', $contact)->where('purpose', $purpose)
            ->latest('id')->first();
    }

    private function sendBySms(Company $company, string $phone, string $code): bool
    {
        $gateway = $this->templates->gatewayFor((int) $company->getKey());

        if ($gateway === null) {
            return false;
        }

        $message = (string) $this->templates->render((int) $company->getKey(), SmsTemplateService::OTP_IMS_INVITE, [
            'company' => (string) $company->name,
            'portal' => $company->name.' Investment Portal',
            'code' => $code,
        ]);

        return $this->notifications->sendSms($gateway, $phone, $message);
    }

    private function sendByEmail(Company $company, string $email, string $code): bool
    {
        $setting = StorefrontSetting::primaryFor((int) $company->getKey());

        if ($setting === null || ! $this->notifications->emailDeliveryAvailable($setting)) {
            app(EmailLogService::class)->skipped((int) $company->getKey(), EmailLog::KIND_IMS_CODE, $email, EmailLogService::REASON_NOT_CONFIGURED);

            return false;
        }

        return $this->notifications->sendMailable($setting, $email, new ImsMessage(
            $company->name,
            __('Your code to join'),
            [__('Your code is :code. It works for :minutes minutes and only once.', ['code' => $code, 'minutes' => InvestorOtpCode::VALID_MINUTES])],
        ), EmailLog::KIND_IMS_CODE);
    }
}
