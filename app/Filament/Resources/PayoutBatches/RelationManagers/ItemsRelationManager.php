<?php

namespace App\Filament\Resources\PayoutBatches\RelationManagers;

use App\Filament\Actions\PayoutProofAction;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchItem;
use App\Models\SettlementPayout;
use App\Services\Investment\PayoutBatchService;
use App\Support\CompanyMedia;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Payouts');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $can = fn (): bool => auth()->user()?->hasPermission('investments.payout_batch_create') ?? false;

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('payable.settlement.project'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('payee_name')->label(__('Payee'))
                    ->description(fn (PayoutBatchItem $record): string => ($record->payable_type === (new SettlementPayout)->getMorphClass() ? __('Investor') : __('Channel partner'))
                        .($record->payable?->settlement?->project ? ' · '.$record->payable->settlement->project->project_code : '')),
                TextColumn::make('account')->label(__('Paid to'))->state(fn (PayoutBatchItem $record): string => $record->accountDisplay())
                    ->description(fn (PayoutBatchItem $record): ?string => $record->routing_number ? __('Routing :number', ['number' => $record->routing_number]) : null)
                    ->wrap(),
                TextColumn::make('amount')->label(__('Amount'))->formatStateUsing(fn ($state): string => MoneyFormatter::currency((float) $state)),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => PayoutBatchItem::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        PayoutBatchItem::STATUS_PAID => 'success',
                        PayoutBatchItem::STATUS_FAILED => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (PayoutBatchItem $record): ?string => $record->failure_reason),
                PayoutProofAction::column(fn (PayoutBatchItem $record) => $record->payable),
            ])
            ->recordActions([
                PayoutProofAction::uploadAction(fn () => CompanyMedia::require($this->getOwnerRecord()), fn (PayoutBatchItem $record) => $record->payable),
                Action::make('remove')
                    ->label(__('Take out'))
                    ->icon(Heroicon::OutlinedMinusCircle)
                    ->color('danger')
                    ->visible(fn (): bool => $this->getOwnerRecord()->status === PayoutBatch::STATUS_DRAFT && $can())
                    ->requiresConfirmation()
                    ->action(function (PayoutBatchItem $record): void {
                        $this->step(fn (PayoutBatchService $service) => $service->removeItem($record, auth()->user()), __('Payout taken out of the batch'));
                    }),
                Action::make('failed')
                    ->label(__('Mark failed'))
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->visible(fn (PayoutBatchItem $record): bool => in_array($this->getOwnerRecord()->status, [PayoutBatch::STATUS_APPROVED, PayoutBatch::STATUS_EXPORTED], true)
                        && $record->status === PayoutBatchItem::STATUS_PENDING && $can())
                    ->modalDescription(__('The payout goes back to pending and can join a new batch.'))
                    ->schema([Textarea::make('failure_reason')->label(__('Why did the payment fail?'))->required()->maxLength(2000)])
                    ->action(function (PayoutBatchItem $record, array $data): void {
                        $this->step(fn (PayoutBatchService $service) => $service->markItemFailed($record, auth()->user(), (string) $data['failure_reason']), __('Payout marked failed'));
                    }),
            ]);
    }

    protected function step(callable $step, string $success): void
    {
        try {
            $step(app(PayoutBatchService::class));
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title(__('Could not complete the step'))->body(collect($exception->errors())->flatten()->first())->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }
}
