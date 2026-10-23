<?php

namespace App\Filament\Resources\CourierWebhookLogs;

use App\Filament\Clusters\Courier;
use App\Filament\Resources\CourierWebhookLogs\Pages\ListCourierWebhookLogs;
use App\Jobs\ProcessCourierWebhook;
use App\Models\CourierWebhookLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

class CourierWebhookLogResource extends Resource
{
    protected static ?string $model = CourierWebhookLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $cluster = Courier::class;

    protected static ?string $navigationLabel = 'Webhook Logs';

    protected static ?int $navigationSort = 4;

    protected static bool $shouldRegisterNavigation = false;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('provider.name')->label('Provider'),
            TextColumn::make('delivery_id')->label('Event ID')->searchable()->limit(28),
            TextColumn::make('event')->searchable(),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'processed' => 'success', 'failed', 'rejected' => 'danger', 'ignored' => 'gray', default => 'warning'
            }),
            TextColumn::make('attempts')->numeric(),
            TextColumn::make('error')->limit(60)->placeholder('-'),
            TextColumn::make('processed_at')->dateTime()->placeholder('-'),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'processed' => 'Processed', 'ignored' => __('Ignored'), 'failed' => 'Failed', 'rejected' => __('Rejected')])])
            ->recordActions([self::processNowAction()]);
    }

    /**
     * Runs a waiting or failed callback right away. Callbacks are processed
     * by the queue worker; without one running they wait as Pending forever,
     * and the courier's status never reaches the order.
     */
    public static function processNowAction(): Action
    {
        return Action::make('processNow')
            ->label(__('Process now'))
            ->icon(Heroicon::OutlinedPlayCircle)
            ->visible(fn (CourierWebhookLog $record): bool => in_array($record->status, ['pending', 'failed'], true))
            ->action(function (CourierWebhookLog $record): void {
                try {
                    app()->call([new ProcessCourierWebhook($record->getKey()), 'handle']);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title(__('The callback could not be processed'))
                        ->body(str($exception->getMessage())->limit(300)->toString())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $record->refresh();

                Notification::make()
                    ->title(__('Callback processed'))
                    ->body($record->status === 'ignored' ? $record->error : null)
                    ->success()
                    ->send();
            });
    }

    /** Callbacks waiting longer than this mean no queue worker is picking them up. */
    public const STUCK_AFTER_MINUTES = 5;

    public static function stuckCount(): int
    {
        return CourierWebhookLog::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->count();
    }

    /**
     * A callback is saved first and processed by the queue worker. When callbacks sit in Pending, the worker is
     * not running and no courier status reaches the orders; say so where staff look.
     */
    public static function logNotice(): ?string
    {
        $stuck = self::stuckCount();

        return $stuck > 0
            ? __(':count courier callback(s) have waited more than :minutes minutes. The queue worker does not seem to be running (see docs/deployment.md) — start it, or press Process now on each one.', [
                'count' => $stuck,
                'minutes' => self::STUCK_AFTER_MINUTES,
            ])
            : null;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListCourierWebhookLogs::route('/')];
    }
}
