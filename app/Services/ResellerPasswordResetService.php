<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * What staff can do when a reseller is locked out (owner request 2026-10-15):
 * set a new password for them, or email them a link to choose one.
 *
 * The link is the portal's own reset page on the reseller's portal host, even
 * though staff trigger it from the admin host, and it is also protected by a
 * one-time token (see CustomerAccountService::issueResetLink), so a forwarded
 * or re-used link does nothing.
 */
class ResellerPasswordResetService
{
    public const SENT = 'sent';

    public const NO_EMAIL = 'no_email';

    public const NO_PORTAL = 'no_portal';

    public const MAIL_NOT_SET_UP = 'mail_not_set_up';

    public const SEND_FAILED = 'send_failed';

    public const NOT_A_RESELLER = 'not_a_reseller';

    public function __construct(
        protected CustomerAccountService $accounts,
        protected StorefrontNotificationService $notifications,
        protected AuditLogService $audit,
    ) {}

    /** Whether the reseller can use the portal at all (a pending or approved application). */
    public function canReset(Customer $reseller): bool
    {
        return in_array($reseller->reseller_status, ['pending', 'approved'], true);
    }

    /** Staff choose the password; the reseller is signed out everywhere. */
    public function setPassword(Customer $reseller, string $password, ?User $by = null): void
    {
        $this->accounts->setPasswordByStaff($reseller, $password);

        // The password itself is never written down.
        $this->audit->record('reseller.password_set_by_staff', $reseller, null, ['staff_user_id' => $by?->getKey()]);
    }

    /** One of the constants above; SENT means a link is on its way to the reseller's own email. */
    public function sendLink(Customer $reseller, ?User $by = null): string
    {
        if (! $this->canReset($reseller)) {
            return self::NOT_A_RESELLER;
        }

        $email = trim((string) $reseller->email);

        if ($email === '') {
            return self::NO_EMAIL;
        }

        $company = $reseller->company;
        $setting = $company?->storefrontSetting;
        $host = $company?->resellerPortalHost();

        if (! $company || ! $host) {
            return self::NO_PORTAL;
        }

        if (! $setting || ! $this->notifications->emailDeliveryAvailable($setting)) {
            app(EmailLogService::class)->skipped((int) $company->getKey(), EmailLog::KIND_RESELLER_PASSWORD_RESET, $email, EmailLogService::REASON_NOT_CONFIGURED);

            return self::MAIL_NOT_SET_UP;
        }

        $token = $this->accounts->issueResetLink($reseller);
        $url = $this->linkOnPortalHost($host, $reseller, $token);

        if (! $this->notifications->sendResellerPasswordResetEmail($setting, $email, $company->name, $url, CustomerAccountService::RESET_LINK_MINUTES)) {
            $this->accounts->cancelReset($reseller);

            return self::SEND_FAILED;
        }

        $this->audit->record('reseller.password_reset_link_sent', $reseller, null, [
            'staff_user_id' => $by?->getKey(),
            'email' => $email,
        ]);

        return self::SENT;
    }

    /**
     * The signed link on the reseller's portal host. Staff are on the admin
     * host, but Laravel signs the whole URL, host included, so it is built as
     * if the request had come to the portal host.
     */
    protected function linkOnPortalHost(string $host, Customer $reseller, string $token): string
    {
        $generator = URL::getFacadeRoot();
        $original = $generator->getRequest();
        $scheme = $original->getScheme();

        $generator->setRequest(Request::create($scheme.'://'.$host.'/'));

        try {
            return $generator->temporarySignedRoute(
                'filament.reseller.auth.password-reset.reset',
                now()->addMinutes(CustomerAccountService::RESET_LINK_MINUTES),
                ['customer' => $reseller->getKey(), 'token' => $token],
            );
        } finally {
            $generator->setRequest($original);
        }
    }
}
