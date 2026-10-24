<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Models\BackupRun;
use App\Services\BackupEncryptor;
use App\Services\BackupHealth;
use App\Services\BackupSettingsService;
use App\Services\DatabaseBackupService;
use App\Services\R2BackupService;
use App\Services\R2CostEstimator;
use App\Services\R2UsageReport;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Cloudflare R2 backup storage at a glance: is it connected, how much is stored and of which
 * kind, how much of the free allowance is used, what the bill is likely to be now and once
 * the schedule has filled the bucket, and the latest files and uploads. Also where R2 is
 * set up (Configure), tested and refreshed.
 *
 * R2 is read for the numbers (R2UsageReport keeps the answer for a few minutes), so a failed
 * connection shows as a message on the page and never as an error page.
 */
class R2Backups extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'r2-backups';

    protected string $view = 'filament.pages.r2-backups';

    /** @var array<string, mixed>|null built once per request */
    protected ?array $dashboardData = null;

    public static function getNavigationLabel(): string
    {
        return __('R2 Backups');
    }

    public function getTitle(): string
    {
        return __('R2 Backup Storage');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canManageBackups() ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('Refresh'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (R2UsageReport $usage): void {
                    $usage->forget();
                    $this->dashboardData = null;
                }),
            Action::make('testR2Connection')
                ->label(__('Test connection'))
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(fn (R2BackupService $r2) => $this->testConnection($r2)),
            Action::make('backupToR2')
                ->label(__('Back up now'))
                ->icon(Heroicon::OutlinedCloudArrowUp)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('Back up the database now'))
                ->modalDescription(__('Makes a database backup, encrypts it and sends it to every off-site place that is switched on. It can take a minute.'))
                ->disabled(fn (): bool => ! app(R2BackupService::class)->isConfigured())
                ->tooltip(fn (): ?string => app(R2BackupService::class)->isConfigured() ? null : __('Set up and switch on Cloudflare R2 first.'))
                ->action(fn (DatabaseBackupService $backups) => $this->backupNow($backups)),
            $this->configureAction(),
        ];
    }

    protected function configureAction(): Action
    {
        return Action::make('configureR2')
            ->label(__('Configure'))
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->modalHeading(__('Cloudflare R2 backup settings'))
            ->modalDescription(__('Backups are encrypted before they leave this server. Use a bucket of its own and an API token limited to that bucket.'))
            ->modalSubmitActionLabel(__('Save R2 settings'))
            ->modalWidth(Width::FiveExtraLarge)
            ->fillForm(function (BackupSettingsService $settings): array {
                return [
                    'enabled' => $settings->r2Enabled(),
                    'auto_upload' => $settings->r2AutoUpload(),
                    'account' => $settings->r2Account(),
                    'bucket' => $settings->r2Bucket(),
                    'prefix' => $settings->r2Prefix() ?: 'erp-backups',
                    'access_key_id' => $settings->r2AccessKeyId(),
                    'secret_access_key' => null,
                    'usd_bdt_rate' => $settings->r2UsdBdtRate(),
                ];
            })
            ->schema([
                TextEntry::make('r2Guide')
                    ->label(__('Where to find these'))
                    ->state([
                        __('Cloudflare dashboard → R2 → Create bucket (private, no public access). The bucket name goes below.'),
                        __('R2 → Manage R2 API Tokens → Create API token → permission "Object Read & Write", limited to that one bucket.'),
                        __('Copy the Access Key ID and the Secret Access Key at once: the secret is shown only one time.'),
                        __('The Account ID is on the R2 overview page (right side), or paste the S3 endpoint address.'),
                    ])
                    ->bulleted()
                    ->columnSpanFull(),
                Toggle::make('enabled')
                    ->label(__('Enable Cloudflare R2 backup')),
                Toggle::make('auto_upload')
                    ->label(__('Auto upload to R2'))
                    ->helperText(__('Sends the hourly and daily database backups and the daily full app backup to R2, encrypted, and deletes older copies there.')),
                TextInput::make('account')
                    ->label(__('Account ID or endpoint address'))
                    ->placeholder('0123456789abcdef0123456789abcdef')
                    ->helperText(__('32 letters and digits, or the full address such as https://ACCOUNT_ID.r2.cloudflarestorage.com'))
                    ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                        $value = trim((string) $value);

                        if ($value !== '' && preg_match('/^[0-9a-f]{32}$/i', $value) !== 1 && preg_match('#^https?://[^\s/]+#i', $value) !== 1) {
                            $fail(__('Enter the 32-character Account ID or an address starting with https://'));
                        }
                    }])
                    ->autocomplete(false),
                TextInput::make('bucket')
                    ->label(__('Bucket name'))
                    ->placeholder('zamzam-erp-backups')
                    // A space pasted along with the name is trimmed on save, so it is not an error.
                    ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', trim((string) $value)) !== 1) {
                            $fail(__('The :attribute field format is invalid.', ['attribute' => __('Bucket name')]));
                        }
                    }])
                    ->helperText(__('Lower-case letters, digits and hyphens. Not the bucket used for product images or customer files.'))
                    ->autocomplete(false),
                TextInput::make('prefix')
                    ->label(__('Folder inside the bucket'))
                    ->placeholder('erp-backups')
                    ->regex('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#')
                    ->helperText(__('Everything this app writes or deletes stays inside this folder.'))
                    ->autocomplete(false),
                TextInput::make('access_key_id')
                    ->label(__('Access Key ID'))
                    ->autocomplete(false),
                TextInput::make('secret_access_key')
                    ->label(__('Secret Access Key'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->helperText(fn (): string => app(BackupSettingsService::class)->hasR2SecretAccessKey()
                        ? __('A secret is already stored (encrypted). Leave this blank to keep it.')
                        : __('It is encrypted before it is stored.')),
                TextInput::make('usd_bdt_rate')
                    ->label(__('Dollar to taka rate (optional)'))
                    ->numeric()
                    ->minValue(1)
                    ->helperText(__('Only to show the estimated bill in taka as well. Cloudflare charges in US dollars.')),
            ])
            ->action(fn (array $data, BackupSettingsService $settings) => $this->saveSettings($settings, $data));
    }

    /** @param  array<string, mixed>  $data */
    public function saveSettings(BackupSettingsService $settings, array $data): void
    {
        $settings->saveR2($data);
        app(R2UsageReport::class)->forget();
        $this->dashboardData = null;

        $missing = app(R2BackupService::class)->missingSettings();

        if (! empty($data['enabled']) && $missing !== []) {
            Notification::make()
                ->title(__('R2 settings saved, but not complete'))
                ->body(__('Still to be filled in: :items', ['items' => implode(', ', $missing)]))
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title(__('R2 settings saved'))->success()->send();
    }

    public function testConnection(R2BackupService $r2): void
    {
        $result = $r2->testConnection();

        Notification::make()
            ->title($result['ok'] ? __('R2 connection works') : __('R2 connection failed'))
            ->body($result['message'])
            ->color($result['ok'] ? 'success' : 'danger')
            ->send();
    }

    public function backupNow(DatabaseBackupService $backups): void
    {
        try {
            $backup = $backups->create(config('backup.app.database_connection'), BackupRun::KIND_MANUAL, upload: true);
            app(R2UsageReport::class)->forget();
            $this->dashboardData = null;

            $failed = array_keys((array) ($backup['offsite']['failed'] ?? []));

            Notification::make()
                ->title(__('Backup sent off-site'))
                ->body($failed === []
                    ? __('Created :name and sent it off-site.', ['name' => $backup['name']])
                    : __('Created :name. It did not reach: :places', ['name' => $backup['name'], 'places' => implode(', ', $failed)]))
                ->color($failed === [] ? 'success' : 'warning')
                ->send();
        } catch (Throwable $exception) {
            Notification::make()->title(__('Backup failed'))->body($exception->getMessage())->danger()->send();
        }
    }

    /**
     * Everything the page shows, built once per request.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return $this->dashboardData ??= $this->buildDashboard();
    }

    /** @return array<string, mixed> */
    protected function buildDashboard(): array
    {
        $r2 = app(R2BackupService::class);
        $estimator = app(R2CostEstimator::class);
        $missing = $r2->missingSettings();
        $report = app(R2UsageReport::class)->snapshot();

        $state = match (true) {
            $missing !== [] => 'not_set_up',
            ! $r2->isEnabled() => 'off',
            $report['state'] === 'error' => 'error',
            default => 'ready',
        };

        $monthToDate = $estimator->monthToDate();
        $forecast = $estimator->forecast($report['groups']);
        $settings = app(BackupSettingsService::class);

        return [
            'state' => $state,
            'missing' => $missing,
            'error' => $report['error'],
            'bucket' => $settings->r2Bucket(),
            'folder' => $settings->r2Prefix(),
            'endpoint_host' => parse_url((string) $settings->r2Endpoint(), PHP_URL_HOST) ?: null,
            'auto_upload' => $r2->autoUploadEnabled(),
            'encrypted' => app(BackupEncryptor::class)->isConfigured(),
            'encryption_required' => (bool) config('backup.offsite.require_encryption', true),
            'report' => $report,
            'fetched_at' => $report['fetched_at'] ? Carbon::parse($report['fetched_at']) : null,
            'pricing' => $estimator->pricing(),
            'month_to_date' => $monthToDate,
            'bill_now' => $estimator->bill($report['bytes'], $monthToDate['class_a'], $monthToDate['class_b']),
            'forecast' => $forecast,
            'bill_full' => $estimator->bill($forecast['steady_bytes'], $forecast['class_a'], $forecast['class_b']),
            'scenarios' => $forecast['has_data'] ? $estimator->scenarios($report['groups']) : [],
            // Before there is anything to measure: typical sizes (database copy, full app archive).
            'examples' => $forecast['has_data'] ? [] : array_map(
                fn (array $size): array => $estimator->example($size[0], $size[1]),
                [[50_000_000, 500_000_000], [200_000_000, 2_000_000_000], [1_000_000_000, 10_000_000_000]],
            ),
            'last_upload' => $this->lastUpload(),
            'recent_runs' => $this->recentRuns(),
            'health' => $this->healthChecks(),
        ];
    }

    protected function lastUpload(): ?Carbon
    {
        try {
            if (! Schema::hasTable('backup_runs') || ! Schema::hasColumn('backup_runs', 'offsite_to')) {
                return null;
            }

            $latest = BackupRun::query()
                ->where('status', BackupRun::STATUS_SUCCESS)
                ->where('offsite_to', 'like', '%'.R2BackupService::KEY.'%')
                ->max('finished_at');

            return $latest ? Carbon::parse($latest) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    protected function recentRuns(): array
    {
        try {
            if (! Schema::hasTable('backup_runs') || ! Schema::hasColumn('backup_runs', 'offsite_to')) {
                return [];
            }

            return BackupRun::query()
                ->where(fn ($query) => $query
                    ->where('offsite_to', 'like', '%'.R2BackupService::KEY.'%')
                    ->orWhere('error', 'like', '%Cloudflare R2%')
                    ->orWhere('note', 'like', '%Not sent to%'.R2BackupService::KEY.'%')
                    ->orWhere(fn ($drill) => $drill->where('scope', BackupRun::SCOPE_DRILL)->where('kind', BackupRun::DRILL_R2)))
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (BackupRun $run): array => [
                    'status' => $run->status,
                    'what' => $run->scope === BackupRun::SCOPE_DRILL ? __('Restore test') : ($run->scope === BackupRun::SCOPE_APP ? __('Full app') : __('Database')),
                    'filename' => $run->filename,
                    'size' => $run->size_bytes,
                    'at' => $run->started_at,
                    'note' => $run->error ?: $run->note,
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    protected function healthChecks(): array
    {
        try {
            return app(BackupHealth::class)->checks();
        } catch (Throwable) {
            return [];
        }
    }

    // --- formatting used by the view ---------------------------------

    /** Sizes in the units Cloudflare bills in (1 GB = 1,000,000,000 bytes), so they match its dashboard. */
    public function bytes(int|float|null $bytes): string
    {
        $bytes = max(0, (float) $bytes);

        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $unit) {
            if ($bytes < 1000 || $unit === 'TB') {
                return ($i === 0 ? number_format($bytes) : rtrim(rtrim(number_format($bytes, 2), '0'), '.')).' '.$unit;
            }

            $bytes /= 1000;
        }

        return '';
    }

    /** A bill to the cent ($1.77); a price keeps its precision ($0.015); a tiny bill is not rounded to nothing ($0.0045). */
    public function usd(float $amount): string
    {
        if ($amount >= 1 || abs($amount * 100 - round($amount * 100)) < 1e-9) {
            return '$'.number_format($amount, 2);
        }

        return '$'.rtrim(number_format($amount, 4), '0');
    }

    public function taka(?float $amount): ?string
    {
        // Two decimals on purpose: MoneyFormatter::number() with none would strip the zeros of 180.
        return $amount === null ? null : MoneyFormatter::currency(round($amount), 'BDT', 2);
    }

    public function ago(mixed $time): string
    {
        return $time ? Carbon::parse($time)->diffForHumans() : '—';
    }

    public function kindLabel(string $kind): string
    {
        return match ($kind) {
            BackupRun::KIND_HOURLY => __('Hourly'),
            BackupRun::KIND_DAILY => __('Daily'),
            BackupRun::KIND_PREDEPLOY => __('Before deploy'),
            BackupRun::KIND_PRERESTORE => __('Before restore'),
            default => __('Manual'),
        };
    }

    public function scopeLabel(string $scope): string
    {
        return $scope === BackupRun::SCOPE_APP ? __('Full app') : __('Database');
    }
}
