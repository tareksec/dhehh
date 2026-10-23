<?php

namespace App\Filament\Resources\StorefrontSettings\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\StorefrontSettings\Pages\Concerns\HandlesNextjsSiteDomain;
use App\Filament\Resources\StorefrontSettings\Pages\Concerns\HasStorefrontSettingsNavigation;
use App\Filament\Resources\StorefrontSettings\StorefrontSettingResource;
use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Services\StorefrontNotificationService;
use App\Support\StorefrontSiteDesign;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditStorefrontSetting extends EditRecord
{
    use HandlesNextjsSiteDomain;
    use HasStickyHeaderFormActions;
    use HasStorefrontSettingsNavigation;

    protected static string $resource = StorefrontSettingResource::class;

    protected string $view = 'filament.resources.storefront-settings.settings-page';

    protected ?bool $hasDatabaseTransactions = true;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->normalizeActiveSection();
    }

    /**
     * The Next.js row opens with the layout and options the website really
     * uses: the default home sections while nothing is saved, and every
     * design switch at its effective value (not blank).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->record->isNextjs()) {
            $data['home_sections'] = StorefrontSiteDesign::homeSections($this->record->home_sections, forForm: true);
            $data['site_design'] = $this->record->siteDesign();
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $siteType = $this->record->isNextjs() ? StorefrontSetting::SITE_NEXTJS : StorefrontSetting::SITE_BLADE;

        if ($siteType === StorefrontSetting::SITE_BLADE) {
            $this->syncCompanyDomain($this->domainSyncData($data));
        }

        unset($data['company_domain'], $data['company_domain_verified'], $data['site_type'], $data['copy_from_blade']);

        // The SMS gateway and the SMTP email setup are edited on Integrations, so this form no longer carries them; keep what is stored.
        $data['notification_credentials'] = array_merge(
            Arr::only((array) $this->record->notification_credentials, [
                ...StorefrontNotificationService::SMS_CREDENTIAL_KEYS,
                ...StorefrontNotificationService::MAIL_CREDENTIAL_KEYS,
            ]),
            (array) ($data['notification_credentials'] ?? []),
        );

        return $this->applySiteFields($data, $this->record, (int) ($data['company_id'] ?? $this->record->company_id), $siteType);
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
        $companyId = $data['company_id'] ?? $this->record->company_id;

        $company = Company::withoutGlobalScopes()->find($companyId);

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

    protected function getHeaderActions(): array
    {
        return [
            $this->getStickySaveFormAction(),
        ];
    }
}
