<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * What the admin panel's company switcher shows: the companies the signed-in
 * user may switch to, which one is active ("all" for a super admin looking at
 * every company) and its name.
 *
 * One place for the rule, used by both switchers — the dropdown in the top
 * bar (resources/views/filament/partials/company-switcher.blade.php) and the
 * company tab of the phone bottom bar (App\Livewire\AdminBottomNavigation).
 */
final class CompanySwitcher
{
    /**
     * Null when the switcher must not be drawn: nobody is signed in, or the
     * user can only ever see one company.
     *
     * @return array{companies: Collection<int, Company>, selected: string, selectedName: string, isSuperAdmin: bool}|null
     */
    public static function for(?User $user): ?array
    {
        if (! $user || ! Schema::hasTable('companies')) {
            return null;
        }

        $companies = $user->accessibleCompanies()->get();

        if (! $user->isSuperAdmin() && $companies->count() <= 1) {
            return null;
        }

        $context = app(CompanyContext::class);
        $sessionCompany = session('current_company_id');

        $selected = $context->isAllCompanies()
            ? 'all'
            : ($context->id() ?: ($sessionCompany ?: ($user->isSuperAdmin() ? 'all' : '')));

        $selectedName = $selected === 'all'
            ? 'All Companies'
            : ($companies->firstWhere('id', (int) $selected)?->name ?? $companies->first()?->name ?? 'Select company');

        return [
            'companies' => $companies,
            'selected' => (string) $selected,
            'selectedName' => $selectedName,
            'isSuperAdmin' => $user->isSuperAdmin(),
        ];
    }
}
