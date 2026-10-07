<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\Company;
use App\Models\User;
use App\Services\BusinessNotificationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    use HasStickyHeaderFormActions;

    protected static string $resource = UserResource::class;

    protected array $companyIds = [];

    protected ?int $defaultCompanyId = null;

    protected function getHeaderActions(): array
    {
        return [
            $this->getStickySaveFormAction(),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Only a Super Admin picks a role. Anyone else's new user starts on
        // the default role, whatever the request says.
        if (! auth()->user()?->canChangePermissions()) {
            $data['role'] = User::defaultRole();
        }

        [$this->companyIds, $this->defaultCompanyId] = auth()->user()->resolveCompanyAccessChange(
            array_values(array_filter(array_map('intval', $data['company_ids'] ?? []))),
            filled($data['default_company_id'] ?? null) ? (int) $data['default_company_id'] : null,
            null,
        );

        unset($data['company_ids'], $data['default_company_id']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation($data);

        $this->syncCompanyAccess($record);

        return $record;
    }

    protected function syncCompanyAccess(Model $record): void
    {
        if ($this->defaultCompanyId && ! in_array($this->defaultCompanyId, $this->companyIds, true)) {
            $this->companyIds[] = $this->defaultCompanyId;
        }

        $sync = collect($this->companyIds)
            ->unique()
            ->mapWithKeys(fn (int $companyId): array => [
                $companyId => [
                    'role' => $record->role,
                    'is_default' => $companyId === $this->defaultCompanyId,
                ],
            ])
            ->all();

        $record->companies()->sync($sync);

        $this->notifyCompaniesOfNewStaff($record, array_keys($sync));
    }

    /**
     * @param  array<int, int>  $companyIds
     */
    protected function notifyCompaniesOfNewStaff(Model $record, array $companyIds): void
    {
        if ($companyIds === []) {
            return;
        }

        $notifications = app(BusinessNotificationService::class);

        foreach (Company::query()->whereKey($companyIds)->get() as $company) {
            $notifications->notifyCompany(
                $company,
                'user.created',
                'notifications.staff',
                'New staff added',
                "{$record->name} was added as {$record->effectiveRole()}.",
                actionUrl: UserResource::getUrl('view', ['record' => $record]),
                actionLabel: 'View user',
            );
        }
    }
}
