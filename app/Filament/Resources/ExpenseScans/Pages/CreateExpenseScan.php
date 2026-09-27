<?php

namespace App\Filament\Resources\ExpenseScans\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\ExpenseScans\ExpenseScanResource;
use App\Filament\Resources\ExpenseScans\Schemas\ExpenseScanForm;
use App\Jobs\ProcessExpenseScanJob;
use App\Models\ExpenseScan;
use App\Services\CompanyContext;
use App\Services\ExpenseScan\ExpenseScanConfigService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class CreateExpenseScan extends CreateRecord
{
    use HasStickyHeaderFormActions;

    protected static string $resource = ExpenseScanResource::class;

    public function getTitle(): string
    {
        return __('Scan expenses from a photo or text');
    }

    public function form(Schema $schema): Schema
    {
        return ExpenseScanForm::upload($schema);
    }

    protected function getHeaderActions(): array
    {
        return [$this->getStickySaveFormAction()->label(__('Read with AI'))];
    }

    protected function beforeCreate(): void
    {
        $company = app(CompanyContext::class)->company();

        if (! $company || ! app(ExpenseScanConfigService::class)->isConfigured($company)) {
            Notification::make()
                ->title(__('Smart Scan is not set up yet'))
                ->body(__('A super admin needs to add the AI model and API key on Settings → Integrations → Smart Scan first.'))
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'image_paths' => array_values((array) ($data['image_paths'] ?? [])),
            'source_text' => filled($data['source_text'] ?? null) ? trim((string) $data['source_text']) : null,
            'user_id' => Auth::id(),
            'status' => ExpenseScan::STATUS_PENDING,
        ];
    }

    protected function afterCreate(): void
    {
        ProcessExpenseScanJob::dispatch($this->record->getKey());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Uploaded — the AI is reading your expenses');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
