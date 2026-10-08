<?php

namespace App\Services;

use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\Investor;
use App\Models\InvestorPortalAccount;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Support\StorefrontApi\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Investor-portal sign-ins (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §4.3–4.4):
 * staff invite an investor or channel partner, the person sets a password
 * from the SMS link, and later signs in with phone + password or phone + an
 * SMS code. The one-time-code rules are the storefront's
 * (CustomerAccountService): hashed, 6 digits, 10 minutes, 5 tries, one send
 * per minute. Phones are stored as 01XXXXXXXXX (StorefrontApi\Phone).
 */
class InvestorPortalAccountService
{
    public const INVITE_VALID_DAYS = 7;

    public const CODE_VALID_MINUTES = 10;

    public const CODE_MAX_ATTEMPTS = 5;

    public function __construct(
        protected StorefrontNotificationService $notifications,
        protected AuditLogService $audit,
    ) {}

    /** The account of an investor or partner, if one was created. */
    public function accountFor(Investor|ChannelPartner $holder): ?InvestorPortalAccount
    {
        $column = $holder instanceof Investor ? 'investor_id' : 'channel_partner_id';

        return InvestorPortalAccount::query()->withoutGlobalScopes()->where($column, $holder->getKey())->first();
    }

    /**
     * Creates (or refreshes) the account and sends the "set your password"
     * link by SMS. A person who is both an investor and a partner with the
     * same phone gets one account linked to both. Returns the invite link so
     * staff can share it by hand when SMS is not set up.
     */
    public function invite(Investor|ChannelPartner $holder, ?User $by = null): string
    {
        $company = Company::query()->findOrFail($holder->company_id);
        $this->assertPortalIsOn($company);
        $phone = $this->portalPhone($holder);
        $column = $holder instanceof Investor ? 'investor_id' : 'channel_partner_id';
        $token = Str::random(48);

        $account = DB::transaction(function () use ($holder, $company, $phone, $token, $by): InvestorPortalAccount {
            $account = $this->claimAccount($holder, $company, $phone);
            $account->forceFill([
                'invite_token_hash' => hash('sha256', $token),
                'invite_expires_at' => now()->addDays(self::INVITE_VALID_DAYS),
                'invited_at' => now(),
                'invited_by' => $by?->getKey(),
            ])->save();

            return $account;
        });

        $link = $company->investorPortalUrl('invite/'.$token);

        $this->audit->record('investor_portal.invited', $account, null, ['phone' => $phone, 'role' => $column]);

        $this->sendSms($company, $account, __('Your :company investor portal account is ready. Set your password here: :link (valid for :days days).', [
            'company' => $company->name,
            'link' => $link,
            'days' => self::INVITE_VALID_DAYS,
        ], $account->locale));

        return (string) $link;
    }

    /**
     * The holder's portal account, created when there is none yet, with no
     * invite link and no SMS. Staff use this to open the portal as the person
     * (InvestorPortalStaffEntryService) without inviting them first. The
     * account stays "invited" with no invite date until a real invite is sent.
     * The same checks as an invite apply: portal on, a phone number, the phone
     * not used by someone else, and the account not disabled.
     */
    public function ensureAccount(Investor|ChannelPartner $holder): InvestorPortalAccount
    {
        $company = Company::query()->findOrFail($holder->company_id);
        $this->assertPortalIsOn($company);
        $phone = $this->portalPhone($holder);

        return DB::transaction(function () use ($holder, $company, $phone): InvestorPortalAccount {
            $account = $this->claimAccount($holder, $company, $phone);
            $account->save();

            return $account;
        });
    }

    protected function assertPortalIsOn(Company $company): void
    {
        if (! $company->investor_portal_enabled || $company->investorPortalHost() === null) {
            throw ValidationException::withMessages(['portal' => __('Turn the investor portal on in Investor Portal Settings first.')]);
        }
    }

    protected function portalPhone(Investor|ChannelPartner $holder): string
    {
        $phone = Phone::normalize((string) $holder->phone);

        if ($phone === '') {
            throw ValidationException::withMessages(['phone' => __('Add a phone number before inviting this person to the portal.')]);
        }

        return $phone;
    }

    /**
     * The account this holder signs in with (their own, the one that already
     * uses their phone, or a new one), pointed at the holder but not saved.
     */
    protected function claimAccount(Investor|ChannelPartner $holder, Company $company, string $phone): InvestorPortalAccount
    {
        $column = $holder instanceof Investor ? 'investor_id' : 'channel_partner_id';

        $account = $this->accountFor($holder)
            ?? InvestorPortalAccount::query()->withoutGlobalScopes()->where('company_id', $company->getKey())->where('phone', $phone)->first()
            ?? new InvestorPortalAccount(['company_id' => $company->getKey(), 'phone' => $phone, 'name' => $holder->name]);

        if ($account->{$column} !== null && (int) $account->{$column} !== (int) $holder->getKey()) {
            throw ValidationException::withMessages(['phone' => __('This phone number already signs in to the portal for someone else.')]);
        }

        if ($account->status === InvestorPortalAccount::STATUS_DISABLED) {
            throw ValidationException::withMessages(['portal' => __('This portal account is disabled. Enable it before sending a new invite.')]);
        }

        $account->{$column} = $holder->getKey();
        $account->name = $account->exists && $account->name ? $account->name : $holder->name;

        return $account;
    }

    /** The account a still-valid invite link belongs to, on this company's portal. */
    public function findByInviteToken(string $token): ?InvestorPortalAccount
    {
        if ($token === '') {
            return null;
        }

        return InvestorPortalAccount::query()
            ->where('invite_token_hash', hash('sha256', $token))
            ->where('invite_expires_at', '>', now())
            ->where('status', '!=', InvestorPortalAccount::STATUS_DISABLED)
            ->first();
    }

    /** Sets the password from an invite link; the link then stops working. */
    public function acceptInvite(InvestorPortalAccount $account, string $password): void
    {
        $account->forceFill([
            'password' => $password,
            'status' => InvestorPortalAccount::STATUS_ACTIVE,
            'invite_token_hash' => null,
            'invite_expires_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        $this->audit->record('investor_portal.invite_accepted', $account);
    }

    /** A sign-in candidate for this company's portal by phone (any spelling of the number). */
    public function findByPhone(string $phone): ?InvestorPortalAccount
    {
        $phone = Phone::normalize($phone);

        return $phone === '' ? null : InvestorPortalAccount::query()->where('phone', $phone)->first();
    }

    public function attemptPasswordLogin(string $phone, string $password): ?InvestorPortalAccount
    {
        $account = $this->findByPhone($phone);

        if (! $account || blank($account->password) || ! Hash::check($password, $account->password)) {
            $this->audit->record('investor_portal.login_failed', $account ?? InvestorPortalAccount::class, null, ['method' => 'password']);

            return null;
        }

        return $account;
    }

    public function smsAvailable(?Company $company): bool
    {
        $setting = $company ? StorefrontSetting::primaryFor((int) $company->getKey()) : null;

        return $setting !== null && $this->notifications->smsConfigured($setting);
    }

    /**
     * Sends a one-time code by SMS (sign-in or password reset). Within a
     * minute of the last send, the code already sent is kept and true is
     * returned, so a double tap never sends two messages.
     */
    public function sendCode(InvestorPortalAccount $account, string $purpose = 'login'): bool
    {
        if ($account->otp_sent_at?->isAfter(now()->subMinute()) && $account->otp_expires_at?->isFuture()) {
            return true;
        }

        $company = Company::query()->find($account->company_id);
        $code = (string) random_int(100000, 999999);

        $account->forceFill([
            'otp_code_hash' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::CODE_VALID_MINUTES),
            'otp_sent_at' => now(),
            'otp_attempts' => 0,
        ])->save();

        $message = $purpose === 'reset'
            ? __('Your :company investor portal password reset code is :code. It expires in :minutes minutes.', ['company' => $company?->name, 'code' => $code, 'minutes' => self::CODE_VALID_MINUTES], $account->locale)
            : __('Your :company investor portal sign-in code is :code. It expires in :minutes minutes.', ['company' => $company?->name, 'code' => $code, 'minutes' => self::CODE_VALID_MINUTES], $account->locale);

        $sent = $company !== null && $this->sendSms($company, $account, $message);

        if (! $sent) {
            $this->clearCode($account);
        }

        $this->audit->record('investor_portal.code_sent', $account, null, ['purpose' => $purpose, 'sent' => $sent]);

        return $sent;
    }

    /** Checks a code; five wrong tries throw the code away. A correct code is used up. */
    public function verifyCode(InvestorPortalAccount $account, string $code): bool
    {
        if (
            blank($account->otp_code_hash)
            || ! $account->otp_expires_at
            || $account->otp_expires_at->isPast()
            || $account->otp_attempts >= self::CODE_MAX_ATTEMPTS
        ) {
            $this->clearCode($account);

            return false;
        }

        if (! Hash::check(trim($code), $account->otp_code_hash)) {
            $attempts = $account->otp_attempts + 1;
            $account->forceFill(['otp_attempts' => $attempts])->save();

            if ($attempts >= self::CODE_MAX_ATTEMPTS) {
                $this->clearCode($account);
            }

            $this->audit->record('investor_portal.login_failed', $account, null, ['method' => 'code']);

            return false;
        }

        $this->clearCode($account);

        return true;
    }

    /** New password after a verified reset code; other devices are signed out. */
    public function resetPassword(InvestorPortalAccount $account, string $code, string $password): bool
    {
        if (! $this->verifyCode($account, $code)) {
            return false;
        }

        $this->updatePassword($account, $password);
        $this->audit->record('investor_portal.password_reset', $account);

        return true;
    }

    public function updatePassword(InvestorPortalAccount $account, string $password): void
    {
        $account->forceFill([
            'password' => $password,
            // Kills "remember me" cookies on every other device.
            'remember_token' => Str::random(60),
        ])->save();
    }

    public function recordLogin(InvestorPortalAccount $account, ?string $ip, string $method): void
    {
        $account->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->save();
        $this->audit->record('investor_portal.login', $account, null, ['method' => $method]);
    }

    public function disable(InvestorPortalAccount $account): void
    {
        $account->forceFill([
            'status' => InvestorPortalAccount::STATUS_DISABLED,
            'invite_token_hash' => null,
            'invite_expires_at' => null,
            'remember_token' => Str::random(60),
        ])->save();
        $this->clearCode($account);
        $this->audit->record('investor_portal.disabled', $account);
    }

    /** Back to active when a password was set before, otherwise back to invited. */
    public function enable(InvestorPortalAccount $account): void
    {
        $account->forceFill([
            'status' => filled($account->password) ? InvestorPortalAccount::STATUS_ACTIVE : InvestorPortalAccount::STATUS_INVITED,
        ])->save();
        $this->audit->record('investor_portal.enabled', $account);
    }

    protected function sendSms(Company $company, InvestorPortalAccount $account, string $message): bool
    {
        $setting = StorefrontSetting::primaryFor((int) $company->getKey());

        return $setting !== null
            && $this->notifications->smsConfigured($setting)
            && $this->notifications->sendSms($setting, $account->phone, $message);
    }

    protected function clearCode(InvestorPortalAccount $account): void
    {
        $account->forceFill([
            'otp_code_hash' => null,
            'otp_expires_at' => null,
            'otp_sent_at' => null,
            'otp_attempts' => 0,
        ])->save();
    }
}
