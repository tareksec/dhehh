<?php

namespace App\Filament\Resources\EmailLogs;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Models\EmailLog;
use App\Services\EmailLogService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * What happened to every email the system sent (owner request 2026-10-23): sent,
 * failed with the mail server's own answer, or not sent with the reason.
 * Read-only. The text of a message is never kept, only who it went to.
 */
class EmailLogResource extends Resource
{
    protected static ?string $model = EmailLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $cluster = Settings::class;

    /** Reached from Settings → Logs (App\Filament\Pages\Logs), not from the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('Email Log');
    }

    public static function getModelLabel(): string
    {
        return __('Email');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Email Log');
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
            EmailLog::KIND_LOGIN_CODE => __('Customer login code'),
            EmailLog::KIND_RESELLER_PASSWORD_RESET => __('Reseller password reset link'),
            EmailLog::KIND_IMS_CODE => __('Investor code'),
            EmailLog::KIND_IMS_INVITATION => __('Investor invitation'),
            EmailLog::KIND_IMS_MESSAGE => __('Investor message'),
            EmailLog::KIND_OWNER_DIGEST => __('Owner daily digest'),
            EmailLog::KIND_TEST => __('Test email'),
            EmailLog::KIND_OTHER => __('Other email'),
        ];
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            EmailLog::STATUS_SENT => __('Sent'),
            EmailLog::STATUS_FAILED => __('Failed'),
            EmailLog::STATUS_SKIPPED => __('Not sent'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                // On a phone one column tells the whole story (when, who, result, why) so nothing scrolls sideways;
                // from a tablet up the same facts get a column each (same layout as the SMS log).
                TextColumn::make('summary')
                    ->label(__('Email'))
                    ->state(fn (EmailLog $record): string => self::summaryHtml($record))
                    ->html()
                    ->wrap()
                    ->hiddenFrom('md'),
                TextColumn::make('created_at')->label(__('Time'))->dateTime('d M, H:i')->sortable()->visibleFrom('md'),
                TextColumn::make('recipient')->label(__('To'))->searchable()->visibleFrom('md'),
                TextColumn::make('status')
                    ->label(__('Result'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->visibleFrom('md'),
                TextColumn::make('reason')
                    ->label(__('Why'))
                    ->state(fn (EmailLog $record): ?string => self::why($record))
                    ->wrap()
                    ->extraAttributes(['style' => 'min-width: 16rem'])
                    ->placeholder('-')
                    ->visibleFrom('md'),
                TextColumn::make('kind')
                    ->label(__('Message'))
                    ->formatStateUsing(fn (string $state): string => self::kindLabels()[$state] ?? $state)
                    ->visibleFrom('md'),
                TextColumn::make('via')
                    ->label(__('Sent through'))
                    ->formatStateUsing(fn (?string $state): string => self::viaLabel($state))
                    ->placeholder('-')
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Result'))->options(self::statusLabels()),
                SelectFilter::make('kind')->label(__('Message'))->options(self::kindLabels()),
            ]);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            EmailLog::STATUS_SENT => 'success',
            EmailLog::STATUS_FAILED => 'danger',
            default => 'warning',
        };
    }

    /** The phone layout of a row: when, who and what, the result, and why (every value is escaped). */
    public static function summaryHtml(EmailLog $record): string
    {
        $color = match (self::statusColor($record->status)) {
            'success' => 'text-success-600 dark:text-success-400',
            'danger' => 'text-danger-600 dark:text-danger-400',
            default => 'text-warning-600 dark:text-warning-400',
        };

        return '<div class="space-y-1" style="min-width: 14rem">'
            .'<div class="text-xs text-gray-500 dark:text-gray-400">'.e(collect([$record->created_at?->format('d M, H:i'), $record->recipient, self::kindLabels()[$record->kind] ?? $record->kind])->filter()->implode(' · ')).'</div>'
            .'<div class="font-semibold '.$color.'">'.e(self::statusLabels()[$record->status] ?? $record->status).'</div>'
            .(filled($why = self::why($record)) ? '<div class="whitespace-normal text-sm">'.e($why).'</div>' : '')
            .'</div>';
    }

    /**
     * "Sent" is the mail server's own acceptance of the message. It is not proof that the inbox received it: a
     * bounce or a spam-folder delivery happens later and is not tracked. Shown above the table, here and on the Logs tab.
     */
    public static function logNotice(): ?string
    {
        return __('Sent means the mail server accepted the message. It does not prove the inbox received it: bounces and spam-folder delivery are not tracked yet.');
    }

    /** The reason in words, with the mail server's own answer when there is one. */
    public static function why(EmailLog $record): ?string
    {
        if ($record->reason === null) {
            return $record->detail;
        }

        $label = EmailLogService::reasonLabels()[$record->reason] ?? $record->reason;

        return filled($record->detail) ? $label.' ('.$record->detail.')' : $label;
    }

    /** "smtp:HOST" is this site's own SMTP account; "server:MAILER" is the server's default mailer. */
    public static function viaLabel(?string $via): string
    {
        if ($via === null || $via === '') {
            return '-';
        }

        [$type, $value] = array_pad(explode(':', $via, 2), 2, '');

        return $type === 'smtp'
            ? __('SMTP :host', ['host' => $value])
            : __('Server mailer (:mailer)', ['mailer' => $value]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEmailLogs::route('/')];
    }
}
