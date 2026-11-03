<?php

namespace App\Filament\Concerns;

use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * For a Settings page that belongs to one site (a StorefrontSetting row) of the selected company, like its email
 * account: the company's sites, the site being shown (the chosen one, else the primary one, else the first), the
 * site picker's options, and the re-check of who is asking that every Livewire handler starts with. The page
 * declares `#[Locked] public ?int $companyId` and `#[Locked] public ?int $settingId`, and `pageName()` for the
 * error text.
 */
trait ManagesCompanySite
{
    abstract protected function pageName(): string;

    /** @return Collection<int, StorefrontSetting> */
    protected function companySettings(): Collection
    {
        return $this->companyId
            ? StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->companyId)->orderBy('site_type')->orderBy('id')->get()
            : collect();
    }

    protected function activeSetting(): ?StorefrontSetting
    {
        $rows = $this->companySettings();
        $primaryId = StorefrontSetting::primaryFor($this->companyId)?->getKey();

        return $rows->firstWhere('id', $this->settingId)
            ?? ($primaryId ? $rows->firstWhere('id', $primaryId) : null)
            ?? $rows->first();
    }

    /** @return array<int, string> */
    protected function siteOptions(): array
    {
        return $this->companySettings()
            ->mapWithKeys(fn (StorefrontSetting $row): array => [$row->getKey() => $row->siteTypeLabel().' · '.($row->siteDomain() ?: '—')])
            ->all();
    }

    /** The site being managed, after re-checking who is asking (every handler starts here). */
    protected function setting(): StorefrontSetting
    {
        $this->selectedCompany();
        $setting = $this->activeSetting();

        abort_unless($setting !== null, 404, __('This company has no site yet.'));

        return $setting;
    }

    protected function selectedCompany(): Company
    {
        $company = app(CompanyContext::class)->company();

        abort_unless($company !== null, 404, 'Select a company before opening '.$this->pageName().'.');

        if ($this->companyId !== null) {
            abort_unless((int) $company->getKey() === $this->companyId, 409, 'The selected company changed. Reload '.$this->pageName().' before continuing.');
        }

        $user = Auth::user();
        abort_unless($user?->canManageSettings() && $user->canAccessCompany((int) $company->getKey()), 403);

        return $company;
    }
}
