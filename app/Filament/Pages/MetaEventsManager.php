<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Ads;
use App\Models\MetaAdAccount;
use App\Models\MetaAudience;
use App\Models\StorefrontMetaEvent;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\MetaAudienceSyncService;
use App\Services\MetaMarketingApiClient;
use App\Services\StorefrontMetaTrackingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Phase E: a Pixel Health panel (real Meta identity + event-volume-by-type,
 * side by side with our own local storefront_meta_events send log — two
 * distinct real data sources, never merged) plus Custom Audience management
 * (Website + Lookalike only — see the Meta Ads plan for why "Customer List"
 * bulk-PII-upload audiences are deliberately out of scope).
 *
 * Lives in the Ads cluster (not Storefront) because Custom Audiences are
 * Marketing API objects under act_{ad_account_id}, needing MetaAdAccount's
 * own access token (already documented as needing ads_management/ads_read)
 * rather than the narrower CAPI-only token on StorefrontSetting. The Pixel
 * ID itself is still sourced from the existing Storefront configuration
 * (StorefrontMetaTrackingService::pixelConfigurations()) — no new
 * pixel-credential field is added anywhere.
 *
 * Pixel Health is fetched explicitly (mount() + an account/pixel/date-preset
 * change, or the Refresh action) — never on every unrelated render — since
 * pixel()/pixelEventStats() are live Meta HTTP calls, unlike
 * MetaAdsDashboard::stats() which only ever queries the local database.
 */
class MetaEventsManager extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $cluster = Ads::class;

    protected static ?string $navigationLabel = 'Events Manager';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Meta Events Manager';

    protected string $view = 'filament.pages.meta-events-manager';

    #[Url]
    public ?int $accountId = null;

    #[Url]
    public ?string $pixelId = null;

    #[Url]
    public string $datePreset = MetaMarketingApiClient::DEFAULT_DATE_PRESET;

    /** @var array{id?: string, name?: string, creation_time?: string, last_fired_time?: string, is_unavailable?: bool}|null */
    public ?array $pixelIdentity = null;

    /** @var array<int, array{event: string, count: int}>|null */
    public ?array $pixelEventStatsRows = null;

    public ?string $pixelStatsNotice = null;

    public ?string $pixelHealthError = null;

    public function mount(): void
    {
        if ($this->accountId === null) {
            $this->accountId = $this->accounts()->first()?->getKey();
        }

        if ($this->pixelId === null) {
            $this->pixelId = array_key_first($this->pixelOptions()) ?: null;
        }

        $this->loadPixelHealth();
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('marketing.view') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('accountId')
                ->label('Ad Account')
                ->options(fn (): array => $this->accounts()->pluck('name', 'id')->all())
                ->native(false)
                ->live()
                ->maxWidth(Width::ExtraSmall)
                ->visible(fn (): bool => $this->accounts()->count() > 1)
                ->selectablePlaceholder(false)
                ->afterStateUpdated(function (): void {
                    $this->pixelId = array_key_first($this->pixelOptions()) ?: null;
                    $this->loadPixelHealth();
                    $this->resetTable();
                }),
            Select::make('pixelId')
                ->label('Pixel / Dataset')
                ->options(fn (): array => $this->pixelOptions())
                ->native(false)
                ->live()
                ->maxWidth(Width::ExtraSmall)
                ->visible(fn (): bool => $this->pixelOptions() !== [])
                ->selectablePlaceholder(false)
                ->afterStateUpdated(function (): void {
                    $this->loadPixelHealth();
                }),
            Select::make('datePreset')
                ->label('Stats Window')
                ->options(MetaAdsDashboard::DATE_PRESETS)
                ->native(false)
                ->live()
                ->maxWidth(Width::ExtraSmall)
                ->selectablePlaceholder(false)
                ->helperText('Applies to the Pixel Health panel below, not the Audiences list.')
                ->afterStateUpdated(fn () => $this->loadPixelHealth()),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshPixelHealth')
                ->label('Refresh Pixel Health')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => $this->selectedAccount() !== null && $this->pixelId !== null)
                ->action(fn () => $this->loadPixelHealth()),
            Action::make('syncAudiences')
                ->label('Sync Audiences')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => Auth::user()?->hasPermission('marketing.create') ?? false)
                ->disabled(fn (): bool => $this->selectedAccount() === null)
                ->action(function (MetaAudienceSyncService $service): void {
                    $account = $this->selectedAccount();

                    if (! $account) {
                        return;
                    }

                    try {
                        $result = $service->sync($account);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Sync failed')
                            ->body($this->exceptionMessage($exception))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Synced')
                        ->body("{$result['created']} created, {$result['updated']} updated.")
                        ->success()
                        ->send();

                    $this->resetTable();
                }),
            Action::make('newAudience')
                ->label('New Audience')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->visible(fn (): bool => Auth::user()?->hasPermission('marketing.create') ?? false)
                ->disabled(fn (): bool => $this->selectedAccount() === null)
                ->schema(fn (): array => $this->newAudienceForm())
                ->action(fn (array $data, MetaMarketingApiClient $client) => $this->createAudience($data, $client)),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->currentQuery())
            ->columns([
                TextColumn::make('name')->searchable()->limit(40),
                TextColumn::make('subtype')
                    ->badge()
                    ->color(fn (string $state): string => $state === MetaAudience::SUBTYPE_LOOKALIKE ? 'info' : 'success'),
                TextColumn::make('size')
                    ->label('Approx. Size')
                    ->getStateUsing(fn (MetaAudience $record): string => $record->approximateSizeLabel()),
                TextColumn::make('delivery_status')->badge()->placeholder('-'),
                TextColumn::make('origin')
                    ->label('Origin')
                    ->getStateUsing(fn (MetaAudience $record): string => $record->subtype === MetaAudience::SUBTYPE_WEBSITE
                        ? 'Pixel '.($record->pixel_id ?? '-')
                        : ($record->originAudience?->name ?? $record->origin_audience_meta_id ?? '-')),
                TextColumn::make('last_synced_at')->label('Last Synced')->since()->placeholder('never'),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (): bool => Auth::user()?->hasPermission('marketing.delete') ?? false)
                    ->requiresConfirmation()
                    ->action(fn (MetaAudience $record, MetaMarketingApiClient $client) => $this->deleteAudience($record, $client)),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading($this->selectedAccount() ? 'No audiences yet' : 'No ad account selected')
            ->emptyStateDescription($this->selectedAccount()
                ? 'Click Sync Audiences to pull existing ones from Meta, or New Audience to create one.'
                : 'Add and select a Meta Ads account to get started.')
            ->emptyStateIcon(Heroicon::OutlinedUserGroup);
    }

    /**
     * Loads real Pixel identity + event-volume-by-type from Meta. Never
     * called on an unrelated render — only from mount() and the selector/
     * Refresh action handlers above.
     */
    public function loadPixelHealth(): void
    {
        $this->pixelIdentity = null;
        $this->pixelEventStatsRows = null;
        $this->pixelStatsNotice = null;
        $this->pixelHealthError = null;

        $account = $this->selectedAccount();

        if (! $account || ! $this->pixelId) {
            return;
        }

        $client = app(MetaMarketingApiClient::class);

        try {
            $this->pixelIdentity = $client->pixel($account, $this->pixelId);
        } catch (Throwable $exception) {
            $this->pixelHealthError = $this->exceptionMessage($exception);

            return;
        }

        [$start, $end] = $this->dateRangeForPreset($this->datePreset);

        try {
            $rows = $client->pixelEventStats($account, $this->pixelId, $start, $end);
        } catch (Throwable $exception) {
            $this->pixelStatsNotice = "Meta didn't return event stats for this pixel: ".$this->exceptionMessage($exception);

            return;
        }

        // Meta's public docs don't fully specify this endpoint's row shape,
        // so keys are detected defensively rather than assumed — see the
        // class docblock and the Meta Ads plan's Phase E honesty caveat.
        $summed = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = $row['name'] ?? $row['event'] ?? null;
            $count = $row['count'] ?? $row['total_count'] ?? $row['value'] ?? null;

            if (! is_string($label) || $label === '' || ! is_numeric($count)) {
                continue;
            }

            $summed[$label] = ($summed[$label] ?? 0) + (int) $count;
        }

        if ($summed === []) {
            $this->pixelStatsNotice = "Meta didn't return recognizable event stats for this pixel (or this token lacks pixel-read access) — see the send log below instead.";

            return;
        }

        $this->pixelEventStatsRows = collect($summed)
            ->map(fn (int $count, string $event): array => ['event' => $event, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * Our own real, 100% local send-attempt log for the same pixel + date
     * window — shown next to (never merged with) the Meta panel above, so
     * the two different sources are never confused for one another.
     */
    public function sendLogRows(): Collection
    {
        if (! $this->pixelId) {
            return collect();
        }

        [$start, $end] = $this->dateRangeForPreset($this->datePreset);

        return StorefrontMetaEvent::query()
            ->where('pixel_id', $this->pixelId)
            ->whereBetween('created_at', [
                now()->createFromTimestamp($start),
                now()->createFromTimestamp($end),
            ])
            ->selectRaw('event_name, status, COUNT(*) as total')
            ->groupBy('event_name', 'status')
            ->orderBy('event_name')
            ->get();
    }

    /** @return Collection<int, MetaAdAccount> */
    public function accounts(): Collection
    {
        $companyId = app(CompanyContext::class)->id();

        return MetaAdAccount::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function selectedAccount(): ?MetaAdAccount
    {
        if (! $this->accountId) {
            return null;
        }

        return $this->accounts()->firstWhere('id', $this->accountId);
    }

    /**
     * This company's already-configured Pixel/Dataset IDs (primary +
     * additional) — the single source of truth reused from the existing
     * Meta CAPI settings, never a new pixel-credential field.
     *
     * @return array<string, string>
     */
    public function pixelOptions(): array
    {
        $account = $this->selectedAccount();

        if (! $account) {
            return [];
        }

        // Every site of the company (Blade and Next.js) has its own pixels.
        $pixelIds = StorefrontSetting::withoutGlobalScopes()
            ->where('company_id', $account->company_id)
            ->get()
            ->flatMap(fn (StorefrontSetting $setting): array => array_column(
                app(StorefrontMetaTrackingService::class)->pixelConfigurations($setting),
                'pixel_id',
            ))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $pixelIds === [] ? [] : array_combine($pixelIds, $pixelIds);
    }

    protected function newAudienceForm(): array
    {
        $account = $this->selectedAccount();
        $pixelOptions = $this->pixelOptions();

        $originOptions = $account
            ? MetaAudience::query()
                ->where('meta_ad_account_id', $account->getKey())
                ->where('subtype', MetaAudience::SUBTYPE_WEBSITE)
                ->pluck('name', 'meta_id')
                ->all()
            : [];

        return [
            Select::make('type')
                ->label('Type')
                ->options([
                    MetaAudience::SUBTYPE_WEBSITE => 'Website Visitors (from Pixel)',
                    MetaAudience::SUBTYPE_LOOKALIKE => 'Lookalike (from an existing audience)',
                ])
                ->required()
                ->live()
                ->native(false),
            TextInput::make('name')
                ->label('Audience Name')
                ->required()
                ->maxLength(255),

            // WEBSITE-only
            Select::make('pixel_id')
                ->label('Pixel / Dataset')
                ->options($pixelOptions)
                ->native(false)
                ->disabled($pixelOptions === [])
                ->required(fn (Get $get): bool => $get('type') === MetaAudience::SUBTYPE_WEBSITE)
                ->visible(fn (Get $get): bool => $get('type') === MetaAudience::SUBTYPE_WEBSITE)
                ->helperText($pixelOptions === []
                    ? 'No Pixel configured yet — set one up on the Meta CAPI settings page first.'
                    : null),
            TextInput::make('retention_days')
                ->label('Retention (days)')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(180)
                ->default(30)
                ->required(fn (Get $get): bool => $get('type') === MetaAudience::SUBTYPE_WEBSITE)
                ->visible(fn (Get $get): bool => $get('type') === MetaAudience::SUBTYPE_WEBSITE),
            TextInput::make('url_contains')
                ->label('Only URLs containing (optional)')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => $get('type') === MetaAudience::SUBTYPE_WEBSITE),

            // LOOKALIKE-only — source restricted to this app's own Website
            // audiences, keeping the picker grounded in data this app
            // actually knows about (Meta Ads plan Phase E scope limit).
            Select::make('origin_audience_meta_id')
                ->label('Source Audience')
                ->options($originOptions)
                ->native(false)
                ->disabled($originOptions === [])
                ->required(fn (Get $get): bool => $get('type') === MetaAudience::SUBTYPE_LOOKALIKE)
                ->visible(fn (Get $get): bool => $get('type') === MetaAudience::SUBTYPE_LOOKALIKE)
                ->helperText($originOptions === []
                    ? "No Website Visitors audience yet — create one first; Lookalikes can only be built from this app's own Website audiences."
                    : null),
            TextInput::make('lookalike_country')
                ->label('Target Country')
                ->default('BD')
                ->maxLength(8)
                ->required(fn (Get $get): bool => $get('type') === MetaAudience::SUBTYPE_LOOKALIKE)
                ->visible(fn (Get $get): bool => $get('type') === MetaAudience::SUBTYPE_LOOKALIKE)
                ->helperText('2-letter country code, e.g. BD. Editable — a Lookalike may target a different market than its source visitors.'),
            TextInput::make('lookalike_ratio')
                ->label('Audience Size')
                ->numeric()
                ->minValue(1)
                ->maxValue(20)
                ->default(1)
                ->suffix('%')
                ->required(fn (Get $get): bool => $get('type') === MetaAudience::SUBTYPE_LOOKALIKE)
                ->visible(fn (Get $get): bool => $get('type') === MetaAudience::SUBTYPE_LOOKALIKE),
        ];
    }

    protected function createAudience(array $data, MetaMarketingApiClient $client): void
    {
        $account = $this->selectedAccount();

        if (! $account) {
            return;
        }

        $isWebsite = $data['type'] === MetaAudience::SUBTYPE_WEBSITE;
        $urlContains = filled($data['url_contains'] ?? null) ? $data['url_contains'] : null;

        try {
            $response = $isWebsite
                ? $client->createWebsiteCustomAudience($account, $data['name'], $data['pixel_id'], (int) $data['retention_days'], $urlContains)
                : $client->createLookalikeAudience($account, $data['name'], $data['origin_audience_meta_id'], $data['lookalike_country'], (float) $data['lookalike_ratio']);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not create audience')
                ->body($this->exceptionMessage($exception))
                ->danger()
                ->send();

            return;
        }

        $metaId = $response['id'] ?? null;

        if (blank($metaId)) {
            Notification::make()
                ->title('Meta did not return an audience ID')
                ->danger()
                ->send();

            return;
        }

        MetaAudience::create([
            'company_id' => $account->company_id,
            'meta_ad_account_id' => $account->getKey(),
            'meta_id' => $metaId,
            'name' => $data['name'],
            'subtype' => $data['type'],
            'pixel_id' => $isWebsite ? $data['pixel_id'] : null,
            'retention_days' => $isWebsite ? (int) $data['retention_days'] : null,
            'rule' => $isWebsite ? $client->websiteAudienceRule($data['pixel_id'], (int) $data['retention_days'], $urlContains) : null,
            'origin_audience_meta_id' => $isWebsite ? null : $data['origin_audience_meta_id'],
            'lookalike_country' => $isWebsite ? null : $data['lookalike_country'],
            'lookalike_ratio' => $isWebsite ? null : (float) $data['lookalike_ratio'],
            'source' => MetaAudience::SOURCE_ERP,
            'created_by_user_id' => Auth::id(),
        ]);

        Notification::make()
            ->title('Audience created')
            ->body('Meta is building it now — the size estimate may take a while to appear; use Sync Audiences to refresh it.')
            ->success()
            ->send();

        $this->resetTable();
    }

    /**
     * Meta first, then local — copies MetaAdsDashboard::toggleStatus()'s
     * discipline exactly: a failed delete leaves the local row untouched.
     */
    protected function deleteAudience(MetaAudience $record, MetaMarketingApiClient $client): void
    {
        $account = $this->selectedAccount();

        if (! $account) {
            return;
        }

        try {
            $client->deleteAudience($account, $record->meta_id);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Delete failed')
                ->body($this->exceptionMessage($exception))
                ->danger()
                ->send();

            return;
        }

        $record->delete();

        Notification::make()
            ->title('Audience deleted')
            ->success()
            ->send();
    }

    /**
     * This app's own best-effort mapping of Meta's date_preset names to a
     * concrete time window — not guaranteed to exactly match Meta's
     * internal day boundaries, only used for the Pixel Health panel's
     * stats/send-log windows.
     *
     * @return array{0: int, 1: int}
     */
    protected function dateRangeForPreset(string $preset): array
    {
        $now = now();

        [$start, $end] = match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7d' => [$now->copy()->subDays(7)->startOfDay(), $now->copy()->endOfDay()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            default => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()], // last_30d
        };

        return [$start->timestamp, $end->timestamp];
    }

    protected function currentQuery(): Builder
    {
        if (! $this->accountId) {
            return MetaAudience::query()->whereRaw('1 = 0');
        }

        return MetaAudience::query()
            ->where('meta_ad_account_id', $this->accountId)
            ->with('originAudience');
    }

    protected function exceptionMessage(Throwable $exception): string
    {
        return $exception instanceof ValidationException
            ? collect($exception->errors())->flatten()->implode(' ')
            : $exception->getMessage();
    }
}
