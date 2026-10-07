<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\Users\UserResource;
use App\Services\CompanyContext;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    use HasStickyHeaderFormActions;

    protected static string $resource = UserResource::class;

    protected array $companyIds = [];

    protected ?int $defaultCompanyId = null;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            $this->getStickySaveFormAction(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Only a Super Admin changes a role, including their own; for anyone
        // else the stored role is kept, whatever the request says.
        if (! auth()->user()?->canChangePermissions()) {
            unset($data['role']);
        }

        [$this->companyIds, $this->defaultCompanyId] = auth()->user()->resolveCompanyAccessChange(
            array_values(array_filter(array_map('intval', $data['company_ids'] ?? []))),
            filled($data['default_company_id'] ?? null) ? (int) $data['default_company_id'] : null,
            $this->record,
        );

        unset($data['company_ids'], $data['default_company_id']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record = parent::handleRecordUpdate($record, $data);

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

        if ((int) auth()->id() !== (int) $record->getKey()) {
            return;
        }

        $defaultCompany = $record->defaultCompany();

        if ($defaultCompany) {
            session()->put('current_company_id', $defaultCompany->getKey());
            session()->put('current_company_selection_explicit', false);
            $record->rememberCompanySelection($defaultCompany->getKey());
            app(CompanyContext::class)->set($defaultCompany);

            return;
        }

        if ($record->isSuperAdmin()) {
            session()->put('current_company_id', 'all');
            session()->put('current_company_selection_explicit', false);
            $record->rememberCompanySelection(null);
            app(CompanyContext::class)->all();
        }
    }
}
