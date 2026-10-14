<?php

namespace App\Filament\Resources\InvestorDrafts\Pages;

use App\Filament\Resources\InvestorDrafts\InvestorDraftResource;
use App\Filament\Resources\Investors\InvestorResource;
use App\Models\InvestorDraft;
use App\Services\Investment\InvestorImportService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class EditInvestorDraft extends EditRecord
{
    protected static string $resource = InvestorDraftResource::class;

    public function getTitle(): string
    {
        return __('Review investor draft');
    }

    protected function getHeaderActions(): array
    {
        return [
            InvestorDraftResource::rejectApplicationAction()->after(fn () => $this->redirect(InvestorDraftResource::getUrl())),
            DeleteAction::make()->label(__('Discard'))->visible(fn (InvestorDraft $record): bool => $record->source !== InvestorDraft::SOURCE_PORTAL),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()->label(__('Save draft')),
            Action::make('saveAndConfirm')
                ->label(__('Save & confirm'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->action(fn () => $this->saveAndConfirm()),
            $this->getCancelFormAction(),
        ];
    }

    /** The reviewer has seen the import notes once the draft is saved from this form. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['import_notes'] = null;

        return $data;
    }

    public function saveAndConfirm(): void
    {
        $imports = app(InvestorImportService::class);
        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

        /** @var InvestorDraft $draft */
        $draft = $this->getRecord()->refresh();
        $message = InvestorDraftResource::confirmedMessage($draft);

        try {
            $investor = $imports->confirm($draft);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])->all()
            );
        }

        Notification::make()->title($message)->success()->send();

        $this->redirect(InvestorResource::getUrl('view', ['record' => $investor]));
    }
}
