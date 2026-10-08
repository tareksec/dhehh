<?php

namespace App\Services;

use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\InvestorPortalAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Portal agreements (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md Phase 3;
 * owner's draft: "a Mudarabah agreement is accepted in the portal, with
 * timestamp and IP logged"). This sits beside the signed paper deed, which
 * stays the contract of record. Staff write and publish the text.
 */
class AgreementService
{
    public function __construct(protected AuditLogService $audit) {}

    /** The agreement kinds this account has to accept: investors (and applicants) the investor agreement, partners the partner agreement. */
    public function kindsFor(InvestorPortalAccount $account): array
    {
        return array_values(array_filter([
            $account->isInvestor() || $account->isApplicant() ? AgreementTemplate::KIND_INVESTOR : null,
            $account->isChannelPartner() ? AgreementTemplate::KIND_PARTNER : null,
        ]));
    }

    /** @return Collection<int, AgreementTemplate> the current published versions this account has not accepted yet */
    public function outstanding(InvestorPortalAccount $account): Collection
    {
        return collect($this->kindsFor($account))
            ->map(fn (string $kind): ?AgreementTemplate => AgreementTemplate::current($kind))
            ->filter()
            ->reject(fn (AgreementTemplate $template): bool => $this->accepted($account, $template) !== null)
            ->values();
    }

    public function accepted(InvestorPortalAccount $account, AgreementTemplate $template): ?AgreementAcceptance
    {
        return AgreementAcceptance::query()
            ->where('agreement_template_id', $template->getKey())
            ->where('portal_account_id', $account->getKey())
            ->first();
    }

    public function accept(InvestorPortalAccount $account, AgreementTemplate $template, ?Request $request = null): AgreementAcceptance
    {
        if (! $template->isPublished() || (int) $template->company_id !== (int) $account->company_id || ! in_array($template->kind, $this->kindsFor($account), true)) {
            throw ValidationException::withMessages(['agreement' => __('This agreement is not available.')]);
        }

        if ($existing = $this->accepted($account, $template)) {
            return $existing;
        }

        $request ??= request();

        $acceptance = AgreementAcceptance::query()->create([
            'company_id' => $account->company_id,
            'agreement_template_id' => $template->getKey(),
            'portal_account_id' => $account->getKey(),
            'investor_id' => $account->investor_id,
            'channel_partner_id' => $account->channel_partner_id,
            'name' => $account->name,
            'accepted_at' => now(),
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500),
            'body_sha256' => $template->bodyHash(),
        ]);

        $this->audit->record('agreement.accepted', $acceptance, null, ['template' => $template->kind.' v'.$template->version]);

        return $acceptance;
    }

    public function publish(AgreementTemplate $template, User $by): void
    {
        if ($template->isPublished()) {
            return;
        }

        if (blank($template->title) || blank(trim(strip_tags((string) $template->body)))) {
            throw ValidationException::withMessages(['body' => __('Write the agreement text before publishing it.')]);
        }

        $template->forceFill(['published_at' => now(), 'published_by' => $by->getKey()])->save();
        $this->audit->record('agreement.published', $template, null, ['kind' => $template->kind, 'version' => $template->version]);
    }
}
