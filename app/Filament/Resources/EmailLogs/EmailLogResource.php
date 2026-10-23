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
                TextColumn::make('created_at')->label(__('Time'))->dateTime()->sortable(),
                TextColumn::make('kind')
                    ->label(__('Message'))
                    ->formatStateUsing(fn (string $state): string => self::kindLabels()[$state] ?? $state),
                TextColumn::make('recipient')->label(__('To'))->searchable(),
                TextColumn::make('status')
                    ->label(__('Result'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        EmailLog::STATUS_SENT => 'success',
                        EmailLog::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('reason')
                    ->label(__('Why'))
                    ->state(fn (EmailLog $record): ?string => self::why($record))
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('via')
                    ->label(__('Sent through'))
                    ->formatStateUsing(fn (?string $state): string => self::viaLabel($state))
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Result'))->options(self::statusLabels()),
                SelectFilter::make('kind')->label(__('Message'))->options(self::kindLabels()),
            ]);
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
