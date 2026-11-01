<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use App\Services\OrderStatusWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChangeOrderWorkflowAction
{
    public static function make(): Action
    {
        return Action::make('changeWorkflowStatus')
            ->label('Change status')
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->visible(fn (Order $record): bool => filled($record->allowedWorkflowStages())
                && (Auth::user()?->hasPermission('sales.update') ?? false))
            ->modalHeading(fn (Order $record): string => "Change status for {$record->order_number}")
            ->modalDescription(fn (Order $record): string => __('Shipping and delivery stages also update the storefront delivery status. Stock and customer dues follow the order status.')
                .($record->stepBackWorkflowStages() !== []
                    ? ' '.__('Going back to an earlier stage does not send the customer a message.')
                    : ''))
            ->schema(fn (Order $record): array => [
                Select::make('stage')
                    ->label('Move to')
                    ->options($record->groupedWorkflowStageOptions())
                    ->required()
                    ->live()
                    ->native(false),
                Textarea::make('reason')
                    ->label('Reason / note')
                    ->rows(3)
                    ->maxLength(1000)
                    ->required(fn (Get $get): bool => in_array($get('stage'), Order::REASON_REQUIRED_STAGES, true))
                    ->helperText('Required for cancellation, return, and refund so the history remains actionable.'),
            ])
            ->requiresConfirmation()
            ->action(function (Order $record, array $data, Action $action): void {
                try {
                    app(OrderStatusWorkflowService::class)->transition(
                        $record,
                        $data['stage'],
                        $data['reason'] ?? null,
                    );
                } catch (ValidationException $exception) {
                    // Let Filament turn this into an inline field error on the
                    // modal, same as any other form validation failure.
                    throw $exception;
                } catch (Throwable $exception) {
                    // Anything else (an unexpected DB error, a courier-sync
                    // failure mid-transition, etc.) must never look like the
                    // click "did nothing" -- report it and surface it plainly
                    // instead of letting a generic, easy-to-miss error bubble
                    // up on its own.
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title('Could not update the order status')
                        ->body('Something unexpected went wrong and the order was left unchanged. Please try again — if it keeps happening, share this with support: '.$exception->getMessage())
                        ->send();

                    $action->halt();
                }

                $record->refresh();
            })
            ->successNotification(
                fn (Order $record): Notification => Notification::make()
                    ->success()
                    ->title('Order status updated')
                    ->body("{$record->order_number} is now ".(Order::WORKFLOW_STAGES[$record->workflowStage()] ?? ucfirst($record->status)).'.')
            );
    }
}
