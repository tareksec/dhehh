<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InvestorPortalAccount;
use App\Models\User;
use App\Support\InvestorPortalStaffPreview;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogService
{
    public function record(
        string $action,
        string|Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
    ): void {
        $request ??= request();
        $auditableType = is_string($auditable) ? $auditable : $auditable::class;
        $auditableId = is_string($auditable) ? null : $auditable->getKey();
        $companyId = $this->companyIdFor($auditable);

        AuditLog::query()->create([
            'company_id' => $companyId,
            'user_id' => $this->actorId(),
            'actor_type' => $this->portalActor()?->getMorphClass(),
            'actor_id' => $this->portalActor()?->getKey(),
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /**
     * audit_logs.user_id points at staff users. On the reseller portal the
     * default guard is switched to "customer" (Filament's Authenticate
     * middleware), so Auth::id() there is a reseller's id -- which is not a
     * user id and must never be stored as one (a foreign-key error at best,
     * another person's name on the log at worst). Only staff are attributed.
     */
    protected function actorId(): ?int
    {
        $actor = Auth::user();

        if ($actor instanceof User) {
            return $actor->getKey();
        }

        // A staff member looking at the investor portal as someone
        // (InvestorPortalStaffPreview): what is recorded is theirs, not the investor's.
        if ($actor instanceof InvestorPortalAccount && InvestorPortalStaffPreview::isFor($actor)) {
            return InvestorPortalStaffPreview::current()['staff_user_id'];
        }

        return null;
    }

    /**
     * A signed-in portal user (investor-portal account, reseller) who is not
     * staff. Stored in actor_type/actor_id so their own actions are attributed
     * without ever being mistaken for a staff user id.
     */
    protected function portalActor(): ?Model
    {
        $actor = Auth::user();

        if ($actor instanceof InvestorPortalAccount && InvestorPortalStaffPreview::isFor($actor)) {
            return null;
        }

        return $actor instanceof Model && ! $actor instanceof User ? $actor : null;
    }

    protected function companyIdFor(string|Model $auditable): ?int
    {
        if ($auditable instanceof Model && $auditable->getAttribute('company_id')) {
            return (int) $auditable->getAttribute('company_id');
        }

        if (app()->bound(CompanyContext::class) && app(CompanyContext::class)->hasCompany()) {
            return app(CompanyContext::class)->id();
        }

        return null;
    }
}
