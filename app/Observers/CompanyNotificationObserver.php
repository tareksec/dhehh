<?php

namespace App\Observers;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Services\BusinessNotificationService;

/**
 * "New company created" is Super-Admin-only, unconditionally -- per the
 * owner's explicit instruction, a company's own admins/staff never see this
 * event, and it bypasses the notifications.company permission entirely.
 * "Company info changed" stays scoped to that one company's permitted
 * users, same as every other business alert.
 */
class CompanyNotificationObserver
{
    public function __construct(
        protected BusinessNotificationService $notifications,
    ) {}

    public function created(Company $company): void
    {
        $this->notifications->notifySuperAdmins(
            'company.created',
            'New company added',
            "\"{$company->name}\" was added to the system.",
            // Always the ERP's page: a company can also be saved from another panel (the Trade Portal's Business profile).
            actionUrl: CompanyResource::getUrl('view', ['record' => $company], panel: 'admin'),
            actionLabel: 'View company',
        );
    }

    public function updated(Company $company): void
    {
        $meaningfulChanges = array_diff(array_keys($company->getChanges()), ['updated_at', 'created_at']);

        if ($meaningfulChanges === []) {
            return;
        }

        $this->notifications->notifyCompany(
            $company,
            'company.updated',
            'notifications.company',
            'Company info updated',
            "\"{$company->name}\"'s settings or information were changed.",
            actionUrl: CompanyResource::getUrl('view', ['record' => $company], panel: 'admin'),
            actionLabel: 'View company',
        );
    }
}
