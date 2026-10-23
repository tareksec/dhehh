<?php

namespace App\Filament\Resources\SmsLogs;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\SmsLogs\Pages\ListSmsLogs;
use App\Models\SmsLog;
use App\Services\CompanyContext;
use App\Services\SmsLogService;
use App\Services\StorefrontNotificationService;
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

    /** Reached from Settings → Logs (App\Filament\Pages\Logs), not from the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

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
            SmsLog::KIND_OTHER => __('Other messages (login codes, reminders, broadcasts)'),
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
                // On a phone one column tells the whole story (when, who, result, why) so nothing scrolls sideways;
                // from a tablet up the same facts get a column each.
                TextColumn::make('summary')
                    ->label(__('SMS'))
                    ->state(fn (SmsLog $record): string => self::summaryHtml($record))
                    ->html()
                    ->wrap()
                    ->hiddenFrom('md'),
                TextColumn::make('created_at')->label(__('Time'))->dateTime('d M, H:i')->sortable()->visibleFrom('md'),
                TextColumn::make('phone')->label(__('Phone'))->placeholder('-')->searchable()->visibleFrom('md'),
                TextColumn::make('status')
                    ->label(__('Result'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->visibleFrom('md'),
                TextColumn::make('reason')
                    ->label(__('Why'))
                    ->state(fn (SmsLog $record): ?string => self::why($record))
                    ->wrap()
                    ->extraAttributes(['style' => 'min-width: 16rem'])
                    ->placeholder('-')
                    ->visibleFrom('md'),
                TextColumn::make('order.order_number')->label(__('Order'))->placeholder('-')->searchable()->visibleFrom('md'),
                TextColumn::make('kind')
                    ->label(__('Message'))
                    ->formatStateUsing(fn (string $state): string => self::kindLabels()[$state] ?? $state)
                    ->visibleFrom('md'),
                TextColumn::make('message')->label(__('Text'))->limit(60)->tooltip(fn (SmsLog $record): ?string => $record->message)->placeholder('-')->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Result'))->options(self::statusLabels()),
                SelectFilter::make('kind')->label(__('Message'))->options(self::kindLabels()),
            ]);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            SmsLog::STATUS_SENT => 'success',
            SmsLog::STATUS_FAILED => 'danger',
            SmsLog::STATUS_SKIPPED => 'warning',
            default => 'gray',
        };
    }

    /** The phone layout of a row: when and who, the result, and why (every value is escaped). */
    public static function summaryHtml(SmsLog $record): string
    {
        $color = match (self::statusColor($record->status)) {
            'success' => 'text-success-600 dark:text-success-400',
            'danger' => 'text-danger-600 dark:text-danger-400',
            'warning' => 'text-warning-600 dark:text-warning-400',
            default => 'text-gray-500 dark:text-gray-400',
        };

        return '<div class="space-y-1" style="min-width: 14rem">'
            .'<div class="text-xs text-gray-500 dark:text-gray-400">'.e(collect([$record->created_at?->format('d M, H:i'), $record->phone, self::kindLabels()[$record->kind] ?? $record->kind])->filter()->implode(' · ')).'</div>'
            .'<div class="font-semibold '.$color.'">'.e(self::statusLabels()[$record->status] ?? $record->status).'</div>'
            .(filled($why = self::why($record)) ? '<div class="whitespace-normal text-sm">'.e($why).'</div>' : '')
            .'</div>';
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

        return collect([
            $gateway === '' ? $label : $label.' ('.$gateway.')',
            StorefrontNotificationService::smsGatewayHint($record->gateway_code),
        ])->filter()->implode(' ');
    }

    /**
     * A message is logged as waiting the moment the order is saved and is sent by the queue worker. When some
     * have waited for minutes the worker is not running; the Logs page and the list page both say so.
     */
    public static function logNotice(): ?string
    {
        $context = app(CompanyContext::class);
        $stuck = app(SmsLogService::class)->stuckCount($context->isAllCompanies() ? null : $context->id());

        return $stuck > 0
            ? __(':count SMS have waited more than :minutes minutes. The queue worker does not seem to be running (see docs/deployment.md), so nothing is sent. Start it and they go out.', [
                'count' => $stuck,
                'minutes' => SmsLogService::STUCK_AFTER_MINUTES,
            ])
            : null;
    }

    public static function getPages(): array
    {
        return ['index' => ListSmsLogs::route('/')];
    }
}
