<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\AiTools;
use App\Filament\Concerns\OptimizesUploadedImages;
use App\Filament\Concerns\SelectableFromMediaHub;
use App\Filament\Pages\Concerns\SavesVideosToDrive;
use App\Jobs\GenerateVideoJob;
use App\Models\Company;
use App\Models\GeneratedImage;
use App\Models\GeneratedVideo;
use App\Models\Offer;
use App\Models\Product;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\PromptEnhancement\PromptEnhancementService;
use App\Services\VideoGeneration\GeneratedVideoAttacher;
use App\Services\VideoGeneration\VideoAttachmentException;
use App\Services\VideoGeneration\VideoGovernanceService;
use App\Services\VideoGeneration\VideoProviderResolver;
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
use Livewire\Attributes\Url;

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
 * The shared ✨ Enhance rewriter (Phase 2) works exactly as on Image
 * Generation, with `video_generation.*` guides. It is hidden when the picked
 * provider never sends the prompt (Stability image-to-video).
 *
 * A finished video can be attached (Phase 3) to a product page or an
 * offer's hero through GeneratedVideoAttacher, starred, or copied to the
 * company's Google Drive (Phase 4). The Video Library's "Reuse" link opens
 * this page with `?from=<id>` to prefill the form. Phase 5 governance
 * (VideoGovernanceService): a per-role monthly video cap is enforced before
 * anything is queued, and a role on the review list gets its videos marked
 * "pending review", which blocks attaching until a reviewer approves.
 */
class VideoGeneration extends Page
{
    use OptimizesUploadedImages;
    use SavesVideosToDrive;
    use SelectableFromMediaHub;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static ?string $cluster = AiTools::class;

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Video Generation';

    protected static ?string $title = 'Video Generation';

    protected string $view = 'filament.pages.video-generation';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * `?from=<generated_video_id>` — the Video Library's "Reuse" link. Read
     * once in mount() to prefill the form, then irrelevant.
     */
    #[Url]
    public ?string $from = null;

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

    /**
     * Blank form by default; prefilled from an existing generation (prompt,
     * settings, and the same starting image) when the Video Library links
     * here with `?from=<id>`.
     *
     * @return array<string, mixed>
     */
    protected function initialFormState(): array
    {
        $state = $this->blankFormState();
        $fromId = (int) $this->from;

        if ($fromId <= 0 || ! $this->hasSelectedCompany()) {
            return $state;
        }

        $source = GeneratedVideo::query()
            ->where('tool', GeneratedVideo::TOOL_VIDEO_GENERATION)
            ->find($fromId);

        if ($source === null) {
            return $state;
        }

        return [
            ...$state,
            'prompt' => (string) $source->prompt,
            'context' => array_key_exists((string) $source->context, GeneratedVideo::CONTEXTS) ? $source->context : 'general',
            'aspect_ratio' => array_key_exists((string) $source->aspect_ratio, GeneratedVideo::ASPECT_RATIOS)
                ? $source->aspect_ratio
                : GeneratedVideo::DEFAULT_ASPECT_RATIO,
            'duration_preset' => array_key_exists((string) $source->duration_preset, GeneratedVideo::DURATION_PRESETS)
                ? $source->duration_preset
                : GeneratedVideo::DEFAULT_DURATION_PRESET,
            'provider_profile_id' => app(VideoProviderSettingsService::class)->find(app(CompanyContext::class)->company(), $source->provider_profile_id)
                ? $source->provider_profile_id
                : $this->defaultProfileId(),
            // Same array shape the video-reference quick-pick uses (see there).
            'source_image' => filled($source->source_image_path) ? [(string) Str::uuid() => $source->source_image_path] : null,
            'source_generated_image_id' => $source->source_generated_image_id,
        ];
    }

    /** @return array<string, mixed> */
    protected function blankFormState(): array
    {
        return [
            'prompt' => '',
            'original_prompt' => null,
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
                Hidden::make('original_prompt'),
                Textarea::make('prompt')
                    ->label('Motion / scene prompt')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000)
                    ->live(onBlur: true)
                    ->helperText(fn (): ?string => $this->selectedProviderUsesPrompt()
                        ? null
                        : __('The selected provider makes the video from the starting image only — this prompt is saved with the video but not sent to it.'))
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
                    ->live()
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
            $this->enhancePromptAction(),
            Action::make('generate')
                ->label('Generate')
                ->icon(Heroicon::OutlinedSparkles)
                ->keyBindings(['mod+enter'])
                ->disabled(fn (): bool => ! $this->hasSelectedCompany() || ! $this->hasConfiguredProvider())
                ->action('generate'),
        ];
    }

    /**
     * The shared ✨ Enhance rewriter, same flow as Image Generation: shown only
     * when a super admin has set the enhancer up and the picked provider
     * actually sends the prompt. The modal shows the original next to the
     * rewrite; accepting records the pre-enhancement text as
     * `original_prompt` on the generation.
     */
    protected function enhancePromptAction(): Action
    {
        return Action::make('enhancePrompt')
            ->label(__('✨ Enhance'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(fn (): bool => $this->promptEnhancerAvailable() && $this->selectedProviderUsesPrompt())
            ->disabled(fn (): bool => blank($this->data['prompt'] ?? null))
            ->modalHeading(__('Enhance prompt'))
            ->modalDescription(__('The rewrite describes camera and scene motion for the selected provider, in your brand style. Edit it if you like, then use it — or keep your original.'))
            ->modalSubmitActionLabel(__('Use enhanced'))
            ->modalCancelActionLabel(__('Keep original'))
            ->fillForm(function (): array {
                // Read the live state, not form->getState(): that validates the
                // whole form, and the starting image is usually not picked yet.
                $raw = trim((string) ($this->data['prompt'] ?? ''));

                try {
                    $enhanced = app(PromptEnhancementService::class)->enhance(
                        $raw,
                        $this->currentContextKey(),
                        $this->selectedProviderApiFormat(),
                        app(CompanyContext::class)->company(),
                    );
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title(__('Could not enhance the prompt'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    $enhanced = $raw;
                }

                return ['original' => $raw, 'enhanced' => $enhanced];
            })
            ->schema([
                Textarea::make('original')
                    ->label(__('Your prompt'))
                    ->rows(3)
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('enhanced')
                    ->label(__('Enhanced'))
                    ->rows(6)
                    ->required()
                    ->maxLength(2000),
            ])
            ->action(function (array $data): void {
                $enhanced = trim((string) ($data['enhanced'] ?? ''));
                $original = trim((string) ($this->data['prompt'] ?? ''));

                if ($enhanced === '' || $enhanced === $original) {
                    return;
                }

                $this->data['original_prompt'] = $original;
                $this->data['prompt'] = $enhanced;
                $this->form->fill($this->data);

                Notification::make()->title(__('Prompt updated'))->success()->send();
            });
    }

    public function promptEnhancerAvailable(): bool
    {
        return $this->hasSelectedCompany()
            && app(PromptEnhancementService::class)->isAvailable(app(CompanyContext::class)->company());
    }

    protected function currentContextKey(): string
    {
        $context = array_key_exists($this->data['context'] ?? '', GeneratedVideo::CONTEXTS)
            ? $this->data['context']
            : 'general';

        return GeneratedVideo::TOOL_VIDEO_GENERATION.'.'.$context;
    }

    /** The picked profile's api_format, or the default profile's. */
    protected function selectedProviderApiFormat(): ?string
    {
        $profiles = collect($this->providerProfiles());
        $profile = $profiles->firstWhere('id', $this->data['provider_profile_id'] ?? null)
            ?? $profiles->firstWhere('id', $this->defaultProfileId());

        return $profile['api_format'] ?? null;
    }

    /** False when the picked provider makes the video from the starting image alone (Stability). */
    public function selectedProviderUsesPrompt(): bool
    {
        $format = $this->selectedProviderApiFormat();
        $resolver = app(VideoProviderResolver::class);

        return $format === null || ! $resolver->supports($format) || $resolver->byFormat($format)->usesTextPrompt();
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

        $user = Auth::user();
        $governance = app(VideoGovernanceService::class);

        // Checked before the form is dehydrated, so a blocked request never
        // stores its uploaded starting image.
        if ($user !== null && $governance->wouldExceedCap($company, $user)) {
            Notification::make()
                ->title(__('Monthly video allowance used'))
                ->body(__('You have used your monthly video allowance for this company. It resets on the 1st.'))
                ->danger()
                ->send();

            return;
        }

        $reviewStatus = ($user !== null && $governance->roleRequiresReview($company, $user->effectiveRole()))
            ? GeneratedVideo::REVIEW_PENDING
            : GeneratedVideo::REVIEW_NOT_REQUIRED;

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
        $originalPrompt = trim((string) ($state['original_prompt'] ?? ''));

        $record = GeneratedVideo::query()->create([
            'user_id' => Auth::id(),
            'tool' => GeneratedVideo::TOOL_VIDEO_GENERATION,
            'context' => $context,
            'operation' => GeneratedVideo::OPERATION_IMAGE_TO_VIDEO,
            'prompt' => $prompt,
            'original_prompt' => ($originalPrompt !== '' && $originalPrompt !== $prompt) ? $originalPrompt : null,
            'source_image_path' => $sourcePath,
            'source_generated_image_id' => $state['source_generated_image_id'] ?: null,
            'provider_profile_id' => $profile['id'],
            'provider_label' => $profile['label'],
            'api_format' => $profile['api_format'],
            'model' => $profile['model'] ?: null,
            'aspect_ratio' => $aspectRatio,
            'duration_preset' => $durationPreset,
            'review_status' => $reviewStatus,
            'status' => GeneratedVideo::STATUS_QUEUED,
        ]);

        app(AuditLogService::class)->record('ai_video.generated', $record, null, [
            'prompt' => $prompt,
            'context' => $context,
            'provider' => $profile['label'],
            'api_format' => $profile['api_format'],
            'review_status' => $reviewStatus,
        ]);

        GenerateVideoJob::dispatch($record->getKey());

        $this->recentGenerationsCache = null;
        $this->from = null;

        $this->form->fill($this->blankFormState());

        Notification::make()
            ->title('Video generation started')
            ->body($reviewStatus === GeneratedVideo::REVIEW_PENDING
                ? __('This can take a few minutes. The video will need a reviewer’s approval before it can be put on a product or offer.')
                : 'This can take a few minutes — it will appear below and you will be notified when it is ready.')
            ->success()
            ->send();
    }

    /**
     * How many more videos the current user may generate this month, or null
     * when their role has no cap. Surfaced under the form.
     */
    public function remainingMonthlyAllowance(): ?int
    {
        $user = Auth::user();

        if ($user === null || ! $this->hasSelectedCompany()) {
            return null;
        }

        return app(VideoGovernanceService::class)->remainingThisMonth(app(CompanyContext::class)->company(), $user);
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
            ->with('linked')
            ->latest()
            ->limit(12)
            ->get();
    }

    /** Keeps the gallery polling while a video is generating or its Drive copy is uploading. */
    public function hasPendingGenerations(): bool
    {
        return $this->recentGenerations()->contains(fn (GeneratedVideo $row): bool => ! $row->isTerminal() || $row->isSavingToDrive());
    }

    // --- Phase 3: attach a finished video to a record -----------------------

    /**
     * Put a finished video on a product page. Mounted per generation from
     * the gallery with a `{ generation }` argument.
     */
    public function attachToProductAction(): Action
    {
        return Action::make('attachToProduct')
            ->label(__('Add to a product'))
            ->icon(Heroicon::OutlinedShoppingBag)
            ->modalHeading(__('Add this video to a product'))
            ->modalDescription(__('The video plays on the product page, below the photos. It replaces the product’s current video, if it has one.'))
            ->modalSubmitActionLabel(__('Attach'))
            ->schema([
                Select::make('product_id')
                    ->label(__('Product'))
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->options(fn (): array => $this->productOptions())
                    ->getSearchResultsUsing(fn (string $search): array => $this->productOptions($search)),
            ])
            ->action(function (array $arguments, array $data): void {
                $generation = $this->resolveGeneration($arguments);
                $product = $generation !== null
                    ? Product::query()->find($data['product_id'] ?? null)
                    : null;

                if ($generation === null || $product === null) {
                    Notification::make()->title(__('Could not attach the video'))->danger()->send();

                    return;
                }

                try {
                    app(GeneratedVideoAttacher::class)->attachToProduct($generation, $product);
                } catch (VideoAttachmentException $exception) {
                    Notification::make()
                        ->title(__('Could not attach the video'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                app(AuditLogService::class)->record('ai_video.attached', $generation, null, [
                    'target' => 'Product #'.$product->getKey().' — '.$product->name,
                ]);

                $this->recentGenerationsCache = null;

                Notification::make()
                    ->title(__('Added to :name', ['name' => $product->name]))
                    ->body(__('The video now plays on the product page.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Play a finished video in an offer / landing page's hero slot. Mounted
     * per generation with a `{ generation }` argument.
     */
    public function attachToOfferAction(): Action
    {
        return Action::make('attachToOffer')
            ->label(__('Use as an offer hero video'))
            ->icon(Heroicon::OutlinedRectangleGroup)
            ->modalHeading(__('Use this video in an offer’s hero'))
            ->modalDescription(__('The video plays in the landing-page hero. The cover image stays as the poster and the social share image.'))
            ->modalSubmitActionLabel(__('Set hero video'))
            ->schema([
                Select::make('offer_id')
                    ->label(__('Offer / landing page'))
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->options(fn (): array => $this->offerOptions())
                    ->getSearchResultsUsing(fn (string $search): array => $this->offerOptions($search)),
            ])
            ->action(function (array $arguments, array $data): void {
                $generation = $this->resolveGeneration($arguments);
                $offer = $generation !== null
                    ? Offer::query()->find($data['offer_id'] ?? null)
                    : null;

                if ($generation === null || $offer === null) {
                    Notification::make()->title(__('Could not attach the video'))->danger()->send();

                    return;
                }

                try {
                    app(GeneratedVideoAttacher::class)->attachToOffer($generation, $offer);
                } catch (VideoAttachmentException $exception) {
                    Notification::make()
                        ->title(__('Could not attach the video'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                app(AuditLogService::class)->record('ai_video.attached', $generation, null, [
                    'target' => 'Offer #'.$offer->getKey().' — '.$offer->title,
                ]);

                $this->recentGenerationsCache = null;

                Notification::make()
                    ->title(__('Hero video updated'))
                    ->body(__('“:title” now plays this video.', ['title' => $offer->title]))
                    ->success()
                    ->send();
            });
    }

    // --- Phase 4: favourites & Google Drive ----------------------------------

    /** Star / unstar a video for the Video Library. Mounted with `{ generation }`. */
    public function toggleFavoriteAction(): Action
    {
        return Action::make('toggleFavorite')
            ->label(__('Favourite'))
            ->icon(Heroicon::OutlinedStar)
            ->action(function (array $arguments): void {
                $generation = $this->resolveGeneration($arguments);

                if ($generation === null) {
                    return;
                }

                $generation->forceFill(['is_favorite' => ! $generation->is_favorite])->save();

                $this->recentGenerationsCache = null;

                Notification::make()
                    ->title($generation->is_favorite ? __('Added to favourites') : __('Removed from favourites'))
                    ->success()
                    ->send();
            });
    }

    /** Copy a finished video to the company's Google Drive folder. Mounted with `{ generation }`. */
    public function saveToDriveAction(): Action
    {
        return Action::make('saveToDrive')
            ->label(__('Save to Google Drive'))
            ->icon(Heroicon::OutlinedCloudArrowUp)
            ->action(function (array $arguments): void {
                $generation = $this->resolveGeneration($arguments);

                if ($generation === null) {
                    Notification::make()->title(__('Could not save to Google Drive'))->danger()->send();

                    return;
                }

                $this->queueDriveSave($generation);

                $this->recentGenerationsCache = null;
            });
    }

    /**
     * Resolve a GeneratedVideo from a mounted action's `generation` argument,
     * scoped to the current user's own rows (and, through CompanyScope, the
     * selected company).
     */
    protected function resolveGeneration(array $arguments): ?GeneratedVideo
    {
        $id = (int) ($arguments['generation'] ?? 0);

        if ($id <= 0 || ! $this->hasSelectedCompany()) {
            return null;
        }

        return GeneratedVideo::query()
            ->where('user_id', Auth::id())
            ->where('tool', GeneratedVideo::TOOL_VIDEO_GENERATION)
            ->find($id);
    }

    /** @return array<int, string> */
    protected function productOptions(string $search = ''): array
    {
        if (! $this->hasSelectedCompany()) {
            return [];
        }

        return Product::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(30)
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> */
    protected function offerOptions(string $search = ''): array
    {
        if (! $this->hasSelectedCompany()) {
            return [];
        }

        return Offer::query()
            ->landingPages()
            ->when($search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->limit(30)
            ->pluck('title', 'id')
            ->all();
    }
}
