<?php

namespace App\Filament\Resources\PayoutBatches\Pages;

use App\Filament\Resources\PayoutBatches\PayoutBatchResource;
use App\Models\PayoutBatch;
use App\Services\Investment\PayoutBatchService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @property PayoutBatch $record */
class ViewPayoutBatch extends ViewRecord
{
    protected static string $resource = PayoutBatchResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (string $permission): bool => auth()->user()?->hasPermission($permission) ?? false;

        return [
            Action::make('approve')
                ->label(__('Approve'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (): bool => $this->record->status === PayoutBatch::STATUS_DRAFT && $can('investments.payout_batch_approve'))
                ->requiresConfirmation()
                ->modalDescription(__('Check every payee, account and amount. After approval the batch cannot be changed.'))
                ->action(fn () => $this->run(fn (PayoutBatchService $service) => $service->approve($this->record, auth()->user()), __('Batch approved'))),
            Action::make('download')
                ->label(fn (): string => $this->record->export_path ? __('Download payment list') : __('Make payment list'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => in_array($this->record->status, [PayoutBatch::STATUS_APPROVED, PayoutBatch::STATUS_EXPORTED, PayoutBatch::STATUS_COMPLETED], true) && $can('investments.payout_batch_create'))
                ->action(function (): ?StreamedResponse {
                    try {
                        $file = app(PayoutBatchService::class)->export($this->record, auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title(__('Could not download the payment list'))->body(collect($exception->errors())->flatten()->first())->send();

                        return null;
                    }

                    $this->record->refresh();

                    return response()->streamDownload(fn () => print ($file['contents']), $file['name'], ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
            Action::make('complete')
                ->label(__('Record as paid'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, [PayoutBatch::STATUS_APPROVED, PayoutBatch::STATUS_EXPORTED], true) && $can('investments.payout_batch_create'))
                ->modalDescription(__('Mark failed payments on the list below first. Every other payout is marked paid with this reference, and one payout voucher is booked for the batch.'))
                ->schema([
                    TextInput::make('bank_reference')->label(__('Bank reference'))->required()->maxLength(255),
                    DatePicker::make('paid_on')->label(__('Paid on'))->default(now()->toDateString())->required(),
                ])
                ->action(fn (array $data) => $this->run(fn (PayoutBatchService $service) => $service->complete($this->record, auth()->user(), (string) $data['bank_reference'], $data['paid_on'] ?? null), __('Batch recorded as paid'))),
            Action::make('cancel')
                ->label(__('Cancel batch'))
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => $this->record->isLive() && ($can('investments.payout_batch_create') || $can('investments.payout_batch_approve')))
                ->modalDescription(__('The payouts go back to pending and can join a new batch. Use this only if nothing of the batch was sent.'))
                ->schema([Textarea::make('cancel_reason')->label(__('Reason'))->required()->maxLength(2000)])
                ->action(fn (array $data) => $this->run(fn (PayoutBatchService $service) => $service->cancel($this->record, auth()->user(), (string) $data['cancel_reason']), __('Batch cancelled'))),
        ];
    }

    protected function run(callable $step, string $success): void
    {
        try {
            $step(app(PayoutBatchService::class));
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title(__('Could not complete the step'))->body(collect($exception->errors())->flatten()->first())->send();

            return;
        }

        $this->record->refresh();
        Notification::make()->success()->title($success)->send();
        $this->dispatch('$refresh');
    }
}
