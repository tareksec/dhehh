<?php

namespace App\Ims\Onboarding;

use App\Ims\Settings\ImsSettings;
use App\Mail\ImsMessage;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\Investor;
use App\Models\InvestorInvite;
use App\Models\InvestorPortalAccount;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\SmsTemplateService;
use App\Services\StorefrontNotificationService;
use App\Support\StorefrontApi\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Joining IMS with an office invite (IMS-V2/PRD.md FR-04, ARCHITECTURE §8.2):
 * the office invites a phone or e-mail; the person opens the link on the IMS
 * domain, proves the contact with a one-time code, then sets a password and
 * gives their details and nominees. That creates the investor (KYC not sent
 * yet) and an active portal sign-in. A password is never sent.
 */
class InviteService
{
    public function __construct(
        private SmsTemplateService $templates,
        private StorefrontNotificationService $notifications,
        private AuditLogService $audit,
    ) {}

    /** "01711000000" or "name@example.com", with its type; null when it is neither. */
    public static function normalizeContact(string $contact): ?array
    {
        $contact = trim($contact);

        if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            return [strtolower($contact), 'email'];
        }

        $phone = Phone::normalize($contact);

        return $phone !== '' ? [$phone, 'phone'] : null;
    }

    /**
     * @return array{invite: InvestorInvite, link: string, sent: bool}
     */
    public function invite(Company $company, string $contact, ?string $name, User $by): array
    {
        if (! $company->imsIsLive()) {
            throw ValidationException::withMessages(['contact' => __('IMS is not switched on for this company yet.')]);
        }

        $normalized = static::normalizeContact($contact);

        if ($normalized === null) {
            throw ValidationException::withMessages(['contact' => __('Enter a mobile number (01XXXXXXXXX) or an e-mail address.')]);
        }

        [$contact, $type] = $normalized;

        $taken = $type === 'phone'
            ? InvestorPortalAccount::query()->withoutGlobalScopes()->where('company_id', $company->getKey())->where('phone', $contact)->exists()
                || Investor::query()->withoutGlobalScopes()->where('company_id', $company->getKey())->where('phone', $contact)->exists()
            : Investor::query()->withoutGlobalScopes()->where('company_id', $company->getKey())->where('email', $contact)->exists();

        if ($taken) {
            throw ValidationException::withMessages(['contact' => __('This person is already an investor. Open their page and use "Invite to portal" instead.')]);
        }

        $token = Str::random(48);

        $invite = DB::transaction(function () use ($company, $contact, $type, $name, $by, $token): InvestorInvite {
            // One live invite per contact: an older one stops working.
            InvestorInvite::query()->withoutGlobalScopes()
                ->where('company_id', $company->getKey())->where('contact', $contact)
                ->whereNull('used_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return InvestorInvite::query()->withoutGlobalScopes()->create([
                'company_id' => $company->getKey(),
                'contact' => $contact,
                'contact_type' => $type,
                'name' => filled($name) ? trim((string) $name) : null,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(InvestorInvite::VALID_DAYS),
                'invited_by' => $by->getKey(),
            ]);
        });

        $link = (string) $company->investorLinkUrl('join/'.$token);
        $sent = $this->deliver($company, $invite, $link);

        $this->audit->record('ims.invite_sent', $invite, null, ['contact_type' => $type, 'sent' => $sent]);

        return ['invite' => $invite, 'link' => $link, 'sent' => $sent];
    }

    public function revoke(InvestorInvite $invite, User $by): void
    {
        if ($invite->used_at !== null) {
            throw ValidationException::withMessages(['invite' => __('This invite was already used.')]);
        }

        $invite->forceFill(['revoked_at' => now()])->save();
        $this->audit->record('ims.invite_revoked', $invite, null, ['by' => $by->getKey()]);
    }

    /**
     * The invite a link belongs to, when it can still be used and joining by
     * invite is switched on. Null otherwise, with no hint of why (an unknown
     * link gets the same answer as an expired one).
     */
    public function findUsable(Company $company, string $token): ?InvestorInvite
    {
        if ($token === '' || ! (bool) ImsSettings::get($company, 'invite_registration_enabled', false)) {
            return null;
        }

        $invite = InvestorInvite::query()->withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $invite?->isUsable() ? $invite : null;
    }

    /**
     * Finishes joining: the investor, their nominees and an active sign-in.
     *
     * @param  array{name: string, phone: string, email?: string|null, date_of_birth?: string|null, nid_number?: string|null, address?: string|null, guardian_name?: string|null, password: string, nominees?: list<array<string, mixed>>}  $data
     */
    public function complete(InvestorInvite $invite, array $data): InvestorPortalAccount
    {
        $phone = Phone::normalize((string) ($data['phone'] ?? ''));

        if ($phone === '') {
            throw ValidationException::withMessages(['phone' => __('Enter your mobile number.')]);
        }

        app(NomineeService::class)->validate(array_values(array_filter($data['nominees'] ?? [], fn (array $row): bool => filled($row['name'] ?? null))));

        return DB::transaction(function () use ($invite, $data, $phone): InvestorPortalAccount {
            $locked = InvestorInvite::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invite->getKey());

            if (! $locked->isUsable()) {
                throw ValidationException::withMessages(['invite' => __('This invite link is not valid any more. Please ask the office for a new one.')]);
            }

            if (InvestorPortalAccount::query()->withoutGlobalScopes()->where('company_id', $locked->company_id)->where('phone', $phone)->exists()) {
                throw ValidationException::withMessages(['phone' => __('This mobile number already signs in to the portal. Sign in instead, or ask the office.')]);
            }

            $investor = Investor::query()->create([
                'company_id' => $locked->company_id,
                'name' => trim((string) $data['name']),
                'phone' => $phone,
                'email' => filled($data['email'] ?? null) ? strtolower(trim((string) $data['email'])) : ($locked->contact_type === 'email' ? $locked->contact : null),
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'nid_number' => $data['nid_number'] ?? null,
                'address' => $data['address'] ?? null,
                'guardian_name' => $data['guardian_name'] ?? null,
            ]);

            // The contact was proven with a code: an invited e-mail is verified.
            if ($locked->contact_type === 'email') {
                $investor->forceFill(['email_verified_at' => now()])->save();
            }

            if (! empty($data['nominees'])) {
                app(NomineeService::class)->replace($investor, $data['nominees'], 'investor');
            }

            $account = InvestorPortalAccount::query()->withoutGlobalScopes()->create([
                'company_id' => $locked->company_id,
                'investor_id' => $investor->getKey(),
                'name' => $investor->name,
                'phone' => $phone,
                'status' => InvestorPortalAccount::STATUS_ACTIVE,
            ]);
            $account->forceFill(['password' => (string) $data['password'], 'remember_token' => Str::random(60)])->save();

            $locked->forceFill(['used_at' => now(), 'investor_id' => $investor->getKey()])->save();

            $this->audit->record('ims.invite_completed', $locked, null, ['investor_id' => $investor->getKey()]);

            return $account;
        });
    }

    private function deliver(Company $company, InvestorInvite $invite, string $link): bool
    {
        $text = __('You are invited to invest with :company. Join here: :link (valid for :days days).', [
            'company' => $company->name,
            'link' => $link,
            'days' => InvestorInvite::VALID_DAYS,
        ]);

        if ($invite->contact_type === 'phone') {
            $gateway = $this->templates->gatewayFor((int) $company->getKey());

            return $gateway !== null && $this->notifications->sendSms($gateway, $invite->contact, $text);
        }

        $setting = StorefrontSetting::primaryFor((int) $company->getKey());

        return $setting !== null && $this->notifications->sendMailable($setting, $invite->contact, new ImsMessage($company->name, __('Your invitation'), [$text], $link), EmailLog::KIND_IMS_INVITATION);
    }
}
