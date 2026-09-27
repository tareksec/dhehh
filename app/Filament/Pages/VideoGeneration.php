<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\AiTools;
use App\Filament\Concerns\OptimizesUploadedImages;
use App\Filament\Concerns\SelectableFromMediaHub;
use App\Jobs\GenerateVideoJob;
use App\Models\Company;
use App\Models\GeneratedImage;
use App\Models\GeneratedVideo;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\VideoGeneration\VideoProviderSettingsService;
use App\Support\CompanyMedia;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Video Generation tool (12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md, Phase
 * 1). The prompt form + starting-image picker live here; the chosen provider
 * profile and its API key are configured on Settings → Integrations → Video Providers.
 * Generation runs in GenerateVideoJob (queued, async submit -> poll) and the
 * results gallery polls itself until every pending row is finished.
 *
 * Picking a starting image has three paths, all converging on the same
 * FileUpload field: upload a fresh photo, "Select From Media" to browse the
 * company's Media Hub (which already carries every finished Image Generation
 * output), or the dedicated quick-pick sourced from the Image Library's
 * `is_video_reference`-flagged pool — the only path that also records
 * `source_generated_image_id`, closing the loop that pool was built for.
 *
 * Attach-to-record actions, the Prompt Enhancer, favourites, and governance
 * are later phases (plan §12) — this page only generates and lists.
 */
class VideoGeneration extends Page
{
    use OptimizesUploadedImages;
    use SelectableFromMediaHub;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static ?string $cluster = AiTools::class;

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Video Generation';

    protected static ?string $title = 'Video Generation';

    protected string $view = 'filament.pages.video-generation';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Per-request memo so one render does not query the same 12 rows three times. */
    private ?Collection $recentGenerationsCache = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('ai_tools.video_generation') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill($this->initialFormState());
    }

    /** @return array<string, mixed> */
    protected function initialFormState(): array
    {
        return [
            'prompt' => '',
            'context' => 'general',
            'aspect_ratio' => GeneratedVideo::DEFAULT_ASPECT_RATIO,
            'duration_preset' => GeneratedVideo::DEFAULT_DURATION_PRESET,
            'provider_profile_id' => $this->defaultProfileId(),
            'source_image' => null,
            'source_generated_image_id' => null,
            'video_reference_pick' => null,
        ];
    }

    public function hasSelectedCompany(): bool
    {
        $context = app(CompanyContext::class);

        return $context->hasCompany() && ! $context->isAllCompanies();
    }

    /** @return array<int, array<string, mixed>> */
    public function providerProfiles(): array
    {
        if (! $this->hasSelectedCompany()) {
            return [];
        }

        return app(VideoProviderSettingsService::class)->list(app(CompanyContext::class)->company());
    }

    /** A configured profile has credentials — and, for the custom format, a submit + poll URL. */
    public function hasConfiguredProvider(): bool
    {
        return collect($this->providerProfiles())->contains(function (array $profile): bool {
            $hasCredential = $profile['has_api_key'] || $profile['api_format'] === 'custom';

            if ($profile['api_format'] === 'custom') {
                return $hasCredential && filled($profile['base_url']) && filled($profile['poll_url_template']);
            }

            return $hasCredential;
        });
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Hidden::make('source_generated_image_id'),
                Textarea::make('prompt')
                    ->label('Motion / scene prompt')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000)
                    ->placeholder('Slow push-in on the product, soft studio lighting, subtle steam rising from the cup')
                    ->columnSpanFull(),
                Select::make('context')
                    ->label('What is this for?')
                    ->options(GeneratedVideo::CONTEXTS)
                    ->default('general')
                    ->required()
                    ->native(false),
                Select::make('aspect_ratio')
                    ->label('Aspect ratio')
                    ->options(collect(GeneratedVideo::ASPECT_RATIOS)->map(fn (array $r): string => $r['label'])->all())
                    ->default(GeneratedVideo::DEFAULT_ASPECT_RATIO)
                    ->required()
                    ->native(false),
                Select::make('duration_preset')
                    ->label('Duration')
                    ->options(collect(GeneratedVideo::DURATION_PRESETS)->map(fn (array $d): string => $d['label'])->all())
                    ->default(GeneratedVideo::DEFAULT_DURATION_PRESET)
                    ->required()
                    ->native(false)
                    ->helperText('Some providers (Stability today) always return one fixed-length clip regardless of this pick — it matters once a duration-capable provider is added.'),
                Select::make('provider_profile_id')
                    ->label('Provider')
                    ->options(fn (): array => collect($this->providerProfiles())
                        ->mapWithKeys(fn (array $p): array => [$p['id'] => $p['label'].($p['is_default'] ? ' (default)' : '')])
                        ->all())
                    ->default(fn (): ?string => $this->defaultProfileId())
                    ->required()
                    ->native(false)
                    ->helperText(fn (): ?string => $this->hasConfiguredProvider()
                        ? null
                        : __('No video provider is configured yet. A super admin can add one on Settings → Integrations → Video Providers.')),

                Select::make('video_reference_pick')
                    ->label('Or pick from your video-reference pool')
                    ->helperText('Images marked "video reference" in the Image Generation library.')
                    ->options(fn (): array => $this->videoReferenceOptions())
                    ->native(false)
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn (): bool => $this->videoReferenceOptions() !== [])
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $image = filled($state) && $this->hasSelectedCompany()
                            ? GeneratedImage::query()->where('tool', GeneratedImage::TOOL_IMAGE_GENERATION)->find($state)
                            : null;
                        $path = collect($image?->output_paths ?? [])->first();

                        if ($image === null || blank($path)) {
                            return;
                        }

                        // A FileUpload's live state must be array-shaped (its own
                        // validation rules assume it, and hydrateFiles() normalizes
                        // to this shape on every real hydration) — a bare string
                        // here would throw a TypeError once the field revalidates.
                        // saveUploadedFiles() passes a plain string value through
                        // unchanged, so this never gets re-optimized as a fresh
                        // upload.
                        $set('source_image', [(string) Str::uuid() => $path]);
                        $set('source_generated_image_id', $image->getKey());
                    })
                    ->columnSpanFull(),
                FileUpload::make('source_image')
                    ->label('Starting image')
                    ->helperText('The frame the video starts from.')
                    ->image()
                    ->required()
                    ->tap(static::browserImagePrecompression())
                    ->disk(fn (): string => CompanyMedia::publicDiskName())
                    ->directory(fn (): string => CompanyMedia::publicDirectory('ai-video-source-images'))
                    ->fetchFileInformation(false)
                    ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                    ->getOpenableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                    ->getDownloadableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                    ->saveUploadedFileUsing(static::optimizeImageUpload())
                    ->hintAction(static::selectFromMediaHubAction(fn (): ?Company => $this->hasSelectedCompany()
                        ? app(CompanyContext::class)->company()
                        : null))
                    ->columnSpanFull(),
            ]);
    }

    /** @return array<int, string> */
    protected function videoReferenceOptions(): array
    {
        if (! $this->hasSelectedCompany()) {
            return [];
        }

        return GeneratedImage::query()
            ->where('tool', GeneratedImage::TOOL_IMAGE_GENERATION)
            ->completed()
            ->videoReferences()
            ->latest()
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (GeneratedImage $image): array => [
                $image->getKey() => Str::limit($image->prompt, 60) ?: "Generation #{$image->getKey()}",
            ])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate')
                ->icon(Heroicon::OutlinedSparkles)
                ->keyBindings(['mod+enter'])
                ->disabled(fn (): bool => ! $this->hasSelectedCompany() || ! $this->hasConfiguredProvider())
                ->action('generate'),
        ];
    }

    public function generate(): void
    {
        if (! $this->hasSelectedCompany()) {
            Notification::make()->title('Select a company first')->warning()->send();

            return;
        }

        $company = app(CompanyContext::class)->company();
        $settings = app(VideoProviderSettingsService::class);

        $profile = $settings->find($company, $this->data['provider_profile_id'] ?? null)
            ?? $settings->default($company);

        if ($profile === null || ! $settings->isConfigured($profile)) {
            throw ValidationException::withMessages([
                'data.provider_profile_id' => __('Pick a provider that is fully configured on Settings → Integrations → Video Providers.'),
            ]);
        }

        // Dehydrate the form: this persists an uploaded/media-hub-picked
        // image through optimizeImageUpload() and turns source_image into
        // its stored path.
        $state = $this->form->getState();
        $sourcePath = $this->storedSourcePath($state['source_image'] ?? null);

        if ($sourcePath === null) {
            throw ValidationException::withMessages([
                'data.source_image' => 'Upload a starting image, or pick one from your video-reference pool.',
            ]);
        }

        $context = array_key_exists($state['context'] ?? '', GeneratedVideo::CONTEXTS) ? $state['context'] : 'general';
        $aspectRatio = array_key_exists($state['aspect_ratio'] ?? '', GeneratedVideo::ASPECT_RATIOS)
            ? $state['aspect_ratio']
            : GeneratedVideo::DEFAULT_ASPECT_RATIO;
        $durationPreset = array_key_exists($state['duration_preset'] ?? '', GeneratedVideo::DURATION_PRESETS)
            ? $state['duration_preset']
            : GeneratedVideo::DEFAULT_DURATION_PRESET;
        $prompt = trim((string) $state['prompt']);

        $record = GeneratedVideo::query()->create([
            'user_id' => Auth::id(),
            'tool' => GeneratedVideo::TOOL_VIDEO_GENERATION,
            'context' => $context,
            'operation' => GeneratedVideo::OPERATION_IMAGE_TO_VIDEO,
            'prompt' => $prompt,
            'source_image_path' => $sourcePath,
            'source_generated_image_id' => $state['source_generated_image_id'] ?: null,
            'provider_profile_id' => $profile['id'],
            'provider_label' => $profile['label'],
            'api_format' => $profile['api_format'],
            'model' => $profile['model'] ?: null,
            'aspect_ratio' => $aspectRatio,
            'duration_preset' => $durationPreset,
            'status' => GeneratedVideo::STATUS_QUEUED,
        ]);

        app(AuditLogService::class)->record('ai_video.generated', $record, null, [
            'prompt' => $prompt,
            'context' => $context,
            'provider' => $profile['label'],
            'api_format' => $profile['api_format'],
        ]);

        GenerateVideoJob::dispatch($record->getKey());

        $this->recentGenerationsCache = null;

        $this->form->fill($this->initialFormState());

        Notification::make()
            ->title('Video generation started')
            ->body('This can take a few minutes — it will appear below and you will be notified when it is ready.')
            ->success()
            ->send();
    }

    /** Normalize a FileUpload's dehydrated state (string or [id => path]) to a path. */
    protected function storedSourcePath(mixed $value): ?string
    {
        $path = is_array($value) ? (reset($value) ?: null) : $value;

        return filled($path) ? (string) $path : null;
    }

    protected function defaultProfileId(): ?string
    {
        if (! $this->hasSelectedCompany()) {
            return null;
        }

        return app(VideoProviderSettingsService::class)
            ->default(app(CompanyContext::class)->company())['id'] ?? null;
    }

    /** @return Collection<int, GeneratedVideo> */
    public function recentGenerations(): Collection
    {
        if ($this->recentGenerationsCache !== null) {
            return $this->recentGenerationsCache;
        }

        if (! $this->hasSelectedCompany()) {
            return $this->recentGenerationsCache = collect();
        }

        return $this->recentGenerationsCache = GeneratedVideo::query()
            ->where('user_id', Auth::id())
            ->where('tool', GeneratedVideo::TOOL_VIDEO_GENERATION)
            ->latest()
            ->limit(12)
            ->get();
    }

    public function hasPendingGenerations(): bool
    {
        return $this->recentGenerations()->contains(fn (GeneratedVideo $row): bool => ! $row->isTerminal());
    }
}
