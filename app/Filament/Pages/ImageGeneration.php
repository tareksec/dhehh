<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\AiTools;
use App\Filament\Concerns\OptimizesUploadedImages;
use App\Jobs\GenerateImageJob;
use App\Models\GeneratedImage;
use App\Models\Offer;
use App\Models\Product;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\ImageGeneration\GeneratedImageAttacher;
use App\Services\ImageGeneration\ImageAttachmentException;
use App\Services\ImageGeneration\ImageGovernanceService;
use App\Services\ImageGeneration\ImageProviderResolver;
use App\Services\ImageGeneration\ImageProviderSettingsService;
use App\Services\PromptEnhancement\PromptEnhancementService;
use App\Support\CompanyMedia;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * The Image Generation tool. The prompt form + generation controls live
 * here; the chosen provider profile and its API key are configured on
 * Settings → Integrations → Image Providers. Generation runs in
 * GenerateImageJob (queued) and the results gallery polls itself until every
 * pending row is finished.
 *
 * Prompt, context, aspect ratio, variation count, provider pick, queued
 * generation, polled gallery, per-image download, and the shared ✨ Enhance
 * prompt rewriter (before/after, always explicit). Each finished image can
 * then be attached (Phase 3) to a product's featured/gallery image or an
 * offer's landing-page cover banner, flagged as a video reference, fed back
 * in as an image-to-image reference ("Regenerate"), or run through
 * background removal — the last two only where a configured provider
 * supports them (OpenAI/custom for image-to-image, Stability for both).
 *
 * The form also takes an optional uploaded reference image: when one is
 * present, "Generate" runs image-to-image or background removal on that
 * upload instead of a from-scratch generation (Phase 4 follow-up).
 */
class ImageGeneration extends Page
{
    use OptimizesUploadedImages;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $cluster = AiTools::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Image Generation';

    protected static ?string $title = 'Image Generation';

    protected string $view = 'filament.pages.image-generation';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * `?from=<generated_image_id>` — the library's "Reuse prompt" link. Read
     * once in mount() to prefill the form, then irrelevant.
     */
    #[Url]
    public ?string $from = null;

    /** Per-request memo so one render does not query the same 12 rows three times. */
    private ?Collection $recentGenerationsCache = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('ai_tools.image_generation') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill($this->initialFormState());
    }

    /**
     * Blank form by default; prefilled from an existing generation when the
     * Image Library links here with `?from=<id>` ("Reuse prompt").
     *
     * @return array<string, mixed>
     */
    protected function initialFormState(): array
    {
        $state = [
            'prompt' => '',
            'original_prompt' => null,
            'context' => 'general',
            'aspect_ratio' => GeneratedImage::DEFAULT_ASPECT_RATIO,
            'variations' => 1,
            'provider_profile_id' => $this->defaultProfileId(),
            'reference_image' => null,
            'reference_operation' => GeneratedImage::OPERATION_IMAGE_TO_IMAGE,
            'reference_strength' => 'balanced',
        ];

        $fromId = (int) $this->from;

        if ($fromId <= 0 || ! $this->hasSelectedCompany()) {
            return $state;
        }

        $source = GeneratedImage::query()
            ->where('tool', GeneratedImage::TOOL_IMAGE_GENERATION)
            ->find($fromId);

        if ($source === null) {
            return $state;
        }

        return [
            ...$state,
            'prompt' => $source->prompt,
            'context' => array_key_exists($source->context, GeneratedImage::CONTEXTS) ? $source->context : 'general',
            'aspect_ratio' => array_key_exists((string) $source->aspect_ratio, GeneratedImage::ASPECT_RATIOS)
                ? $source->aspect_ratio
                : GeneratedImage::DEFAULT_ASPECT_RATIO,
            'variations' => max(1, min((int) $source->variations_requested, GeneratedImage::MAX_VARIATIONS)),
            'provider_profile_id' => app(ImageProviderSettingsService::class)->find(app(CompanyContext::class)->company(), $source->provider_profile_id)
                ? $source->provider_profile_id
                : $this->defaultProfileId(),
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

        return app(ImageProviderSettingsService::class)->list(app(CompanyContext::class)->company());
    }

    public function hasConfiguredProvider(): bool
    {
        return collect($this->providerProfiles())
            ->contains(fn (array $profile): bool => filled($profile['model']) && ($profile['has_api_key'] || $profile['api_format'] === 'custom'));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Hidden::make('original_prompt'),
                Textarea::make('prompt')
                    ->label('Prompt')
                    ->required(fn (Get $get): bool => ! $this->isBackgroundRemovalUpload($get))
                    ->rows(4)
                    ->maxLength(2000)
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): ?string => $this->isBackgroundRemovalUpload($get)
                        ? 'Not needed for background removal.'
                        : null)
                    ->placeholder('A studio product photo of a matte-black stainless steel water bottle on a warm neutral background, soft daylight, subtle shadow')
                    ->columnSpanFull(),
                Select::make('context')
                    ->label('What is this for?')
                    ->options(GeneratedImage::CONTEXTS)
                    ->default('general')
                    ->required()
                    ->native(false),
                Select::make('aspect_ratio')
                    ->label('Aspect ratio')
                    ->options(collect(GeneratedImage::ASPECT_RATIOS)->map(fn (array $r): string => $r['label'])->all())
                    ->default(GeneratedImage::DEFAULT_ASPECT_RATIO)
                    ->required()
                    ->native(false),
                Select::make('variations')
                    ->label('How many images?')
                    ->options(collect(range(1, GeneratedImage::MAX_VARIATIONS))
                        ->mapWithKeys(fn (int $n): array => [$n => $n === 1 ? '1 image' : "{$n} images"])
                        ->all())
                    ->default(1)
                    ->required()
                    ->native(false),
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
                        : __('No image provider is configured yet. A super admin can add one on Settings → Integrations → Image Providers.')),

                FileUpload::make('reference_image')
                    ->label('Start from an image (optional)')
                    ->helperText('Upload a photo to reimagine with your prompt, or to remove its background — instead of generating from scratch. The provider that runs it is picked automatically.')
                    ->image()
                    ->tap(static::browserImagePrecompression())
                    ->disk(fn (): string => CompanyMedia::publicDiskName())
                    ->directory(fn (): string => CompanyMedia::publicDirectory('ai-reference-uploads'))
                    ->fetchFileInformation(false)
                    ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                    ->getOpenableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                    ->getDownloadableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                    ->saveUploadedFileUsing(static::optimizeImageUpload())
                    ->visible(fn (): bool => $this->canEditImages())
                    ->live()
                    ->columnSpanFull(),
                Select::make('reference_operation')
                    ->label('What to do with it')
                    ->options(fn (): array => $this->referenceOperationOptions())
                    ->default(GeneratedImage::OPERATION_IMAGE_TO_IMAGE)
                    ->native(false)
                    ->required(fn (Get $get): bool => filled($get('reference_image')))
                    ->visible(fn (Get $get): bool => $this->canEditImages() && filled($get('reference_image')))
                    ->live(),
                Select::make('reference_strength')
                    ->label('How much to change')
                    ->options([
                        'subtle' => 'Subtle — keep it close to the original',
                        'balanced' => 'Balanced',
                        'strong' => 'Strong — reinterpret freely',
                    ])
                    ->default('balanced')
                    ->native(false)
                    ->visible(fn (Get $get): bool => $this->canEditImages()
                        && filled($get('reference_image'))
                        && ($get('reference_operation') ?? GeneratedImage::OPERATION_IMAGE_TO_IMAGE) === GeneratedImage::OPERATION_IMAGE_TO_IMAGE),
            ]);
    }

    /** True when the form currently holds an uploaded reference set to "remove background". */
    protected function isBackgroundRemovalUpload(Get $get): bool
    {
        return filled($get('reference_image'))
            && ($get('reference_operation') ?? null) === GeneratedImage::OPERATION_BACKGROUND_REMOVAL;
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
     * The shared ✨ Enhance rewriter. Only shown when a super admin has set
     * the enhancer up (Settings → Integrations → Prompt Enhancer). Always explicit: the
     * modal shows the original next to the rewrite; the user edits/accepts or
     * cancels. Accepting records the pre-enhancement text as
     * `original_prompt` on the generation.
     */
    protected function enhancePromptAction(): Action
    {
        return Action::make('enhancePrompt')
            ->label('✨ Enhance')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(fn (): bool => $this->hasSelectedCompany() && $this->promptEnhancerAvailable())
            ->disabled(fn (): bool => blank($this->data['prompt'] ?? null))
            ->modalHeading('Enhance prompt')
            ->modalDescription('The rewrite adapts to the selected provider and your brand style. Edit it if you like, then use it — or keep your original.')
            ->modalSubmitActionLabel('Use enhanced')
            ->modalCancelActionLabel('Keep original')
            ->fillForm(function (): array {
                $raw = trim((string) ($this->form->getState()['prompt'] ?? ''));

                try {
                    $enhanced = app(PromptEnhancementService::class)->enhance(
                        $raw,
                        $this->currentContextKey(),
                        $this->selectedProviderApiFormat(),
                        app(CompanyContext::class)->company(),
                    );
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Could not enhance the prompt')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    $enhanced = $raw;
                }

                return ['original' => $raw, 'enhanced' => $enhanced];
            })
            ->schema([
                Textarea::make('original')
                    ->label('Your prompt')
                    ->rows(3)
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('enhanced')
                    ->label('Enhanced')
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

                Notification::make()->title('Prompt updated')->success()->send();
            });
    }

    public function promptEnhancerAvailable(): bool
    {
        return $this->hasSelectedCompany()
            && app(PromptEnhancementService::class)->isAvailable(app(CompanyContext::class)->company());
    }

    protected function currentContextKey(): string
    {
        $context = array_key_exists($this->data['context'] ?? '', GeneratedImage::CONTEXTS)
            ? $this->data['context']
            : 'general';

        return GeneratedImage::TOOL_IMAGE_GENERATION.'.'.$context;
    }

    protected function selectedProviderApiFormat(): ?string
    {
        $id = $this->data['provider_profile_id'] ?? null;

        return collect($this->providerProfiles())->firstWhere('id', $id)['api_format'] ?? null;
    }

    public function generate(): void
    {
        if (! $this->hasSelectedCompany()) {
            Notification::make()->title('Select a company first')->warning()->send();

            return;
        }

        if ($this->canEditImages() && filled($this->data['reference_image'] ?? null)) {
            $this->generateFromUploadedReference();

            return;
        }

        $state = $this->form->getState();
        $company = app(CompanyContext::class)->company();
        $settings = app(ImageProviderSettingsService::class);

        $profile = $settings->find($company, $state['provider_profile_id'] ?? null)
            ?? $settings->default($company);

        if ($profile === null || ! $settings->isConfigured($profile)) {
            throw ValidationException::withMessages([
                'data.provider_profile_id' => __('Pick a provider that has a model and API key configured on Settings → Integrations → Image Providers.'),
            ]);
        }

        $aspectRatio = array_key_exists($state['aspect_ratio'] ?? '', GeneratedImage::ASPECT_RATIOS)
            ? $state['aspect_ratio']
            : GeneratedImage::DEFAULT_ASPECT_RATIO;

        $context = array_key_exists($state['context'] ?? '', GeneratedImage::CONTEXTS)
            ? $state['context']
            : 'general';

        $variations = max(1, min((int) ($state['variations'] ?? 1), GeneratedImage::MAX_VARIATIONS));

        $user = Auth::user();
        $governance = app(ImageGovernanceService::class);

        if ($user !== null && $governance->wouldExceedCap($company, $user, $variations)) {
            $remaining = $governance->remainingThisMonth($company, $user);

            throw ValidationException::withMessages([
                'data.variations' => $remaining === 0
                    ? 'You have used your monthly image allowance for this company. It resets on the 1st.'
                    : "That would go over your monthly image allowance — you have {$remaining} left this month.",
            ]);
        }

        $finalPrompt = trim((string) $state['prompt']);
        $originalPrompt = trim((string) ($state['original_prompt'] ?? ''));

        $reviewStatus = ($user !== null && $governance->roleRequiresReview($company, $user->effectiveRole()))
            ? GeneratedImage::REVIEW_PENDING
            : GeneratedImage::REVIEW_NOT_REQUIRED;

        $record = GeneratedImage::query()->create([
            'user_id' => Auth::id(),
            'tool' => GeneratedImage::TOOL_IMAGE_GENERATION,
            'context' => $context,
            'original_prompt' => ($originalPrompt !== '' && $originalPrompt !== $finalPrompt) ? $originalPrompt : null,
            'prompt' => $finalPrompt,
            'provider_profile_id' => $profile['id'],
            'provider_label' => $profile['label'],
            'api_format' => $profile['api_format'],
            'model' => $profile['model'],
            'aspect_ratio' => $aspectRatio,
            'variations_requested' => $variations,
            'is_video_reference' => $context === 'video_reference',
            'review_status' => $reviewStatus,
            'status' => GeneratedImage::STATUS_QUEUED,
        ]);

        app(AuditLogService::class)->record('ai_image.generated', $record, null, [
            'prompt' => $finalPrompt,
            'context' => $context,
            'provider' => $profile['label'],
            'api_format' => $profile['api_format'],
            'model' => $profile['model'],
            'variations' => $variations,
            'review_status' => $reviewStatus,
        ]);

        GenerateImageJob::dispatch($record->getKey());

        $this->recentGenerationsCache = null;

        $this->form->fill([
            'prompt' => '',
            'original_prompt' => null,
            'context' => $context,
            'aspect_ratio' => $aspectRatio,
            'variations' => $record->variations_requested,
            'provider_profile_id' => $profile['id'],
            'reference_image' => null,
            'reference_operation' => GeneratedImage::OPERATION_IMAGE_TO_IMAGE,
            'reference_strength' => 'balanced',
        ]);

        Notification::make()
            ->title('Generation started')
            ->body($reviewStatus === GeneratedImage::REVIEW_PENDING
                ? 'Your image is being generated. It will need a reviewer’s approval before it can be attached to a product or offer.'
                : 'Your image is being generated — it will appear below and you will be notified when it is ready.')
            ->success()
            ->send();
    }

    /**
     * How many more images the current user may generate this month, or null
     * when their role has no cap. Surfaced under the form.
     */
    public function remainingMonthlyAllowance(): ?int
    {
        $user = Auth::user();

        if ($user === null || ! $this->hasSelectedCompany()) {
            return null;
        }

        return app(ImageGovernanceService::class)->remainingThisMonth(app(CompanyContext::class)->company(), $user);
    }

    /** @return Collection<int, GeneratedImage> */
    public function recentGenerations(): Collection
    {
        if ($this->recentGenerationsCache !== null) {
            return $this->recentGenerationsCache;
        }

        if (! $this->hasSelectedCompany()) {
            return $this->recentGenerationsCache = collect();
        }

        return $this->recentGenerationsCache = GeneratedImage::query()
            ->where('user_id', Auth::id())
            ->where('tool', GeneratedImage::TOOL_IMAGE_GENERATION)
            ->with('linked')
            ->latest()
            ->limit(12)
            ->get();
    }

    public function hasPendingGenerations(): bool
    {
        return $this->recentGenerations()->contains(fn (GeneratedImage $row): bool => ! $row->isTerminal());
    }

    protected function defaultProfileId(): ?string
    {
        if (! $this->hasSelectedCompany()) {
            return null;
        }

        return app(ImageProviderSettingsService::class)
            ->default(app(CompanyContext::class)->company())['id'] ?? null;
    }

    // --- Phase 3: attach a finished image to a record -----------------------

    /**
     * Add one finished output to a product — its featured image (also what
     * Meta ad creatives use) or its gallery. Mounted per image from the
     * gallery with `{ generation, image }` arguments.
     */
    public function attachToProductAction(): Action
    {
        return Action::make('attachToProduct')
            ->label('Add to a product')
            ->icon(Heroicon::OutlinedShoppingBag)
            ->modalHeading('Add this image to a product')
            ->modalSubmitActionLabel('Attach')
            ->schema([
                Select::make('product_id')
                    ->label('Product')
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->options(fn (): array => $this->productOptions())
                    ->getSearchResultsUsing(fn (string $search): array => $this->productOptions($search)),
                Select::make('slot')
                    ->label('Use as')
                    ->required()
                    ->native(false)
                    ->default(GeneratedImageAttacher::PRODUCT_SLOT_GALLERY)
                    ->options([
                        GeneratedImageAttacher::PRODUCT_SLOT_GALLERY => 'Add to the product gallery',
                        GeneratedImageAttacher::PRODUCT_SLOT_FEATURED => 'Set as the featured image',
                    ])
                    ->helperText('The featured image is also the photo Meta ad creatives use for this product.'),
            ])
            ->action(function (array $arguments, array $data): void {
                $generation = $this->resolveGeneration($arguments);
                $product = $generation !== null
                    ? Product::query()->find($data['product_id'] ?? null)
                    : null;

                if ($generation === null || $product === null) {
                    Notification::make()->title('Could not attach the image')->danger()->send();

                    return;
                }

                try {
                    $landedIn = app(GeneratedImageAttacher::class)->attachToProduct(
                        $generation,
                        (int) ($arguments['image'] ?? 0),
                        $product,
                        (string) ($data['slot'] ?? GeneratedImageAttacher::PRODUCT_SLOT_GALLERY),
                    );
                } catch (ImageAttachmentException $exception) {
                    Notification::make()
                        ->title('Could not attach the image')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                app(AuditLogService::class)->record('ai_image.attached', $generation, null, [
                    'target' => 'Product #'.$product->getKey().' — '.$product->name,
                    'slot' => $landedIn,
                    'image_index' => (int) ($arguments['image'] ?? 0),
                ]);

                $this->recentGenerationsCache = null;

                Notification::make()
                    ->title('Added to '.$product->name)
                    ->body('The image is now on the product '.$landedIn.'.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Set one finished output as an offer / landing page's cover banner.
     * Mounted per image with `{ generation, image }` arguments.
     */
    public function attachToOfferAction(): Action
    {
        return Action::make('attachToOffer')
            ->label('Set as an offer banner')
            ->icon(Heroicon::OutlinedRectangleGroup)
            ->modalHeading('Use this image as an offer’s cover banner')
            ->modalDescription('Replaces the offer’s current cover image — the landing-page hero banner and social share image.')
            ->modalSubmitActionLabel('Set banner')
            ->schema([
                Select::make('offer_id')
                    ->label('Offer / landing page')
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
                    Notification::make()->title('Could not attach the image')->danger()->send();

                    return;
                }

                try {
                    app(GeneratedImageAttacher::class)->attachToOffer(
                        $generation,
                        (int) ($arguments['image'] ?? 0),
                        $offer,
                    );
                } catch (ImageAttachmentException $exception) {
                    Notification::make()
                        ->title('Could not attach the image')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                app(AuditLogService::class)->record('ai_image.attached', $generation, null, [
                    'target' => 'Offer #'.$offer->getKey().' — '.$offer->title,
                    'slot' => 'cover banner',
                    'image_index' => (int) ($arguments['image'] ?? 0),
                ]);

                $this->recentGenerationsCache = null;

                Notification::make()
                    ->title('Cover banner updated')
                    ->body('“'.$offer->title.'” now uses this image.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Flag the whole generation as a reference image for the future AI Video
     * Generation tool. Mounted per generation with a `{ generation }`
     * argument.
     */
    public function useAsVideoReferenceAction(): Action
    {
        return Action::make('useAsVideoReference')
            ->label('Mark as video reference')
            ->icon(Heroicon::OutlinedFilm)
            ->requiresConfirmation()
            ->modalHeading('Mark as a video reference')
            ->modalDescription('Flags this generation for the upcoming AI Video Generation tool. It stays in your library and keeps every other use.')
            ->modalSubmitActionLabel('Mark as reference')
            ->action(function (array $arguments): void {
                $generation = $this->resolveGeneration($arguments);

                if ($generation === null) {
                    Notification::make()->title('Could not update the generation')->danger()->send();

                    return;
                }

                app(GeneratedImageAttacher::class)->markAsVideoReference($generation);

                $this->recentGenerationsCache = null;

                Notification::make()->title('Marked as a video reference')->success()->send();
            });
    }

    /**
     * Star / unstar a generation for the Image Library. Mounted per
     * generation with a `{ generation }` argument.
     */
    public function toggleFavoriteAction(): Action
    {
        return Action::make('toggleFavorite')
            ->label('Favourite')
            ->icon(Heroicon::OutlinedStar)
            ->action(function (array $arguments): void {
                $generation = $this->resolveGeneration($arguments);

                if ($generation === null) {
                    return;
                }

                $generation->forceFill(['is_favorite' => ! $generation->is_favorite])->save();

                $this->recentGenerationsCache = null;

                Notification::make()
                    ->title($generation->is_favorite ? 'Added to favourites' : 'Removed from favourites')
                    ->success()
                    ->send();
            });
    }

    // --- Phase 4: image-to-image & background removal ----------------------

    /** Formats among THIS company's configured providers that can run an operation. */
    public function canRegenerateFromImage(): bool
    {
        return $this->hasSelectedCompany()
            && $this->configuredProfileSupporting(GeneratedImage::OPERATION_IMAGE_TO_IMAGE) !== null;
    }

    public function canRemoveBackground(): bool
    {
        return $this->hasSelectedCompany()
            && $this->configuredProfileSupporting(GeneratedImage::OPERATION_BACKGROUND_REMOVAL) !== null;
    }

    /**
     * "Regenerate" — feed one finished output back in as a reference, guided
     * by a (possibly edited) prompt and a change strength.
     */
    public function regenerateFromImageAction(): Action
    {
        return Action::make('regenerateFromImage')
            ->label('Regenerate')
            ->icon(Heroicon::OutlinedArrowPath)
            ->modalHeading('Regenerate from this image')
            ->modalDescription('Uses this image plus your prompt as the starting point. A stronger setting changes more of it.')
            ->modalSubmitActionLabel('Regenerate')
            ->fillForm(function (array $arguments): array {
                $source = $this->resolveGeneration($arguments);

                return ['prompt' => $source?->prompt ?? '', 'strength' => 'balanced', 'variations' => 1];
            })
            ->schema([
                Textarea::make('prompt')->label('Prompt')->required()->rows(3)->maxLength(2000),
                Select::make('strength')
                    ->label('How much to change')
                    ->native(false)
                    ->required()
                    ->default('balanced')
                    ->options([
                        'subtle' => 'Subtle — keep it close to the original',
                        'balanced' => 'Balanced',
                        'strong' => 'Strong — reinterpret freely',
                    ]),
                Select::make('variations')
                    ->label('How many images?')
                    ->native(false)
                    ->required()
                    ->default(1)
                    ->options(collect(range(1, GeneratedImage::MAX_VARIATIONS))
                        ->mapWithKeys(fn (int $n): array => [$n => $n === 1 ? '1 image' : "{$n} images"])
                        ->all()),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->startDerivedGeneration(
                    $arguments,
                    GeneratedImage::OPERATION_IMAGE_TO_IMAGE,
                    trim((string) ($data['prompt'] ?? '')),
                    (int) ($data['variations'] ?? 1),
                    $this->strengthValue($data['strength'] ?? 'balanced'),
                );
            });
    }

    /** One-click background removal of a finished output (new image, original kept). */
    public function removeBackgroundAction(): Action
    {
        return Action::make('removeBackground')
            ->label('Remove background')
            ->icon(Heroicon::OutlinedScissors)
            ->requiresConfirmation()
            ->modalHeading('Remove the background')
            ->modalDescription('Creates a new image with the background made transparent. The original stays untouched.')
            ->modalSubmitActionLabel('Remove background')
            ->action(fn (array $arguments) => $this->startDerivedGeneration(
                $arguments,
                GeneratedImage::OPERATION_BACKGROUND_REMOVAL,
                '',
                1,
            ));
    }

    /**
     * @return array<string, mixed>|null a configured profile (from list())
     *                                   whose adapter supports $operation
     */
    protected function configuredProfileSupporting(string $operation): ?array
    {
        $formats = app(ImageProviderResolver::class)->formatsSupporting($operation);

        return collect($this->providerProfiles())
            ->first(fn (array $p): bool => in_array($p['api_format'], $formats, true)
                && filled($p['model'])
                && ($p['has_api_key'] || $p['api_format'] === 'custom'));
    }

    /** At least one configured provider can run image-to-image or background removal. */
    public function canEditImages(): bool
    {
        return $this->canRegenerateFromImage() || $this->canRemoveBackground();
    }

    /**
     * The edit operations a configured provider can actually run — the option
     * list for the form's "What to do with it" select.
     *
     * @return array<string, string>
     */
    public function referenceOperationOptions(): array
    {
        return collect([
            GeneratedImage::OPERATION_IMAGE_TO_IMAGE => 'Reimagine it from my prompt',
            GeneratedImage::OPERATION_BACKGROUND_REMOVAL => 'Just remove its background',
        ])
            ->filter(fn (string $label, string $operation): bool => $this->configuredProfileSupporting($operation) !== null)
            ->all();
    }

    /**
     * "Generate" with a reference image uploaded on the form: run image-to-image
     * or background removal on the upload instead of a from-scratch generation.
     * Cap / provider errors surface as form errors (consistent with generate()).
     */
    protected function generateFromUploadedReference(): void
    {
        $operation = in_array($this->data['reference_operation'] ?? null, [
            GeneratedImage::OPERATION_IMAGE_TO_IMAGE,
            GeneratedImage::OPERATION_BACKGROUND_REMOVAL,
        ], true)
            ? $this->data['reference_operation']
            : GeneratedImage::OPERATION_IMAGE_TO_IMAGE;

        $profile = $this->configuredProfileSupporting($operation);

        if ($profile === null) {
            throw ValidationException::withMessages([
                'data.reference_operation' => __('No configured provider can do that. A super admin can add a supporting provider on Settings → Integrations → Image Providers.'),
            ]);
        }

        $company = app(CompanyContext::class)->company();
        $user = Auth::user();
        $governance = app(ImageGovernanceService::class);

        $variations = $operation === GeneratedImage::OPERATION_BACKGROUND_REMOVAL
            ? 1
            : max(1, min((int) ($this->data['variations'] ?? 1), GeneratedImage::MAX_VARIATIONS));

        if ($user !== null && $governance->wouldExceedCap($company, $user, $variations)) {
            $remaining = $governance->remainingThisMonth($company, $user);

            throw ValidationException::withMessages([
                'data.variations' => $remaining === 0
                    ? 'You have used your monthly image allowance for this company. It resets on the 1st.'
                    : "That would go over your monthly image allowance — you have {$remaining} left this month.",
            ]);
        }

        // Dehydrate the form: this persists the upload through
        // optimizeImageUpload() and turns `reference_image` into its stored path.
        $state = $this->form->getState();
        $referencePath = $this->storedReferencePath($state['reference_image'] ?? null);

        if ($referencePath === null) {
            Notification::make()->title('Upload a reference image first')->warning()->send();

            return;
        }

        $strength = $operation === GeneratedImage::OPERATION_IMAGE_TO_IMAGE
            ? $this->strengthValue($state['reference_strength'] ?? 'balanced')
            : null;

        $context = array_key_exists($state['context'] ?? '', GeneratedImage::CONTEXTS) ? $state['context'] : 'general';
        $aspectRatio = array_key_exists($state['aspect_ratio'] ?? '', GeneratedImage::ASPECT_RATIOS)
            ? $state['aspect_ratio']
            : GeneratedImage::DEFAULT_ASPECT_RATIO;

        $this->queueDerivedGeneration(
            profile: $profile,
            operation: $operation,
            prompt: trim((string) ($state['prompt'] ?? '')),
            variations: $variations,
            strength: $strength,
            referencePath: $referencePath,
            context: $context,
            aspectRatio: $aspectRatio,
            auditContext: ['source' => 'uploaded reference'],
        );

        $this->form->fill([
            'prompt' => '',
            'original_prompt' => null,
            'context' => $context,
            'aspect_ratio' => $aspectRatio,
            'variations' => 1,
            'provider_profile_id' => $this->defaultProfileId(),
            'reference_image' => null,
            'reference_operation' => GeneratedImage::OPERATION_IMAGE_TO_IMAGE,
            'reference_strength' => 'balanced',
        ]);
    }

    /** Map the "how much to change" pick to an image-to-image strength (0 keeps the source, 1 ignores it). */
    protected function strengthValue(?string $choice): float
    {
        return match ($choice) {
            'subtle' => 0.35,
            'strong' => 0.85,
            default => 0.6,
        };
    }

    /** Normalize a FileUpload's dehydrated state (string or [id => path]) to a path. */
    protected function storedReferencePath(mixed $value): ?string
    {
        $path = is_array($value) ? (reset($value) ?: null) : $value;

        return filled($path) ? (string) $path : null;
    }

    protected function startDerivedGeneration(array $arguments, string $operation, string $prompt, int $variations, ?float $strength = null): void
    {
        $source = $this->resolveGeneration($arguments);
        $sourcePath = collect($source?->output_paths ?? [])->values()->get((int) ($arguments['image'] ?? 0));

        if ($source === null || $source->status !== GeneratedImage::STATUS_COMPLETED || blank($sourcePath)) {
            Notification::make()->title('That image is not available')->danger()->send();

            return;
        }

        $profile = $this->configuredProfileSupporting($operation);

        if ($profile === null) {
            Notification::make()
                ->title('No configured provider can do that')
                ->body(__('A super admin can add a supporting provider on Settings → Integrations → Image Providers.'))
                ->danger()
                ->send();

            return;
        }

        $company = app(CompanyContext::class)->company();
        $user = Auth::user();
        $governance = app(ImageGovernanceService::class);
        $variations = max(1, min($variations, GeneratedImage::MAX_VARIATIONS));

        if ($user !== null && $governance->wouldExceedCap($company, $user, $variations)) {
            $remaining = $governance->remainingThisMonth($company, $user);

            Notification::make()
                ->title('Monthly image allowance reached')
                ->body($remaining === 0 ? 'It resets on the 1st.' : "You have {$remaining} left this month.")
                ->warning()
                ->send();

            return;
        }

        $this->queueDerivedGeneration(
            profile: $profile,
            operation: $operation,
            prompt: $prompt,
            variations: $variations,
            strength: $strength,
            referencePath: (string) $sourcePath,
            context: $source->context,
            aspectRatio: $source->aspect_ratio ?: GeneratedImage::DEFAULT_ASPECT_RATIO,
            auditContext: ['source_generation_id' => (string) $source->getKey()],
        );
    }

    /**
     * Shared tail of every image-to-image / background-removal run (whether the
     * source is a finished output or an uploaded reference): create the queued
     * row, audit it, dispatch the job, refresh the gallery, notify. The caller
     * has already resolved $profile, clamped $variations, and checked the cap.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $auditContext
     */
    protected function queueDerivedGeneration(
        array $profile,
        string $operation,
        string $prompt,
        int $variations,
        ?float $strength,
        string $referencePath,
        string $context,
        string $aspectRatio,
        array $auditContext = [],
    ): void {
        $company = app(CompanyContext::class)->company();
        $user = Auth::user();

        $reviewStatus = ($user !== null && app(ImageGovernanceService::class)->roleRequiresReview($company, $user->effectiveRole()))
            ? GeneratedImage::REVIEW_PENDING
            : GeneratedImage::REVIEW_NOT_REQUIRED;

        $record = GeneratedImage::query()->create([
            'user_id' => Auth::id(),
            'tool' => GeneratedImage::TOOL_IMAGE_GENERATION,
            'context' => array_key_exists($context, GeneratedImage::CONTEXTS) ? $context : 'general',
            'operation' => $operation,
            'prompt' => $prompt,
            'reference_image_path' => $referencePath,
            'provider_profile_id' => $profile['id'],
            'provider_label' => $profile['label'],
            'api_format' => $profile['api_format'],
            'model' => $profile['model'],
            'aspect_ratio' => $aspectRatio ?: GeneratedImage::DEFAULT_ASPECT_RATIO,
            'variations_requested' => $operation === GeneratedImage::OPERATION_BACKGROUND_REMOVAL ? 1 : max(1, $variations),
            'review_status' => $reviewStatus,
            'status' => GeneratedImage::STATUS_QUEUED,
        ]);

        app(AuditLogService::class)->record('ai_image.'.$operation, $record, null, array_filter([
            'prompt' => $prompt ?: null,
            'provider' => $profile['label'],
            'strength' => $strength,
            ...$auditContext,
        ]));

        GenerateImageJob::dispatch($record->getKey(), $strength);

        $this->recentGenerationsCache = null;

        Notification::make()
            ->title($operation === GeneratedImage::OPERATION_BACKGROUND_REMOVAL ? 'Removing the background' : 'Regenerating')
            ->body($reviewStatus === GeneratedImage::REVIEW_PENDING
                ? 'It will need a reviewer’s approval before it can be attached to a product or offer.'
                : 'It will appear below and you will be notified when it is ready.')
            ->success()
            ->send();
    }

    /**
     * Resolve a GeneratedImage from a mounted action's `generation` argument,
     * scoped to the current user's own image-generation rows (and, through
     * CompanyScope, the selected company).
     */
    protected function resolveGeneration(array $arguments): ?GeneratedImage
    {
        $id = (int) ($arguments['generation'] ?? 0);

        if ($id <= 0 || ! $this->hasSelectedCompany()) {
            return null;
        }

        return GeneratedImage::query()
            ->where('user_id', Auth::id())
            ->where('tool', GeneratedImage::TOOL_IMAGE_GENERATION)
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
