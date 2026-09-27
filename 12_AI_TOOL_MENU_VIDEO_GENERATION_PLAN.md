# 12 — AI Tool Menu: Video Generation (Module Plan)

Status: **Phases 0–2 built.** The tool is live on the Tool Menu: a user can pick a starting image (upload, Media Hub, or the video-reference quick-pick), write a prompt (with ✨ Enhance for providers that use it), and get a queued, self-polling Stability/Custom async generation. Not yet wired: attach-to-Product/Offer, the Video Library, and governance (Phases 3–5 below). Written as the pre-execution reference for phased implementation in Claude Code, mirroring how `11_AI_TOOL_MENU_IMAGE_GENERATION_PLAN.md` was used for Image Generation. Owner decisions already made (see §2) — provider breadth and the Product/Offer attach scope. Everything else below is the technical design.

## 1. Overview

Video Generation is the AI Tools cluster's second tool (after Image Generation) and the first of the two slots `11_AI_TOOL_MENU_IMAGE_GENERATION_PLAN.md` deliberately reserved and left unbuilt. Its Tool Menu card already exists today as a disabled "Coming soon" tile — `App\Filament\Pages\ToolMenu::TOOLS['video_generation']` with `'page' => null`. Building this tool is largely: implement everything below, then flip that one line to point at the real page.

**In scope for v1:** image-to-video generation. A user picks an *existing* image — from the Image Library's `is_video_reference`-flagged pool (a queue Image Generation's Phase 3 built specifically for this), the wider Image Library, or the Media Hub — writes a short motion/scene prompt, picks a duration preset and aspect handling, and the tool submits an async job to a configured provider, polls it to completion, and stores the resulting clip. Finished videos can be attached to a Product or an Offer, where they get basic storefront playback.

**Explicitly out of scope for v1:** text-to-video-from-nothing (no starting image), uploading your own raw video clip as an input, and Meta Ads video ad creatives (§13).

### 1.1 Why this is a different shape than Image Generation, not a reskin

Four structural facts drive every decision below, all confirmed against the current codebase:

1. **No FFmpeg/video-processing library is installed**, and the Media Hub (`App\Models\Media`, `SelectableFromMediaHub`) is coded and documented as image-only — no thumbnailing for non-image mimes. A generated video cannot get a Hub-generated poster frame; it reuses its source image or a provider-supplied poster instead.
2. **The browser-upload pipeline is sized for images, not video, and this is a real limit.** `nginx.template.conf` sets `client_max_body_size 16M` and PHP-FPM `upload_max_filesize=12M` / `post_max_size=16M`; `docs/deployment.md` explicitly forbids routing Livewire's temp upload disk through R2 (no presigned direct-to-R2 browser path exists). This only binds *browser* uploads through PHP-FPM — never a queue worker's own server-to-server HTTP calls — which is exactly why the only way into this tool is picking an *existing* (small) image, never uploading a video file.
3. **`GenerateImageJob` is the only async-generation precedent in the app, and it is fully synchronous** — one HTTP call inline, `tries=1`, `timeout=240` (the longest timeout anywhere in this app; every other job is ≤70s or the framework default). There is no submit→poll precedent to copy. Video-generation APIs are essentially always async (submit → poll a status endpoint over 1–10+ minutes → download when ready), so §8 designs a poll loop from Laravel primitives (`retryUntil()`, `$this->release()`).
4. **Neither `Product` nor `Offer` has any video column today** (confirmed: `Product::$fillable` has `image`/`gallery_images`, `Offer::$fillable` has `cover_image`; no video field anywhere). Attach is new schema + new storefront work, not a reuse of an existing field the way image attach was.

## 2. Decisions Locked In

Owner-confirmed:

| Decision | Choice |
|---|---|
| Provider breadth | **Not locked to one named provider.** Ship Stability AI (image-to-video) as the first working adapter — it's already an integrated provider elsewhere in the app and its async API shape is a native match for the submit/poll job design (§8) — plus a **Custom** adapter (any OpenAI/Runway/Kling/etc-compatible endpoint an admin points at manually, mirroring `CustomOpenAiCompatibleProvider`'s flexibility). A second **named** commercial adapter (Runway, Kling, or similar) is an immediate near-term follow-up once the submit/poll pattern is proven against Stability — not a "someday," per the owner's explicit wish to keep provider choice open (§13(a)). |
| Attach scope | **Generate + Attach in v1.** New `products.video_path` / `offers.video_path` columns, written by a `GeneratedVideoAttacher` service, with basic storefront `<video>` playback on the product page and the offer landing page. |

Technical decisions made in this document (open to owner pushback when reviewed, not requiring a separate sign-off):

| Decision | Choice | Why |
|---|---|---|
| Data model | Dedicated `generated_videos` table + `GeneratedVideo` model, not a `tool` discriminator bolted onto `generated_images` | §3.1 |
| Input | Image-to-video only; the source is always an existing image (Image Library / video-reference pool / Media Hub), or a small *fresh image* upload through the existing `OptimizesUploadedImages` pipeline — never a video upload | §1.1(2) |
| Batch count | Always exactly 1 clip per request — no `variations` control | Video is far more expensive per unit than an image |
| Duration | A small fixed preset menu (`short`/`standard`), each adapter snaps to its nearest native duration | Mirrors `GeneratedImage::ASPECT_RATIOS`'s exact snap-to-nearest mechanism |
| Async mechanism | Laravel `retryUntil()` + self-`release()` polling — no new infra, no webhooks required from the provider | Provider-agnostic from day one; the app's existing `*WebhookController.php` pattern remains available later if a provider prefers push |
| Governance | `VideoGovernanceService` owns its own settings key; no shared abstraction with `ImageGovernanceService` or the CRM AI-budget system | This codebase's established precedent — every metered tool owns its governance independently |
| Meta Ads video creatives | Deferred, no committed timeline | `MetaAdsCreationService` is 100% image-hash-based today; adding video is a substantial standalone Meta Graph API workstream, not a small add-on (§13(e)) |

## 3. How This Fits Existing Architecture

### 3.1 Data model: dedicated `GeneratedVideo`, not a `generated_images.tool` discriminator

A new `generated_videos` table + `App\Models\GeneratedVideo` model, sibling to `GeneratedImage`, not a `tool` column added to `generated_images`:

- **The shape mismatch is structural.** `GeneratedImage::output_paths` is a JSON array because one job run can synchronously produce up to `MAX_VARIATIONS=4` images; every consumer (`outputUrls()`, `GeneratedImageAttacher::outputPath($image, $outputIndex, ...)`, the gallery's per-image `{generation, image}` action arguments) is built around "index into an array." A video row is always exactly one output — forcing that through the same column means either a permanent meaningless one-element-array wrapper or branching every method on "is this a video row," both risking regressions to the already-shipped Image Generation feature.
- **The async state machine needs columns that mean nothing for an image row** (`external_job_id`, `poll_attempts`, `next_poll_at`, `poll_deadline_at`, `submitted_at`, `duration_preset`, `duration_seconds` — full list in §6.2). Bolting these onto `generated_images` pollutes every image row with permanently-null columns.
- **This codebase already made this exact call once**: `ImageGovernanceService` and the CRM's independent AI-run budget system are deliberately separate, with no shared abstraction, because they're genuinely different production shapes. A dedicated `generated_videos` table is the same philosophy applied to the data layer.
- **Zero risk to the shipped Image Generation feature** — no migration touches `generated_images`; `GeneratedImage::scopeVideoReferences()` keeps its exact current meaning (the source-image picker pool for this tool) with no code change on that side.
- **Registration cost is trivial and already has a precedent** — `MultiCompanyIsolationTest` already lists `Media::class, GeneratedImage::class` as siblings; `GeneratedVideo::class` joins the same array.

### 3.2 Settings storage and resolver pattern — reused as-is

`companies.settings->ai_tools->{tool}` (per-company JSON, encrypted API keys via `Crypt::encryptString`) is already tool-agnostic. `VideoProviderSettingsService`/`VideoGovernanceService` each get their own leaf (`ai_tools.video_generation`, `ai_tools.video_governance`), exactly parallel to the image services. `ImageProviderResolver`'s "map a stored `api_format` string to an adapter class via `app($class)`" shape is copied verbatim as `VideoProviderResolver`.

### 3.3 Prompt Enhancer — reused with one small caveat

`PromptEnhancementService::enhance(string $rawPrompt, string $contextKey, ?string $targetApiFormat, Company $company)` and `PromptGuideRepository` are already fully tool-agnostic. `PromptGuideRepository::providerStyle($apiFormat)` in `config/prompt_guides.php` **already has `stability` and `custom` entries** (this tool's exact provider set) — no new `provider_styles` needed, only new `contexts` entries:

```
config/prompt_guides.php -> 'contexts' array gains:
  'video_generation.product_demo'
  'video_generation.ad_creative'
  'video_generation.landing_hero'
  'video_generation.general'
```

The `VideoGeneration` page's Enhance button calls the same `PromptEnhancementService::enhance(..., 'video_generation.'.$context, ...)`, identical to how `ImageGeneration::currentContextKey()` builds `'image_generation.'.$context`.

**Minor caveat:** `PromptEnhancementService::systemPrompt()`'s opening line reads "You improve prompts for AI image generation..." — imprecise for a video motion/scene prompt. Doesn't break anything (the per-context guide text does the real work), but worth a small follow-up (a `$mediaNoun` parameter defaulting to `'image'`) — tracked in Phase 2, not a blocker.

`SelectableFromMediaHub` (an additive `Action` any `FileUpload` field can add via `->hintAction()`) is reused directly on the "starting image" field — no new picker component needed.

## 4. New Filament Structure

| Page | Path | Purpose | Sort |
|---|---|---|---|
| `VideoGeneration` | `app/Filament/Pages/VideoGeneration.php` | The tool itself | 7 |
| `VideoLibrary` | `app/Filament/Pages/VideoLibrary.php` | Searchable generation history | 8 |
| `VideoProviderSettings` | `app/Filament/Pages/VideoProviderSettings.php` | Super-admin provider profiles | 9 |
| `VideoGovernance` | `app/Filament/Pages/VideoGovernance.php` | Super-admin caps/review/usage dashboard | 10 |

Sorts 7–10 sit after `ImageGovernance` (sort 6, the last currently-used slot), filling the gap the way `PromptEnhancerSettings` (sort 5) already does — no existing page's sort value changes. `AiTools` cluster needs no change (`canAccess()` is already generic).

`ToolMenu::TOOLS['video_generation']['page']` flips from `null` to `\App\Filament\Pages\VideoGeneration::class` — the literal "turn the tool on" switch.

## 5. Permissions

Current state (verified in `app/Models/User.php`): `ai_tools.video_generation` exists only in `CUSTOM_PERMISSION_OPTIONS`, labeled `'AI Tools: Video Generation (reserved — not built yet)'`; it is in **no** role's `ROLE_PERMISSIONS` array.

Changes:

1. **`CUSTOM_PERMISSION_OPTIONS`** — relabel to `'ai_tools.video_generation' => 'AI Tools: Video Generation (generate + attach)'` (drop "reserved," matching `ai_tools.image_generation`'s exact phrasing).
2. **`ROLE_PERMISSIONS['manager']`** — add `'ai_tools.video_generation'` right after `'ai_tools.image_generation'`. Super Admin needs no change (`['*']` already covers it). No other built-in role gets it by default — mirrors exactly how `ai_tools.image_generation` defaults today (only Super Admin + Manager).
3. **`ai_tools.video_generation.review` is NOT added in Phase 0/1.** `11_AI_TOOL_MENU_IMAGE_GENERATION_PLAN.md`'s own original permissions table (§5) listed only the tool key, not `.review` — that sibling was only introduced when Phase 5's approval workflow was actually built. Mirror that sequencing: add `ai_tools.video_generation.review` in Phase 5 here, not now.
4. **`database/migrations/2026_06_16_000000_seed_default_user_roles.php`** snapshots `ROLE_PERMISSIONS` into `user_roles` rows once, at migration-run time — this is what the role editor UI displays for built-in roles. A small additive migration re-runs the same snapshot logic in Phase 0 so the role editor doesn't show a stale Manager permission list (editing the PHP constant alone takes effect immediately for `hasPermission()` checks, but not for the role editor's display).
5. **`docs/roles-and-permissions.md`** — remove `ai_tools.video_generation` from the "reserved for future tools" sentence; update the AI Tools row of the Permission Matrix table; add the `.review` explanatory line in Phase 5.

## 6. Data Model

### 6.1 `companies.settings` JSON additions

```
settings.ai_tools.video_generation = [
  {
    "id": "uuid",
    "label": "Stability — image to video",
    "api_format": "stability" | "runway" | "custom",   // "runway" (or another named format) added when its adapter ships
    "base_url": "",                 // custom only: the submit endpoint
    "poll_url_template": "",        // custom only: e.g. "https://host/jobs/{job_id}"
    "model": "",                    // optional
    "cost_per_video": 0,            // admin-set estimate, mirrors cost_per_image
    "is_default": true,
    "api_key": "<encrypted>"
  }
]

settings.ai_tools.video_governance = {
  "monthly_caps": { "sales_staff": 10, ... },   // 0 = unlimited; unit TBD, see §13(d)
  "review_roles": ["sales_staff"]
}
```

`poll_url_template` is new relative to the image Custom adapter — `CustomOpenAiCompatibleProvider` only ever needed one URL because image generation is a single call; a generic async Custom adapter needs a second endpoint an admin can express with a `{job_id}` placeholder.

### 6.2 New table: `generated_videos`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `company_id` | FK, indexed | `BelongsToCompany` |
| `user_id` | FK, nullable | who generated it |
| `tool` | string, default `video_generation` | structural symmetry with `GeneratedImage.tool` |
| `context` | string, default `general` | `product_demo` \| `ad_creative` \| `landing_hero` \| `general` — no `video_reference` (doesn't apply to a video output) |
| `operation` | string, default `image_to_video` | one value today (`GeneratedVideo::OPERATION_IMAGE_TO_VIDEO`); column exists now so a future operation doesn't need an additive migration later, mirroring how `generated_images.operation` was added after the fact |
| `original_prompt` | text, nullable | pre-enhancement text |
| `prompt` | text | the motion/scene prompt actually sent |
| `source_image_path` | string, nullable | the starting frame's stored public path |
| `source_generated_image_id` | FK to `generated_images.id`, nullable, nullOnDelete | populated when the source was picked from the Image Library / video-reference pool — closes the loop `is_video_reference`/`scopeVideoReferences()` was built for |
| `provider_profile_id` / `provider_label` / `api_format` / `model` | string, nullable | mirrors the image row |
| `aspect_ratio` | string, nullable | which supported input frame the source image is fit to |
| `duration_preset` | string, default `standard` | `short` \| `standard` |
| `duration_seconds` | unsigned smallint, nullable | actual duration once known |
| `external_job_id` | string, nullable, **indexed** | the provider's async job id; its presence is the submit-vs-poll branch signal |
| `poll_attempts` | unsigned int, default 0 | status-check count |
| `submitted_at` / `next_poll_at` / `poll_deadline_at` | timestamp, nullable | async lifecycle timestamps; `poll_deadline_at` is what `retryUntil()` reads |
| `video_path` | string, nullable | the single stored output — no array, unlike `output_paths` |
| `video_mime_type` | string, nullable | recorded explicitly since container format varies by provider |
| `video_size_bytes` | unsigned bigint, nullable | ops/cost visibility |
| `thumbnail_path` | string, nullable | provider poster if returned, else `source_image_path`, else null |
| `linked_type` / `linked_id` | nullable morphs | `Product` or `Offer` |
| `is_favorite` | boolean, default false | mirrors Image Library favouriting |
| `estimated_cost` | decimal(12,4), default 0 | profile's `cost_per_video` at completion |
| `review_status` / `reviewed_by` / `reviewed_at` / `review_note` | — | mirrors `GeneratedImage::REVIEW_*` exactly |
| `status` | string, default `queued` | exactly 4 values, reused verbatim: `queued`\|`processing`\|`completed`\|`failed` (see below) |
| `error_message` | text, nullable | |
| `provider_response` | json, nullable | last submit-or-poll response snapshot (overwritten each poll) |
| `generated_at` / `created_at` / `updated_at` | — | |

Indexes: `[company_id, status]`, `[company_id, context]`, `[company_id, is_favorite]`, `[company_id, review_status]`, `external_job_id` (ops lookup by provider job id).

**Why exactly 4 statuses, not a richer submitted/polling enum:** kept identical to `GeneratedImage`'s 4 values so the Video Library's status badge reuses `ImageLibrary`'s exact color-mapping logic unchanged. The finer-grained "where in the async lifecycle" question is answered by `external_job_id`/`poll_attempts`/`next_poll_at` being present, not a new top-level status — e.g. `GeneratedVideo::isPolling(): bool` returns `status === STATUS_PROCESSING && filled($this->external_job_id)`, distinguishing "in-flight submit call" from "waiting on the provider" without a schema change.

No `variations_requested` column — count is hardcoded to 1 everywhere in the service/job layer, not modeled as data.

### 6.3 `GeneratedVideo` model — `app/Models/GeneratedVideo.php`

`use BelongsToCompany;`. Constants mirror `GeneratedImage`'s naming 1:1 wherever the concept exists:

```php
STATUS_QUEUED/PROCESSING/COMPLETED/FAILED           // identical strings
TOOL_VIDEO_GENERATION = 'video_generation';
CONTEXTS = ['product_demo'=>'Product Demo', 'ad_creative'=>'Ad Creative', 'landing_hero'=>'Landing / Offer Hero Video', 'general'=>'General'];
OPERATION_IMAGE_TO_VIDEO = 'image_to_video';
OPERATIONS = [self::OPERATION_IMAGE_TO_VIDEO => 'Image-to-video'];
REVIEW_NOT_REQUIRED/PENDING/APPROVED/REJECTED + REVIEW_STATUSES   // identical shape
ASPECT_RATIOS = ['1:1'=>[...], '16:9'=>[...], '9:16'=>[...]];      // smaller than images' 5 (see §13(b))
DURATION_PRESETS = ['short'=>['label'=>..., 'seconds'=>...], 'standard'=>[...]];
DEFAULT_DURATION_PRESET = 'standard';
```

Relations: `user()`, `reviewer()`, `linked(): MorphTo`, `sourceGeneratedImage(): BelongsTo` (new, → `GeneratedImage`).

Scopes: `scopeFavorites()`, `scopeCompleted()`, `scopePendingReview()`, `scopeThisMonth()` — mirrored exactly. No `scopeVideoReferences()` equivalent (nothing downstream needs a "videos flagged for a future tool" pool yet).

Helpers: `isAttachable()`, `isTerminal()`, `outputUrl(): ?string` (singular, via `CompanyStorageService::publicUrl()`), `thumbnailUrl(): ?string`, `isPolling(): bool`, `contextLabel()`, `operationLabel()`, `reviewStatusLabel()`, `durationLabel(): string`.

### 6.4 Product/Offer attach columns

One additive migration, `Schema::hasColumn`-guarded and fully reversible (the exact defensive pattern already used in `add_operation_to_generated_images_table.php`):

- `products.video_path` — nullable string
- `offers.video_path` — nullable string

**Important constraint on how these get written:** neither column ever gets a Filament `FileUpload::make('video_path')` on `ProductForm`/`OfferForm` — that would reintroduce the exact browser-upload-through-PHP-FPM ceiling this whole design avoids. The only writer is `GeneratedVideoAttacher` (a server-side path-string write, no file transfer through the browser), mirroring exactly how `GeneratedImageAttacher::attachToProduct()` writes `products.image` without any FileUpload field involved. `ProductForm`/`OfferForm` may at most show a read-only video preview + a "Generate a video for this product" link into the tool — never an upload control.

## 7. New Service Classes

```
app/Services/VideoGeneration/
  VideoGenerationRequest.php          // immutable DTO
  VideoSubmissionResult.php           // DTO returned by submit()
  VideoPollResult.php                 // DTO returned by poll()
  VideoGenerationClient.php           // interface: submit() + poll() + supportsOperation()
  VideoGenerationException.php
  VideoAttachmentException.php
  VideoProviderResolver.php
  VideoProviderSettingsService.php
  VideoGovernanceService.php
  GeneratedVideoAttacher.php
  Providers/
    AbstractVideoProvider.php
    StabilityVideoProvider.php
    CustomVideoProvider.php
    (RunwayVideoProvider.php or similar — near-term follow-up, §2)
```

### 7.1 Why the client interface is `submit()` + `poll()`, not one `generate()`

The single biggest structural deviation from Image Generation, and deliberate: if `VideoGenerationClient` kept one `generate()` method, an adapter would have to sleep-and-poll *inside* that call — blocking an HTTP client for minutes, defeating the whole point of moving the wait into the job's own `release()` loop. The interface instead splits along the same seam the job's state machine uses:

```php
interface VideoGenerationClient
{
    public function submit(VideoGenerationRequest $request): VideoSubmissionResult;

    // $sink: a resource (from tmpfile()). The adapter only writes to it
    // once the provider reports the job done — see §8.3 on streaming.
    public function poll(VideoGenerationRequest $request, string $externalJobId, mixed $sink): VideoPollResult;

    public function supportsOperation(string $operation): bool;
}
```

`VideoGenerationRequest` mirrors `ImageGenerationRequest`'s shape (readonly constructor properties, an `endpoint()` helper) but swaps `referenceImage`/`count` for `sourceImage` (required) and `durationPreset`; it carries no `count` field at all — the DTO is honest about the shape rather than carrying a vestigial always-1 field.

`VideoSubmissionResult { externalJobId: string, meta: array }` — deliberately minimal.

`VideoPollResult { status: 'pending'|'completed'|'failed', videoMimeType: ?string, videoSizeBytes: ?int, durationSeconds: ?float, posterUrl: ?string, errorMessage: ?string, meta: array }` — **no video-bytes field.** Carrying binary through a return value would mean the adapter already buffered the whole clip in memory. `$sink` is passed into `poll()` instead, and the adapter streams into it via `Http::sink($sink)` (Laravel's `PendingRequest` streams to the sink as bytes arrive off the socket, and this works correctly under `Http::fake()` too, so it's fully testable without a live provider) — the video's bytes never pass through a PHP string at any layer above the raw HTTP read.

### 7.2 `VideoProviderResolver`

Identical shape to `ImageProviderResolver`: `protected array $providers = ['stability' => StabilityVideoProvider::class, 'custom' => CustomVideoProvider::class]` (a named 2nd/3rd adapter's class joins this map when it ships), `byFormat()`, `supports()`, `formatsSupporting()`.

### 7.3 `VideoProviderSettingsService`

Line-for-line mirror of `ImageProviderSettingsService`: `SETTINGS_PATH = 'ai_tools.video_generation'`, `API_FORMATS` map (starts with `stability` + `custom`, grows as adapters ship), `all()`/`list()`/`default()`/`find()`/`isConfigured()`/`hasAnyConfigured()`/`save()`/`enforceSingleDefault()`. Profile shape drops `default_size` (no simple WxH concept for video), renames `cost_per_image` → `cost_per_video`, adds `poll_url_template` (custom format only, required when `api_format = custom`, mirroring how `base_url` is conditionally required today).

### 7.4 `VideoGovernanceService`

Mirrors `ImageGovernanceService` closely, deliberately re-implementing `governableRoles()` locally rather than sharing a base class — matching this codebase's established precedent that each tool's governance stays independent.

- `SETTINGS_PATH = 'ai_tools.video_governance'`
- `all()`/`save()` → `{monthly_caps, review_roles}`
- `videosUsedThisMonth(Company, User): int` — a row **count** (not a `sum('variations_requested')` — that column doesn't exist here; see §13(d) on whether count is the right unit)
- `remainingThisMonth()`, `wouldExceedCap()`, `review()` — identical shapes to the image version
- `usageSummary()` — `{videos, cost, by_user[], by_provider[]}`, counting rows instead of summing variations

### 7.5 `GeneratedVideoAttacher`

Simpler than `GeneratedImageAttacher` — no output-index, no gallery-slot concept:

```php
attachToProduct(GeneratedVideo $video, Product $product): void   // products.video_path = ...
attachToOffer(GeneratedVideo $video, Offer $offer): void         // offers.video_path = ...
```

No `PRODUCT_SLOT_FEATURED`/`PRODUCT_SLOT_GALLERY` equivalent (v1 has one video per product/offer, not a gallery) and no `markAsVideoReference()` equivalent (no downstream tool needs a "video reference" pool yet). `outputPath()`/`assertSameCompany()`/`link()` protected helpers mirror the image version, minus `$outputIndex`.

`VideoGenerationException`/`VideoAttachmentException` play the identical roles their image counterparts do: adapter/resolver failures → job marks the row failed; attach-time business-rule violations → the Filament page shows a notification.

## 8. Provider Adapter Plan

### 8.1 `AbstractVideoProvider` (shared plumbing)

Mirrors `AbstractImageProvider`: `providerName()`, `operations()` (default `[OP_IMAGE_TO_VIDEO]`), `supportsOperation()`, `assertOperationSupported()`, `send(callable): Response` (identical HTTP-failure-to-exception wrapper), `summarize(Response)`. Adds one new helper not needed for images: `streamToSink(Response $response, mixed $sink): int`, used by every concrete adapter's `poll()` once a job is complete.

### 8.2 `StabilityVideoProvider` — the reference/first adapter

Stability is already an integrated image provider (an admin plugging in the same account/API key relationship), and its documented `POST /v2beta/image-to-video` (submit) / `GET /v2beta/image-to-video/result/{id}` (poll) shape is a native match for the `submit()`/`poll()` split above.

- `submit()`: multipart POST with the source image bytes → `{ id }` → `VideoSubmissionResult`.
- `poll()`: GET the result endpoint; "still generating" → `VideoPollResult(status: pending)`; finished → stream the body into `$sink` via `Http::sink($sink)` (**not** buffered into a string the way image bytes are today — correct at image scale, wrong at video scale) → `VideoPollResult(status: completed, ...)`; error → `VideoPollResult(status: failed, errorMessage: ...)`.
- Input framing: `GeneratedVideo::ASPECT_RATIOS` describes which input-image bucket the source gets fit to for this adapter — the same "snap to nearest supported" mechanism `StabilityImageProvider` already implements for images.

**Honest caveat:** the exact request/response envelope (required vs. optional parameters, whether the finished-clip response is `video/*` directly or JSON-wrapped, exactly which durations are selectable today) needs confirming against Stability's current API reference at implementation time — the same way `StabilityImageProvider`'s own docblock cites exact paths/shapes it verified. This plan fixes the architecture (submit/poll split, non-buffering stream, snap-to-nearest framing) with confidence; it does not assert unverified byte-level API specifics as fact. Feeds §13(b).

### 8.3 `CustomVideoProvider` — the flexible adapter

Mirrors `CustomOpenAiCompatibleProvider`'s spirit (admin-set base URL, no default endpoint, optional API key for keyless self-hosted servers) but needs a second endpoint since a generic async provider needs a real status-check URL:

- `submit()`: POST to the profile's `base_url`.
- `poll()`: GET `str_replace('{job_id}', $externalJobId, $profile['poll_url_template'])`. Defensively handles either an inline binary or a `video_url` to download-and-stream — the same defensive-both-shapes pattern `OpenAiImageProvider::generate()` already uses for `b64_json` vs `url`.

### 8.4 Near-term follow-up (not v1, but not "someday" either — see §2)

A second named commercial adapter (Runway, Kling, or similar) follows immediately once the submit/poll pattern is proven against Stability in production. Adding one is "one map entry in `VideoProviderResolver` + one adapter class implementing the same interface" — the same low marginal cost `ImageProviderResolver`'s own docblock already advertises for images.

## 9. Jobs & Notifications — `app/Jobs/GenerateVideoJob.php`

### 9.1 State machine across re-invocations

Every `$this->release()` re-queues the same serialized job payload — Laravel deserializes a fresh `GenerateVideoJob` instance and calls `handle()` again from scratch each time it's popped. Nothing persists in PHP state between invocations; everything that needs to survive lives on the `GeneratedVideo` row. The branch is one check:

```php
public function handle(CompanyContext $context, VideoProviderSettingsService $providerSettings, VideoProviderResolver $resolver, CompanyStorageService $storage, BusinessNotificationService $notifications): void
{
    $record = GeneratedVideo::withoutGlobalScopes()->with(['company', 'user'])->find($this->generatedVideoId);
    if (! $record || ! $record->company) return;

    $context->set($record->company);
    try {
        if ($record->isTerminal()) return; // safety: already resolved

        $profile = $providerSettings->find($record->company, $record->provider_profile_id);
        $adapter = $resolver->byFormat($profile['api_format']);

        blank($record->external_job_id)
            ? $this->submit($record, $profile, $adapter, $storage)
            : $this->poll($record, $profile, $adapter, $storage, $notifications);
    } catch (VideoGenerationException $e) {
        report($e);
        $record->forceFill(['status' => GeneratedVideo::STATUS_FAILED, 'error_message' => $e->getMessage()])->save();
        $this->notify($notifications, $record, success: false);
    } finally {
        $context->clear();
    }
}
```

- **`submit()`**: reads `source_image_path` via `CompanyStorageService::readPublic()` (identical to how `GenerateImageJob` reads `reference_image_path` today), calls `$adapter->submit(...)`, stores `external_job_id`, `submitted_at = now()`, `poll_deadline_at = now()->addMinutes(30)`, `next_poll_at = now()->addSeconds(15)`, calls `$this->release(15)`, returns. Row status becomes `processing`.
- **`poll()`**: increments `poll_attempts`, opens `$sink = tmpfile()` (disk-backed, not memory-backed — bounded memory regardless of clip size), calls `$adapter->poll($request, $record->external_job_id, $sink)`:
  - `pending` → bump `next_poll_at` with a small growing backoff (e.g. `min(60, 15 * (1 + intdiv($poll_attempts, 4)))` seconds) and `$this->release($delay)`.
  - `failed` → throw `VideoGenerationException($pollResult->errorMessage)` (caught by the outer try/catch — one code path handles every failure mode).
  - `completed` → `rewind($sink)`, hand the resource straight to `CompanyStorageService::putPublic($company, 'ai-generated-videos', $filename, $sink)` (its docblock already declares `resource|string $contents` and passes it straight to Flysystem's `put()` — no new storage-layer work needed), set `thumbnail_path` = the provider's poster URL if returned else `source_image_path`, `forceFill` the row `completed` + stamps + `estimated_cost`, best-effort `Media::recordUpload()` (`rescue()`-wrapped, exactly like the image job), notify success. Always `fclose($sink)` in a `finally`.

### 9.2 `retryUntil()` — why `$tries` stops being the real governor

Laravel's own queue worker ignores `$tries`/`maxTries` entirely once a job defines `retryUntil()` (the `maxTries` expiry check only runs when `retryUntil()` returns null):

```php
public int $tries = 200;      // defensive fallback only — should never actually bind
public int $timeout = 180;    // one submit call, OR one poll-check-plus-stream-download — never the whole wait
public bool $failOnTimeout = true;

public function retryUntil(): DateTime
{
    $record = GeneratedVideo::withoutGlobalScopes()->find($this->generatedVideoId);
    return $record?->poll_deadline_at ?? $record?->created_at?->addMinutes(30) ?? now()->addMinutes(30);
}
```

`timeout=180` (vs. `GenerateImageJob`'s 240) is deliberately shorter per-invocation: each invocation is a fast submit call or a poll-check-plus-download, never the full multi-minute wait — that wait lives in wall-clock time *across* releases via the production Redis queue (`queue:work --max-time=3600`), not inside one `handle()` call. This is independent of `nginx.template.conf`'s `max_execution_time=120`, which governs PHP-FPM web requests only — a queue worker runs under the CLI SAPI and is unaffected.

**A real edge case this design must close:** when a released job is popped again *after* `retryUntil()` has already elapsed, Laravel fails it via its own expiry check **before ever calling `handle()` again** — so the in-`handle()` try/catch above never runs, and without a safety net the row would be left stuck at `processing` forever with no error message. `GenerateImageJob` never needs this because it never re-releases itself. `GenerateVideoJob` needs a `failed(?Throwable $exception): void` hook (mirroring `AiAutoReplyJob::failed()`'s pattern of independently re-resolving the record and setting `CompanyContext` itself, since this hook runs outside `handle()`'s own context) that marks the row `STATUS_FAILED` with "The video did not finish in time." and notifies the user.

### 9.3 Streaming end-to-end

The "no full in-memory buffering" requirement is honored at every hop: the adapter's `poll()` streams the provider's HTTP response into `$sink` via `Http::sink($sink)`; the job never reads that sink's contents into a PHP string — it only `rewind()`s the resource and passes it directly to `CompanyStorageService::putPublic()`, which passes it straight to Flysystem's `put()`. No layer holds the whole clip in a PHP variable at once — a deliberate contrast with the image pipeline (`ImageOptimizerService::optimizeBytes()`), which is fine at few-MB image scale and wouldn't be at video scale.

### 9.4 `CompanyContext` across re-invocations

Unchanged pattern: **every invocation** (the one submit call and every poll call) is its own fresh `handle()` execution with its own `set()`/`clear()` pair in its own `try`/`finally`, exactly like `GenerateImageJob` today. The only difference is this happens N times per logical generation instead of once, because each is a genuinely separate queue pop, not a continuation of anything.

### 9.5 Notifications

`notify()` mirrors `GenerateImageJob::notify()` exactly: `BusinessNotificationService::notifyUser($user, 'ai-video', $title, $body, ['generated_video_id' => ...], $url, $label)`, `$url` resolved via `rescue(fn () => VideoGeneration::getUrl(), ...)`.

## 10. UI/UX Flow

1. User opens **Tool Menu** — the Video Generation card is now live (was "Coming soon").
2. Opens **Video Generation**. Form: a "Starting image" `FileUpload` (image-only, with a `SelectableFromMediaHub` hint action, plus a quick-pick `Select` sourced from `GeneratedImage::query()->completed()->videoReferences()->latest()` — the pool Image Generation's Phase 3 built specifically for this), a `Textarea` prompt describing the desired motion/scene, a `context` select, an `aspect_ratio` select, a `duration_preset` select, and a provider select (mirrors `ImageGeneration`'s `provider_profile_id` field).
3. Optional **✨ Enhance** button — identical component/flow to Image Generation's.
4. **Generate** creates a `GeneratedVideo` row (`status=queued`), dispatches `GenerateVideoJob::dispatch($record->getKey())`.
5. Gallery self-polls (`wire:poll`, mirroring `ImageGeneration::hasPendingGenerations()`) while any row is non-terminal; a processing row shows "checking… (Nth check)" using `poll_attempts`/`isPolling()`.
6. On completion: an inline `<video controls poster="...">` preview (poster = `thumbnailUrl()`), with actions **Download**, **Attach to Product**, **Attach to Offer**, **Favourite** — the image tool's video-reference/regenerate/remove-background actions have no video analog in v1.

**Video Library** (`VideoLibrary.php`) mirrors `ImageLibrary` column-for-column where the concept exists: thumbnail, prompt, context, duration, provider, creator, review status, cost, status, created_at (the "kind" column collapses since only one operation exists; the favourite/video-ref icon pair reduces to just favourite). Same filters (context, status, created-by, review, favourites, attached-to-a-record), same permission gate.

## 11. Attach-to-Record Integrations (v1 scope, per §2)

| Target | v1 | Deferred |
|---|---|---|
| Product | `products.video_path` set via `GeneratedVideoAttacher::attachToProduct()`; the storefront product page (today renders only `gallery_images`, no `<video>` tag anywhere) gets a minimal new block rendering `<video>` when `video_path` is set | A video *gallery* (multiple videos per product) |
| Offer / landing page | `offers.video_path` set via `attachToOffer()`; the offer hero block (today reads only `cover_image`) gets a variant that plays the video in the hero slot when present, falling back to `cover_image` otherwise | Block-builder-level "insert a video anywhere on the page" flexibility |
| Meta Ad creative | **Deferred entirely**, no committed timeline | `MetaAdsCreationService::createAd()` sets `'image_hash' => $imageHash` in the object-story-spec; there is no video-upload Graph API call and no video object-story-spec branch anywhere in the file. Adding this is a substantial standalone Meta Ads workstream (a new upload endpoint, a different creative spec shape) — tracked in §13(e) |

Per CLAUDE.md's rule that storefront-visible content must be admin-manageable via Filament, never hardcoded — the new blade blocks read `video_path` the same way `cover_image`/`gallery_images` are read today.

## 12. Phased Delivery Plan

**Phase 0 — Foundation** ✅ done
- Flip `ToolMenu::TOOLS['video_generation']['page']` to `VideoGeneration::class`
- Add `ai_tools.video_generation` to `ROLE_PERMISSIONS['manager']`; relabel it in `CUSTOM_PERMISSION_OPTIONS`
- Re-run the `ROLE_PERMISSIONS` → `user_roles` snapshot so the role editor UI stays in sync
- Update `docs/roles-and-permissions.md`

**Phase 1 — Video Generation core** ✅ done
- `generated_videos` migration + `GeneratedVideo` model (register in `MultiCompanyIsolationTest`)
- `products.video_path` / `offers.video_path` migration
- `VideoProviderSettingsService` + `VideoProviderSettings` page (Stability + Custom profiles, encrypted keys)
- `VideoGenerationRequest`/`VideoSubmissionResult`/`VideoPollResult`/`VideoGenerationClient` + `VideoProviderResolver` + `AbstractVideoProvider` + `StabilityVideoProvider` + `CustomVideoProvider`
- `GenerateVideoJob` (submit/poll state machine, `retryUntil()`, `failed()` safety net)
- `VideoGeneration` page (form + starting-image picker + queued generation + self-polling results, no attach actions yet)
- Deviation worth flagging: `GenerateVideoJob`'s outer `catch` was widened from the plan's `VideoGenerationException`-only pseudocode (§9.1) to `catch (Throwable ...)`, matching `GenerateImageJob`'s actual convention — an uncaught exception would otherwise let Laravel's default queue retry (at the connection's visibility-timeout cadence) silently take over instead of terminating the row as `failed` immediately.
- A real bug caught by testing, not by review: the video-reference quick-pick's `afterStateUpdated` originally wrote a bare path string into `source_image`'s live state via `$set()`. A Filament `FileUpload`'s validation rules assume array-shaped state unconditionally (`hydrateFiles()` normalizes to that shape on every *real* hydration, which a raw `$set()` bypasses) — clicking Generate after using the quick-pick would have thrown a `TypeError`. Fixed by wrapping the path (`[Str::uuid() => $path]`); `saveUploadedFiles()` passes a plain string entry through unchanged, so it is never mistaken for a fresh upload.
- Stability's exact API shape was confirmed via web research at implementation time (per §8.2's honest caveat): image-to-video's three accepted input-frame sizes (1024x576, 768x768, 576x1024) map exactly to `GeneratedVideo::ASPECT_RATIOS`; it has no duration parameter and always returns one short fixed-length clip, which resolves open question §14(b) — the `duration_preset` selector is kept (for the Custom adapter and future named providers) with UI copy clarifying Stability ignores it.

**Phase 2 — Prompt Enhancer integration** ✅ done
- New `video_generation.*` entries in `config/prompt_guides.php`
- Wire the shared "✨ Enhance" action into `VideoGeneration`'s prompt field
- Optional polish: parameterize `PromptEnhancementService::systemPrompt()`'s opening line so it doesn't say "image generation" for a video prompt
- Deviation: the plan said "no `provider_styles` changes needed", but the existing `stability` style asks for comma-separated keyword tags, which suits images, not a motion prompt. Video gets its own `video_provider_styles` map (only `custom`). The medium is taken from the context key prefix instead of a new `$mediaNoun` parameter, so `enhance()`'s signature is unchanged.
- Finding: Stability's `/v2beta/image-to-video` takes no text prompt at all (image + seed/cfg_scale/motion_bucket_id only). `VideoGenerationClient::usesTextPrompt()` was added; for Stability the Enhance button is hidden and the prompt field says the prompt is not sent. Enhancement matters for the Custom adapter and future text-capable providers.
- The Prompt Enhancer settings page labels guides "Image — …" / "Video — …" (both tools have a "General" context).

**Phase 3 — Attach & handoff integrations**
- `GeneratedVideoAttacher` service
- Per-video "Attach to product" / "Attach to offer" actions on the gallery
- New storefront blocks: product detail video block, offer hero video variant
- Meta Ads video creative work explicitly skipped (§13(e))

**Phase 4 — Productivity features**
- `VideoLibrary` page (search/filter/reuse-prompt, mirroring `ImageLibrary`)
- Favourites
- *(No batch-generation phase — count is always 1 by design; no image-to-image/background-removal equivalent for video in v1)*

**Phase 5 — Governance & polish**
- `VideoGovernance` page + `VideoGovernanceService` (usage/cost dashboard, per-role monthly cap, approval workflow)
- Add `ai_tools.video_generation.review` permission at this point (mirroring exactly when `.image_generation.review` was introduced)
- Audit logging (`ai_video.generated`, `ai_video.attached`, `ai_video.review_approved/rejected`) via the existing `AuditLogService::record()`

**Near-term follow-up (per §2, not a separate numbered phase):** a second named commercial adapter (Runway/Kling/similar) once Stability's submit/poll pattern is proven in production.

## 13. Testing Plan

Per CLAUDE.md's verification rules, and the exact test-file layout already used for Image Generation:

- Register `GeneratedVideo::class` in `MultiCompanyIsolationTest::test_every_company_owned_model_uses_the_company_scope_contract`
- `Http::fake()` every provider adapter call — `tests/Feature/VideoGenerationProviderAdaptersTest.php`, mirroring `ImageGenerationProviderAdaptersTest`'s structure (fake the submit endpoint and the poll endpoint separately, assert request bodies/headers via `Http::assertSent()`)
- One feature test per Filament page: `VideoGenerationPageTest`, `VideoLibraryTest`, `VideoProviderSettingsPageTest`, `VideoGovernancePageTest` — same permission-gating style as their Image equivalents
- One feature test per service: `VideoProviderSettingsServiceTest`, `VideoGovernanceServiceTest`, `GeneratedVideoAttacherTest`

**Testing the async job** — the genuinely new pattern, built on two Laravel mechanics:

1. Mirror `GenerateImageJobTest`'s own technique of calling `app()->call([new GenerateVideoJob($id), 'handle'])` directly rather than dispatching through a real queue, to simulate submit → poll(pending) → poll(pending) → poll(completed), or → poll(failed), across repeated calls against the same row.
2. **Asserting `release()`/`retryUntil()` without a real sleep**: `$job->release($delay)` is a silent no-op when the job's internal queue-job property is unset (true for a directly-instantiated job). Use `Illuminate\Queue\InteractsWithQueue`'s own test seam — call `$job->withFakeQueueInteractions()` before invoking `handle()`, then `$job->assertReleased($expectedDelaySeconds)` / `$job->assertNotReleased()` after. Concretely:
   - **Submit test:** seed a `queued` row with no `external_job_id`; fake the submit endpoint; run `handle()` with fake queue interactions; assert `status=processing`, `external_job_id` set, `poll_deadline_at`/`next_poll_at` populated, `assertReleased(15)`.
   - **Poll-still-pending test:** seed a `processing` row with `external_job_id`; fake a "still processing" poll response; assert `poll_attempts` incremented, `next_poll_at` pushed forward, `assertReleased($expectedBackoff)`.
   - **Poll-completed test:** fake a finished poll response (confirmed `Http::fake()`'s sink handling writes the faked body into the sink and rewinds it — no special test-only adapter code path needed); `Storage::fake('public')`; assert `status=completed`, `video_path` set and exists on disk, `estimated_cost` stamped, success notification queued, `assertNotReleased()`.
   - **Poll-failed test:** fake an error/failed poll response; assert `status=failed` with `error_message`, failure notification, `assertNotReleased()`.
   - **`retryUntil()` unit test:** construct against a row with a known `poll_deadline_at`; assert the exact return value (and the `created_at + 30min` fallback when null) — a pure method call.
   - **`failed()` safety-net test:** seed a `processing` row whose `poll_deadline_at` is already past; call `$job->failed($exception)` directly; assert the row flips to `failed` with a timeout message and a failure notification — the test for the edge case in §9.2 where Laravel's own expiry check would otherwise leave the row silently stuck.

Run the affected test files plus the full `php artisan test` (no `--env` flag, per CLAUDE.md) and `npm run build` since Filament pages/blade changes touch frontend assets.

## 14. Open Questions / Follow-Ups

a. ~~Which video provider(s) to integrate first~~ — **resolved (§2):** Stability + Custom in Phase 1, a second named provider as an immediate near-term follow-up.
b. **Exact duration/aspect-ratio presets.** `short`/`standard` durations and a `1:1`/`16:9`/`9:16` aspect set are reasonable, easily-changed defaults — but Stability's image-to-video API may currently support only a single fixed native duration with no real choice (unverified at planning time; needs checking against current docs before Phase 1's UI is finalized). If so, decide whether to hide the duration selector for a single-duration provider or show it as informational, since the preset menu only earns its keep once a second adapter with genuine duration choice exists.
c. ~~Whether Product/Offer video attach is wanted in v1~~ — **resolved (§2):** yes, Generate + Attach.
d. **Monthly cap unit: video count vs. total seconds**, and a default `cost_per_video` placeholder (or explicitly "0, admin must set it," matching `cost_per_image`'s current default). `VideoGovernanceService`'s `monthly_caps: {role: int}` storage shape is unit-agnostic — only the enforcement method's definition changes — so this can be decided (or changed later) without touching the schema.
e. **Meta Ads video creative timeline.** Confirmed as new Meta Graph API work (a video-upload endpoint, a different object-story-spec branch), not a small addition on top of the existing image-hash-based flow. No committed timeline — flagged here so it isn't silently forgotten rather than deliberately deferred.
