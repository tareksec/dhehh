<?php

namespace App\Services;

use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\InvestmentDocument;
use App\Models\Investor;
use App\Models\InvestorDraft;
use App\Models\InvestorPortalAccount;
use App\Support\InvestorIdentity;
use App\Support\StorefrontApi\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self sign-up on the investor portal (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md
 * Phase 3; owner's draft: "the investor self-registers through a referrer's
 * invite link and the admin verifies"). The application becomes an
 * Investor Draft (source "portal") with its KYC files, so staff review it
 * in the same queue as imports; confirming the draft creates the investor
 * and activates the sign-in, discarding it marks the application rejected.
 * Money and the paper deed are still handled at the office.
 */
class InvestorOnboardingService
{
    public function __construct(protected AuditLogService $audit) {}

    /**
     * @param  array{name: string, phone: string, password: string, nid_number: string, guardian_name?: ?string, date_of_birth?: ?string, email?: ?string, address?: ?string, nominee_name?: ?string, nominee_relation?: ?string, nominee_phone?: ?string, nominee_nid_or_passport?: ?string}  $data
     * @param  array<string, list<string>>  $files  document category => stored private paths
     */
    public function register(Company $company, ChannelPartner $partner, array $data, array $files): InvestorPortalAccount
    {
        $phone = Phone::normalize((string) $data['phone']);

        if (InvestorPortalAccount::query()->withoutGlobalScopes()->where('company_id', $company->getKey())->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['data.phone' => __('This phone number already has an investor portal account. Please sign in instead.')]);
        }

        $nidHash = InvestorIdentity::hash($data['nid_number'] ?? null);

        if ($nidHash !== null && (
            Investor::query()->withoutGlobalScopes()->where('company_id', $company->getKey())->where('nid_hash', $nidHash)->exists()
            || InvestorDraft::query()->withoutGlobalScopes()->where('company_id', $company->getKey())->where('nid_hash', $nidHash)->where('source', InvestorDraft::SOURCE_PORTAL)->exists()
        )) {
            throw ValidationException::withMessages(['data.nid_number' => __('An investor or an application with this NID / passport number already exists. Please contact the company office.')]);
        }

        $account = DB::transaction(function () use ($company, $partner, $data, $files, $phone): InvestorPortalAccount {
            $account = new InvestorPortalAccount([
                'company_id' => $company->getKey(),
                'name' => trim((string) $data['name']),
                'phone' => $phone,
                'status' => InvestorPortalAccount::STATUS_PENDING,
            ]);
            $account->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();

            $draft = InvestorDraft::query()->create([
                'company_id' => $company->getKey(),
                'batch_id' => (string) Str::uuid(),
                'source' => InvestorDraft::SOURCE_PORTAL,
                'source_name' => __('Investor portal sign-up (invite code :code)', ['code' => $partner->invite_code]),
                'name' => trim((string) $data['name']),
                'guardian_name' => $data['guardian_name'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'phone' => $phone,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'nid_number' => $data['nid_number'],
                'channel_partner_id' => $partner->getKey(),
                'nominee_name' => $data['nominee_name'] ?? null,
                'nominee_relation' => $data['nominee_relation'] ?? null,
                'nominee_phone' => $data['nominee_phone'] ?? null,
                'nominee_nid_or_passport' => $data['nominee_nid_or_passport'] ?? null,
                'portal_account_id' => $account->getKey(),
            ]);

            foreach ($files as $category => $paths) {
                foreach ($paths as $path) {
                    $draft->documents()->create(['company_id' => $company->getKey(), 'category' => $category, 'file_path' => $path]);
                }
            }

            $account->forceFill(['investor_draft_id' => $draft->getKey()])->save();

            return $account;
        });

        $this->audit->record('investor_portal.registered', $account, null, ['channel_partner_id' => $partner->getKey()]);

        return $account;
    }

    /**
     * Called by InvestorImportService::confirm() before the draft is deleted:
     * the applicant's sign-in now belongs to the new investor, and the KYC
     * files move from the draft to the investor.
     */
    public function draftConfirmed(InvestorDraft $draft, Investor $investor): void
    {
        $account = $draft->portal_account_id
            ? InvestorPortalAccount::query()->withoutGlobalScopes()->find($draft->portal_account_id)
            : null;

        $draft->documents()->update([
            'documentable_type' => $investor->getMorphClass(),
            'documentable_id' => $investor->getKey(),
        ]);

        if (! $account || ! $account->isApplicant()) {
            return;
        }

        $account->forceFill([
            'investor_id' => $investor->getKey(),
            'status' => InvestorPortalAccount::STATUS_ACTIVE,
            'investor_draft_id' => null,
            'rejection_reason' => null,
        ])->save();

        $this->audit->record('investor_portal.application_approved', $account, ['status' => InvestorPortalAccount::STATUS_PENDING], ['status' => InvestorPortalAccount::STATUS_ACTIVE, 'investor_id' => $investor->getKey()]);
    }

    /** Staff turn an application down; the person sees the reason when they sign in. */
    public function reject(InvestorDraft $draft, ?string $reason): void
    {
        $account = $draft->portal_account_id
            ? InvestorPortalAccount::query()->withoutGlobalScopes()->find($draft->portal_account_id)
            : null;

        if ($account && $account->status === InvestorPortalAccount::STATUS_PENDING) {
            $account->forceFill([
                'status' => InvestorPortalAccount::STATUS_REJECTED,
                'rejection_reason' => filled($reason) ? trim((string) $reason) : null,
                'investor_draft_id' => null,
            ])->save();

            $this->audit->record('investor_portal.application_rejected', $account, ['status' => InvestorPortalAccount::STATUS_PENDING], ['status' => InvestorPortalAccount::STATUS_REJECTED, 'reason' => $account->rejection_reason]);
        }

        // The KYC files of a turned-down application are not kept on file.
        $draft->documents()->each(fn (InvestmentDocument $document) => $document->delete());
    }
}
