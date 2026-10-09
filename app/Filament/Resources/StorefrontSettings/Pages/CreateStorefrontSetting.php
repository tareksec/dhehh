<?php

namespace App\Filament\Resources\StorefrontSettings\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\StorefrontSettings\Pages\Concerns\HandlesNextjsSiteDomain;
use App\Filament\Resources\StorefrontSettings\Pages\Concerns\HasStorefrontSettingsNavigation;
use App\Filament\Resources\StorefrontSettings\StorefrontSettingResource;
use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateStorefrontSetting extends CreateRecord
{
    use HandlesNextjsSiteDomain;
    use HasStickyHeaderFormActions;
    use HasStorefrontSettingsNavigation;

    protected static string $resource = StorefrontSettingResource::class;

    protected string $view = 'filament.resources.storefront-settings.settings-page';

    protected ?bool $hasDatabaseTransactions = true;

    /** Whether the new Next.js site starts as a copy of the company's Blade settings. */
    protected bool $copyFromBlade = false;

    public function mount(): void
    {
        parent::mount();

        $this->normalizeActiveSection();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getStickySaveFormAction(),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $siteType = ($data['site_type'] ?? null) === StorefrontSetting::SITE_NEXTJS
            ? StorefrontSetting::SITE_NEXTJS
            : StorefrontSetting::SITE_BLADE;
        $data['site_type'] = $siteType;
        $companyId = isset($data['company_id']) ? (int) $data['company_id'] : $this->activeCompanyId();

        // The form's own rule covers "all companies" mode; this also covers a
        // single-company session, where the company is implied.
        $alreadyHasSite = $companyId && StorefrontSetting::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('site_type', $siteType)
            ->exists();

        if ($alreadyHasSite) {
            throw ValidationException::withMessages(['data.site_type' => __('This company already has this kind of site.')]);
        }

        $this->copyFromBlade = $siteType === StorefrontSetting::SITE_NEXTJS && (bool) ($data['copy_from_blade'] ?? false);

        if ($siteType === StorefrontSetting::SITE_BLADE) {
            $this->syncCompanyDomain($this->domainSyncData($data));
        }

        unset($data['company_domain'], $data['company_domain_verified'], $data['copy_from_blade']);

        return $this->applySiteFields($data, null, $companyId, $siteType);
    }

    /**
     * A new Next.js site can start as a copy of the company's Blade settings.
     * The row is replicated from the stored (still encrypted) attributes, so
     * credentials are carried over as they are, never re-encrypted; only the
     * choices made on this form are applied on top.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $blade = $this->copyFromBlade ? StorefrontSetting::bladeFor((int) ($data['company_id'] ?? $this->activeCompanyId())) : null;

        if (! $blade) {
            return parent::handleRecordCreation($data);
        }

        $record = $blade->replicate(['nextjs_site_enabled', 'nextjs_site_domain', 'nextjs_site_domain_verified']);
        $record->forceFill(Arr::only($data, [
            'company_id',
            'site_type',
            'is_published',
            'customer_accounts_enabled',
            'nextjs_site_enabled',
            'nextjs_site_domain',
            'nextjs_site_domain_verified',
        ]));
        $record->save();

        return $record;
    }

    protected function activeCompanyId(): ?int
    {
        return app(CompanyContext::class)->id();
    }

    protected function domainSyncData(array $data): array
    {
        $rawState = $this->form->getRawState();

        if ($rawState instanceof Arrayable) {
            $rawState = $rawState->toArray();
        }

        if (! is_array($rawState)) {
            return $data;
        }

        return array_replace($rawState, $data);
    }

    protected function syncCompanyDomain(array $data): void
    {
        if (! isset($data['company_id'])) {
            return;
        }

        $company = Company::withoutGlobalScopes()->find($data['company_id']);

        if (! $company) {
            return;
        }

        $domain = Company::normalizeDomain($data['company_domain'] ?? null);
        $this->assertDomainIsAvailable($domain, (int) $company->getKey());
        $domainChanged = $domain !== Company::normalizeDomain($company->domain);

        $company->forceFill([
            'domain' => $domain,
            'domain_verified' => filled($domain)
                && ! $domainChanged
                && (bool) ($data['company_domain_verified'] ?? false),
        ])->save();
    }

    protected function assertDomainIsAvailable(?string $domain, int $companyId): void
    {
        if (! $domain) {
            return;
        }

        $this->assertBladeDomainIsNotNextjs($domain);

        $exists = Company::withoutGlobalScopes()
            ->where('domain', $domain)
            ->whereKeyNot($companyId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'data.company_domain' => 'This storefront domain is already assigned to another company.',
            ]);
        }
    }
}
