<?php

namespace App\Filament\Resources\SmsLogs;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\SmsLogs\Pages\ListSmsLogs;
use App\Models\SmsLog;
use App\Services\SmsLogService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * What happened to every order SMS (owner request 2026-10-23): sent, refused by
 * the gateway with the gateway's own reason, skipped with the reason, or still
 * waiting for the queue worker. Read-only.
 */
class SmsLogResource extends Resource
{
    protected static ?string $model = SmsLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('SMS Log');
    }

    public static function getModelLabel(): string
    {
        return __('SMS');
    }

    public static function getPluralModelLabel(): string
    {
        return __('SMS Log');
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
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

    /** @return array<string, string> */
    public static function kindLabels(): array
    {
        return [
            SmsLog::KIND_ORDER_PLACED => __('Order placed'),
            SmsLog::KIND_ORDER_STATUS => __('Order status change'),
            SmsLog::KIND_TEST => __('Test SMS'),
        ];
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            SmsLog::STATUS_QUEUED => __('Waiting in the queue'),
            SmsLog::STATUS_SENT => __('Sent'),
            SmsLog::STATUS_FAILED => __('Not sent: gateway'),
            SmsLog::STATUS_SKIPPED => __('Not sent: skipped'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Time'))->dateTime()->sortable(),
                TextColumn::make('order.order_number')->label(__('Order'))->placeholder('-')->searchable(),
                TextColumn::make('kind')
                    ->label(__('Message'))
                    ->formatStateUsing(fn (string $state): string => self::kindLabels()[$state] ?? $state),
                TextColumn::make('phone')->label(__('Phone'))->placeholder('-')->searchable(),
                TextColumn::make('status')
                    ->label(__('Result'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        SmsLog::STATUS_SENT => 'success',
                        SmsLog::STATUS_FAILED => 'danger',
                        SmsLog::STATUS_SKIPPED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('reason')
                    ->label(__('Why'))
                    ->state(fn (SmsLog $record): ?string => self::why($record))
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('message')->label(__('Text'))->limit(60)->tooltip(fn (SmsLog $record): ?string => $record->message)->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Result'))->options(self::statusLabels()),
                SelectFilter::make('kind')->label(__('Message'))->options(self::kindLabels()),
            ]);
    }

    /** The reason in words, with the gateway's own answer when it gave one. */
    public static function why(SmsLog $record): ?string
    {
        if ($record->reason === null && $record->status === SmsLog::STATUS_QUEUED) {
            return __('Waiting for the queue worker to send it.');
        }

        if ($record->reason === null) {
            return $record->detail;
        }

        $label = SmsLogService::reasonLabels()[$record->reason] ?? $record->reason;
        $gateway = collect([$record->gateway_code, $record->detail])->filter()->implode(' - ');

        return $gateway === '' ? $label : $label.' ('.$gateway.')';
    }

    public static function getPages(): array
    {
        return ['index' => ListSmsLogs::route('/')];
    }
}
