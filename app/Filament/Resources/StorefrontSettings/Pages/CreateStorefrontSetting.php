<?php

namespace App\Filament\Resources\StorefrontSettings\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\StorefrontSettings\Pages\Concerns\HandlesNextjsSiteDomain;
use App\Filament\Resources\StorefrontSettings\Pages\Concerns\HasStorefrontSettingsNavigation;
use App\Filament\Resources\StorefrontSettings\StorefrontSettingResource;
use App\Models\Company;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;

class CreateStorefrontSetting extends CreateRecord
{
    use HandlesNextjsSiteDomain;
    use HasStickyHeaderFormActions;
    use HasStorefrontSettingsNavigation;

    protected static string $resource = StorefrontSettingResource::class;

    protected string $view = 'filament.resources.storefront-settings.settings-page';

    protected ?bool $hasDatabaseTransactions = true;

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
        $this->syncCompanyDomain($this->domainSyncData($data));

        unset($data['company_domain'], $data['company_domain_verified']);

        return $this->applyNextjsSiteDomain($data, null, isset($data['company_id']) ? (int) $data['company_id'] : null);
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
