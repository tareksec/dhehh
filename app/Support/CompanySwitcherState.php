<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * What a company switcher shows: the companies the signed-in person may open,
 * which one is open now, and whether there is anything to switch to.
 *
 * Shared by the header drop-down (resources/views/filament/partials/company-switcher.blade.php)
 * and the phone bottom bar (AdminBottomNav), so the two can never disagree about
 * the open company. The switch itself is `POST admin.company.switch`.
 */
final class CompanySwitcherState
{
    public const ALL = 'all';

    /**
     * @return array{companies: Collection<int, Company>, selected: int|string, selectedName: string, canSwitch: bool, canSelectAll: bool}|null null when nobody is signed in or the companies table is missing
     */
    public static function for(?User $user): ?array
    {
        if (! $user || ! Schema::hasTable('companies')) {
            return null;
        }

        $companies = $user->accessibleCompanies()->get();
        $context = app(CompanyContext::class);
        $sessionCompany = session('current_company_id');
        $isSuperAdmin = $user->isSuperAdmin();

        $selected = $context->isAllCompanies()
            ? self::ALL
            : ($context->id() ?: ($sessionCompany ?: ($isSuperAdmin ? self::ALL : '')));

        $selectedName = $selected === self::ALL
            ? __('All Companies')
            : ($companies->firstWhere('id', (int) $selected)?->name ?? $companies->first()?->name ?? __('Select company'));

        return [
            'companies' => $companies,
            'selected' => $selected,
            'selectedName' => $selectedName,
            'canSwitch' => $isSuperAdmin || $companies->count() > 1,
            'canSelectAll' => $isSuperAdmin,
        ];
    }
}
