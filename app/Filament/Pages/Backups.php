<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Models\BackupRun;
use App\Services\AppBackupService;
use App\Services\BackupSettingsService;
use App\Services\DatabaseBackupService;
use App\Services\GoogleDriveBackupService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Number;
use Throwable;

class Backups extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Backups';

    protected string $view = 'filament.pages.backups';

    public bool $googleDriveEnabled = false;

    public bool $googleDriveAutoUpload = false;

    public ?string $googleDriveFolderId = null;

    public ?string $googleDriveServiceAccountPath = null;

    public ?string $googleDriveServiceAccountJson = null;

    public function mount(BackupSettingsService $settings): void
    {
        $this->googleDriveEnabled = $settings->googleDriveEnabled();
        $this->googleDriveAutoUpload = $settings->googleDriveAutoUpload();
        $this->googleDriveFolderId = $settings->googleDriveFolderId();
        $this->googleDriveServiceAccountPath = $settings->serviceAccountPath();
        $this->googleDriveServiceAccountJson = null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Google Drive Backup')
                    ->description('Configure Drive credentials once, then upload full app backups manually or automatically.')
                    ->icon(Heroicon::OutlinedCloudArrowUp)
                    ->headerActions([
                        Action::make('configureGoogleDrive')
                            ->label('Configure')
                            ->icon(Heroicon::OutlinedCog6Tooth)
                            ->modalHeading('Google Drive Backup Settings')
                            ->modalDescription('Add service account credentials to upload full app backups to Google Drive.')
                            ->modalSubmitActionLabel('Save Drive Settings')
                            ->modalWidth(Width::FiveExtraLarge)
                            ->fillForm(fn (): array => [
                                'googleDriveEnabled' => $this->googleDriveEnabled,
                                'googleDriveAutoUpload' => $this->googleDriveAutoUpload,
                                'googleDriveFolderId' => $this->googleDriveFolderId,
                                'googleDriveServiceAccountPath' => $this->googleDriveServiceAccountPath,
                                'googleDriveServiceAccountJson' => null,
                            ])
                            ->schema([
                                TextEntry::make('googleDriveSetupGuide')
                                    ->label('Where to get Service Account JSON and Folder ID')
                                    ->state([
                                        'Enable Google Drive API in a Google Cloud project.',
                                        'Create a service account and download a JSON key from its Keys page.',
                                        'Share the target Google Drive folder with the service account email.',
                                        'Copy the text after /folders/ from the Drive folder URL as the Folder ID.',
                                        'Paste the Folder ID and either the JSON content or its absolute server path below.',
                                    ])
                                    ->bulleted()
                                    ->columnSpanFull(),
                                Toggle::make('googleDriveEnabled')
                                    ->label('Enable Google Drive backup'),
                                Toggle::make('googleDriveAutoUpload')
                                    ->label('Auto upload daily'),
                                TextInput::make('googleDriveFolderId')
                                    ->label('Google Drive Folder ID')
                                    ->placeholder('1AbCdEfGhIjKlMnOpQrStUvWxYz...')
                                    ->helperText('Use the value after /folders/ in the Google Drive folder URL.')
                                    ->autocomplete(false),
                                TextInput::make('googleDriveServiceAccountPath')
                                    ->label('Service Account JSON Path')
                                    ->placeholder('/app/storage/keys/google-drive.json')
                                    ->helperText('Optional absolute path when the JSON file is stored on the server.')
                                    ->autocomplete(false),
                                Textarea::make('googleDriveServiceAccountJson')
                                    ->label('Service Account JSON')
                                    ->rows(10)
                                    ->json()
                                    ->autocomplete(false)
                                    ->placeholder('{"type":"service_account", ...}')
                                    ->helperText(fn (): string => $this->hasStoredServiceAccountJson()
                                        ? 'A service account JSON is already stored encrypted. Leave this blank to keep it.'
                                        : 'Paste the complete service account JSON. It will be encrypted before storage.')
                                    ->columnSpanFull(),
                            ])
                            ->action(fn (array $data, BackupSettingsService $settings) => $this->saveGoogleDriveSettings($settings, $data)),
                    ])
                    ->schema([
                        TextEntry::make('googleDriveStatus')
                            ->label('Status')
                            ->state(fn (): string => $this->googleDriveConfigured() ? 'Configured' : 'Not configured')
                            ->badge()
                            ->color(fn (): string => $this->googleDriveConfigured() ? 'success' : 'warning'),
                    ]),

                Section::make('Full App Backups')
                    ->description('Creates a ZIP archive with app files, public uploads, environment files, and a database backup.')
                    ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                    ->headerActions([
                        Action::make('createFullAppBackup')
                            ->label('Create App Backup')
                            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                            ->action(fn (AppBackupService $backups) => $this->createAppBackup($backups)),
                        Action::make('uploadFullAppBackup')
                            ->label('Upload to Drive')
                            ->icon(Heroicon::OutlinedCloudArrowUp)
                            ->color('gray')
                            ->disabled(fn (): bool => ! $this->googleDriveConfigured())
                            ->tooltip(fn (): ?string => $this->googleDriveConfigured()
                                ? null
                                : 'Configure Google Drive before uploading a backup.')
                            ->action(fn (AppBackupService $backups) => $this->createAndUploadAppBackup($backups)),
                    ])
                    ->schema([
                        $this->backupList(
                            'appBackupFiles',
                            fn (): array => $this->appBackupFiles(),
                            'No full app backups created yet.',
                            showKind: true,
                        ),
                    ]),

                Section::make('Database Backups')
                    ->description(__('Database backups are stored privately. Each type (hourly, daily, before deploy, manual) keeps its own latest files, so one type never pushes out another.'))
                    ->icon(Heroicon::OutlinedCircleStack)
                    ->headerActions([
                        Action::make('createDatabaseBackup')
                            ->label('Create Backup')
                            ->icon(Heroicon::OutlinedCircleStack)
                            ->action(fn (DatabaseBackupService $backups) => $this->createBackup($backups)),
                    ])
                    ->schema([
                        $this->backupList(
                            'databaseBackupFiles',
                            fn (): array => $this->backupFiles(),
                            'No database backups created yet.',
                            showKind: true,
                        ),
                    ]),

                Section::make(__('Backup history'))
                    ->description(__('The last 20 backup attempts, newest first. A failed attempt shows the reason.'))
                    ->icon(Heroicon::OutlinedClock)
                    ->schema([
                        RepeatableEntry::make('backupRunRows')
                            ->hiddenLabel()
                            ->state(fn (): array => $this->backupRuns())
                            ->placeholder(__('No backups have been recorded yet.'))
                            ->table([
                                TableColumn::make(__('Status')),
                                TableColumn::make(__('Type')),
                                TableColumn::make('File'),
                                TableColumn::make('Size'),
                                TableColumn::make(__('Started')),
                                TableColumn::make(__('Note')),
                            ])
                            ->schema([
                                TextEntry::make('status_label')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        __('Success') => 'success',
                                        __('Failed') => 'danger',
                                        default => 'warning',
                                    }),
                                TextEntry::make('type_label'),
                                TextEntry::make('filename')->placeholder('—'),
                                TextEntry::make('size_human')->placeholder('—'),
                                TextEntry::make('started_label'),
                                TextEntry::make('note')->placeholder('—'),
                            ]),
                    ]),
            ]);
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canManageBackups() ?? false;
    }

    public function createBackup(DatabaseBackupService $backups): void
    {
        try {
            $backup = $backups->create();

            Notification::make()
                ->title('Backup created')
                ->body("Created {$backup['name']}.")
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Backup failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function createAppBackup(AppBackupService $backups): void
    {
        try {
            $backup = $backups->create(uploadToGoogleDrive: false);

            Notification::make()
                ->title('Full app backup created')
                ->body("Created {$backup['name']}.")
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Full app backup failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function createAndUploadAppBackup(AppBackupService $backups): void
    {
        try {
            $backup = $backups->create(uploadToGoogleDrive: true);

            Notification::make()
                ->title('Full app backup uploaded')
                ->body("Created {$backup['name']} and uploaded it to Google Drive.")
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Google Drive backup failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /** @param array<string, mixed>|null $data */
    public function saveGoogleDriveSettings(BackupSettingsService $settings, ?array $data = null): void
    {
        if ($data !== null) {
            $this->googleDriveEnabled = (bool) ($data['googleDriveEnabled'] ?? false);
            $this->googleDriveAutoUpload = (bool) ($data['googleDriveAutoUpload'] ?? false);
            $this->googleDriveFolderId = filled($data['googleDriveFolderId'] ?? null)
                ? (string) $data['googleDriveFolderId']
                : null;
            $this->googleDriveServiceAccountPath = filled($data['googleDriveServiceAccountPath'] ?? null)
                ? (string) $data['googleDriveServiceAccountPath']
                : null;
            $this->googleDriveServiceAccountJson = filled($data['googleDriveServiceAccountJson'] ?? null)
                ? (string) $data['googleDriveServiceAccountJson']
                : null;
        }

        $json = trim((string) $this->googleDriveServiceAccountJson);

        if ($json !== '' && json_decode($json, true) === null) {
            Notification::make()
                ->title('Invalid service account JSON')
                ->body('Paste the full JSON from the Google Cloud service account key.')
                ->danger()
                ->send();

            return;
        }

        $settings->saveGoogleDrive([
            'enabled' => $this->googleDriveEnabled,
            'auto_upload' => $this->googleDriveAutoUpload,
            'folder_id' => $this->googleDriveFolderId,
            'service_account_path' => $this->googleDriveServiceAccountPath,
            'service_account_json' => $json,
        ]);

        $this->googleDriveServiceAccountJson = null;

        Notification::make()
            ->title('Google Drive settings saved')
            ->success()
            ->send();
    }

    public function backupFiles(): array
    {
        return collect(app(DatabaseBackupService::class)->all())
            ->map(fn (array $file): array => [...$file, 'kind_label' => $this->kindLabel($file['kind'])])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function backupRuns(): array
    {
        if (! DatabaseSchema::hasTable('backup_runs')) {
            return [];
        }

        return BackupRun::query()
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (BackupRun $run): array => [
                'status_label' => match ($run->status) {
                    BackupRun::STATUS_SUCCESS => __('Success'),
                    BackupRun::STATUS_FAILED => __('Failed'),
                    default => __('Running'),
                },
                'type_label' => $this->kindLabel($run->kind).' · '.($run->scope === BackupRun::SCOPE_APP ? __('Full app') : __('Database')),
                'filename' => $run->filename,
                'size_human' => $run->size_bytes === null ? null : Number::fileSize($run->size_bytes, precision: 1),
                'started_label' => $run->started_at->format('d M Y, h:i A'),
                'note' => $run->error ?? ($run->sha256 ? 'SHA-256 '.substr($run->sha256, 0, 12).'…' : null),
            ])
            ->all();
    }

    protected function kindLabel(string $kind): string
    {
        return match ($kind) {
            BackupRun::KIND_HOURLY => __('Hourly'),
            BackupRun::KIND_DAILY => __('Daily'),
            BackupRun::KIND_PREDEPLOY => __('Before deploy'),
            default => __('Manual'),
        };
    }

    public function appBackupFiles(): array
    {
        return collect(app(AppBackupService::class)->all())
            ->map(fn (array $file): array => [...$file, 'kind_label' => $this->kindLabel($file['kind'])])
            ->all();
    }

    public function googleDriveConfigured(): bool
    {
        return app(GoogleDriveBackupService::class)->isConfigured();
    }

    public function hasStoredServiceAccountJson(): bool
    {
        return app(BackupSettingsService::class)->hasServiceAccountJson();
    }

    /**
     * @param  callable(): array<int, array<string, mixed>>  $state
     */
    protected function backupList(string $name, callable $state, string $emptyMessage, bool $showKind = false): RepeatableEntry
    {
        return RepeatableEntry::make($name)
            ->hiddenLabel()
            ->state($state)
            ->placeholder($emptyMessage)
            ->table([
                TableColumn::make('File'),
                ...($showKind ? [TableColumn::make(__('Type'))] : []),
                TableColumn::make('Size'),
                TableColumn::make('Created'),
            ])
            ->schema([
                TextEntry::make('name')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (string $state): string => route('backups.download', $state)),
                ...($showKind ? [TextEntry::make('kind_label')] : []),
                TextEntry::make('size_human'),
                TextEntry::make('modified_label'),
            ]);
    }
}
