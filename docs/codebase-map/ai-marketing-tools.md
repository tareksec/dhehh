# AI & Marketing Tools
> Last checked: 2026-11-01, branch `claude/upbeat-franklin-hy6fhr` (offers: soft deletes and Trash, T3); before it: 2026-10-20, commit 0e5316d

## What it does
The AI & Marketing Tools module provides a suite of generative AI and digital advertising capabilities designed for e-commerce growth. It features a unified Tool Menu accessing AI image and video generation engines across multiple third-party providers (Google Imagen, OpenAI DALL-E, Stability AI, OpenRouter, and custom endpoints) with mandatory content governance and approval workflows. It includes a prompt enhancement engine that enriches ad prompts with industry-specific e-commerce guides. For performance marketing, it integrates with the Meta Marketing API, featuring a live Meta Ads dashboard, automated campaign creation, an AI ad copy assistant, and audience synchronization. Additionally, it automates high-converting combo offer landing page generation with structured block content and promotional pricing.

## Where to start
- `app/Filament/Pages/ToolMenu.php` — Central launcher page organizing all generative AI tools and marketing utilities.
- `app/Services/ImageGeneration/ImageGenerationClient.php` — Core client managing image generation requests, provider dispatching, and error handling.
- `app/Services/VideoGeneration/VideoGenerationClient.php` — Asynchronous video synthesis client handling rendering submissions and polling.
- `app/Services/ImageGeneration/ImageGovernanceService.php` — Content moderation service enforcing brand safety, logging prompts, and managing review states.
- `app/Services/MetaMarketingApiClient.php` — Low-level HTTP client interfacing with Meta's Graph Marketing API for ads management.
- `app/Services/MetaAdsAiAssistantService.php` — Conversational assistant drafting Meta ad campaigns, target audiences, and ad copies.
- `app/Services/OfferLandingPageAiGenerator.php` — Generative engine building multi-section e-commerce landing pages from product specs.
- `app/Filament/Pages/ImageGeneration.php` — Filament interactive studio for generating and editing product promotional imagery.

## Files

### Models
| Path | What it does |
| --- | --- |
| `app/Models/GeneratedImage.php` | Metadata record for synthesized images tracking prompt, provider, disk path, and governance status. |
| `app/Models/GeneratedVideo.php` | Metadata record for synthesized video assets tracking rendering status and Drive export links. |
| `app/Models/MetaAdAccount.php` | Connected Facebook/Meta ad accounts with currency, spending limits, and access tokens. |
| `app/Models/MetaAdCampaign.php` | Synced Meta ad campaigns tracking objective, daily budget, and delivery status. |
| `app/Models/MetaAdSet.php` | Synced Meta ad sets tracking audience targeting parameters, bid strategy, and optimization goals. |
| `app/Models/MetaAd.php` | Synced Meta ad creative records tracking spend, impressions, clicks, and ROAS. |
| `app/Models/MetaAdProposal.php` | AI-drafted ad campaign proposals awaiting staff approval before publishing to Meta. |
| `app/Models/Offer.php` | Promotional combo offers and marketing landing page configurations. |
| `app/Models/OfferItem.php` | Product and variant line items bundled inside a promotional combo offer. |

### Image & Video Generation Services
| Path | What it does |
| --- | --- |
| `app/Services/ImageGeneration/ImageGenerationClient.php` | Orchestrates image generation through resolved provider drivers. |
| `app/Services/ImageGeneration/ImageProviderResolver.php` | Factory resolving active driver (Google, OpenAI, Stability, OpenRouter, Custom). |
| `app/Services/ImageGeneration/ImageProviderSettingsService.php` | Manages encrypted API credentials, model names, and resolution limits. |
| `app/Services/ImageGeneration/ImageGovernanceService.php` | Evaluates prompt safety, logs usage, and manages staff review status (`approved`, `flagged`). |
| `app/Services/ImageGeneration/GeneratedImageAttacher.php` | Attaches approved generated images to catalog products or storefront slides. |
| `app/Services/VideoGeneration/VideoGenerationClient.php` | Dispatches video synthesis requests and checks asynchronous task status. |
| `app/Services/VideoGeneration/VideoProviderResolver.php` | Resolves video generation drivers (Stability AI, Custom Video API). |
| `app/Services/VideoGeneration/VideoProviderSettingsService.php` | Manages video model parameters, fps settings, and API secrets. |
| `app/Services/VideoGeneration/VideoGovernanceService.php` | Reviews synthesized video clips before permitting attachment to products. |
| `app/Services/VideoGeneration/GeneratedVideoAttacher.php` | Associates generated video files with products and storefront showcases. |
| `app/Services/VideoGeneration/VideoDriveExporter.php` | Exports heavy video files to Google Drive storage to conserve server disk space. |

### Prompt Enhancement & Creative Services
| Path | What it does |
| --- | --- |
| `app/Services/PromptEnhancement/PromptEnhancementService.php` | Enriches basic user prompts with professional photography and e-commerce directives. |
| `app/Services/PromptEnhancement/PromptEnhancerConfigService.php` | Manages prompt enhancer temperature, system prompts, and model selection. |
| `app/Services/PromptEnhancement/PromptGuideRepository.php` | Loads category-specific prompt guides from `config/prompt_guides.php`. |
| `app/Services/AdCreative/TextRenderingCapability.php` | Checks whether active diffusion models support legible on-image text rendering. |

### Meta Ads & Landing Page Services
| Path | What it does |
| --- | --- |
| `app/Services/MetaMarketingApiClient.php` | Low-level client executing Meta Graph API calls for campaigns and audiences. |
| `app/Services/MetaAdsSyncService.php` | Synchronizes active campaigns, ad sets, and spend telemetry from Meta. |
| `app/Services/MetaAdsCreationService.php` | Publishes approved AI ad proposals to Meta as live draft campaigns. |
| `app/Services/MetaAdsAiAssistantService.php` | Conversational LLM generating marketing angles, targeting interests, and headlines. |
| `app/Services/MetaAudienceSyncService.php` | Pushes high-value customer phone numbers and emails to Meta Custom Audiences. |
| `app/Services/OfferLandingPageAiGenerator.php` | Uses AI to generate compelling landing page copy, FAQs, and USP sections. |
| `app/Services/OfferPricingService.php` | Calculates combo package savings, bundled discounts, and gross margins. |

### Filament Clusters, Resources & Pages
| Path | What it does |
| --- | --- |
| `app/Filament/Clusters/AiTools.php` | Navigation cluster grouping generative image, video, and prompt tools. |
| `app/Filament/Clusters/Ads.php` | Navigation cluster grouping Meta advertising management and audience sync. |
| `app/Filament/Pages/ToolMenu.php` | Central tool launcher card grid for marketing and AI utilities. |
| `app/Filament/Pages/ImageGeneration.php` | Creative studio for generating images with aspect ratio and style selectors. |
| `app/Filament/Pages/ImageLibrary.php` | Gallery of previously generated AI imagery with search and download filters. |
| `app/Filament/Pages/ImageGovernance.php` | Moderation queue for reviewing, approving, or discarding generated images. |
| `app/Filament/Pages/ImageProviderSettings.php` | Admin page for configuring API keys for OpenAI, Google, and Stability. |
| `app/Filament/Pages/VideoGeneration.php` | Studio for generating short video clips from text or starter images. |
| `app/Filament/Pages/VideoLibrary.php` | Video gallery with embedded video player and Google Drive export buttons. |
| `app/Filament/Pages/VideoGovernance.php` | Moderation queue for staff review of synthesized videos. |
| `app/Filament/Pages/VideoProviderSettings.php` | Admin page for configuring video API provider credentials. |
| `app/Filament/Pages/PromptEnhancerSettings.php` | Admin settings for tuning the prompt enhancement assistant. |
| `app/Filament/Pages/MetaAdsDashboard.php` | Analytics dashboard displaying live Meta ad spend, impressions, CPC, and ROAS. |
| `app/Filament/Pages/CreateMetaAdCampaign.php` | Step-by-step wizard creating new campaigns on Facebook and Instagram. |
| `app/Filament/Pages/MetaAdsAiAssistant.php` | Interactive chat assistant drafting full advertising campaign structures. |
| `app/Filament/Pages/MetaEventsManager.php` | Health monitor for Meta Conversions API (CAPI) events and pixel match quality. |
| `app/Filament/Resources/MetaAdAccounts/MetaAdAccountResource.php` | Manages connected Meta advertising accounts and access tokens. |
| `app/Filament/Resources/Offers/OfferResource.php` | Manages combo product offers and AI-generated landing pages. Offers and collections are soft-deleted (T3, `Offer` model, Trash page `Pages/TrashedOffers.php`); a collection in Trash stops applying its deal prices and keeps its slug. |

### Jobs, Commands & Config
| Path | What it does |
| --- | --- |
| `app/Jobs/GenerateImageJob.php` | Queued job executing external API calls to generate images in the background. |
| `app/Jobs/GenerateVideoJob.php` | Queued job polling video generation tasks until rendering completes. |
| `app/Jobs/SaveVideoToDriveJob.php` | Background job streaming finished video files to Google Drive cloud storage. |
| `app/Console/Commands/CheckAdCreativeRendering.php` | Artisan command validating ad creative text rendering capabilities. |
| `app/Console/Commands/SyncMetaAdsData.php` | Scheduled command synchronizing campaign metrics and spend data from Meta. |
| `config/prompt_guides.php` | Configuration array storing category-specific photography prompts and styles. |

### Views
| Path | What it does |
| --- | --- |
| `resources/views/filament/pages/tool-menu.blade.php` | Launcher card grid view for AI tools. |
| `resources/views/filament/pages/image-generation.blade.php` | Interactive image generation studio view. |
| `resources/views/filament/pages/image-library.blade.php` | Image gallery grid view. |
| `resources/views/filament/pages/image-governance.blade.php` | Moderation review view for generated images. |
| `resources/views/filament/pages/video-generation.blade.php` | Video synthesis studio view. |
| `resources/views/filament/pages/video-library.blade.php` | Video gallery view with playback controls. |
| `resources/views/filament/pages/video-governance.blade.php` | Video moderation review view. |
| `resources/views/filament/pages/meta-ads-dashboard.blade.php` | Meta advertising performance dashboard. |
| `resources/views/filament/pages/create-meta-ad-campaign.blade.php` | Ad campaign creation form layout. |
| `resources/views/filament/pages/meta-ads-ai-assistant.blade.php` | Interactive chat assistant interface for ad campaign planning. |
| `resources/views/filament/pages/meta-events-manager.blade.php` | CAPI event health monitoring dashboard. |

### Migrations (name pattern only)
- Find a table's migrations by searching `database/migrations/` for the table name: several tables are created together in one migration, so the file name does not always match the table.
- `database/migrations/*create_generated_images_table.php`
- `database/migrations/*create_generated_videos_table.php`
- `database/migrations/*create_meta_ad_accounts_table.php`
- `database/migrations/*create_meta_ad_campaigns_table.php`
- `database/migrations/*create_meta_ad_sets_table.php`
- `database/migrations/*create_meta_ads_table.php`
- `database/migrations/*create_meta_ad_proposals_table.php`
- `database/migrations/*create_offers_table.php`
- `database/migrations/*create_offer_items_table.php`

### Tests
| Path | What it does |
| --- | --- |
| `tests/Feature/ImageGenerationPageTest.php` | Tests prompt submission, aspect ratio options, and provider resolution. |
| `tests/Feature/ImageGovernanceServiceTest.php` | Tests content moderation rules and approval status workflows. |
| `tests/Feature/VideoGenerationPageTest.php` | Tests video synthesis job dispatching and polling states. |
| `tests/Feature/VideoGovernanceServiceTest.php` | Tests video review approval and attachment to products. |
| `tests/Feature/PromptEnhancementServiceTest.php` | Tests prompt enrichment using prompt guide rules. |
| `tests/Feature/MetaAdsDashboardPageTest.php` | Tests metrics rendering and date filtering on the Meta ads dashboard. |
| `tests/Feature/MetaAdsCreationServiceTest.php` | Tests converting AI proposals into live Meta draft campaigns. |
| `tests/Feature/OfferLandingPageAiGeneratorTest.php` | Tests automated generation of landing page copy, blocks, and FAQs. |

## Routes and panels
- **Panels**:
  - `admin` panel:
    - AI Tools Cluster (`admin/ai-tools/*`): Tool Menu, Image Generation, Image Library, Image Governance, Image Provider Settings, Video Generation, Video Library, Video Governance, Video Provider Settings, Prompt Enhancer Settings.
    - Ads Cluster (`admin/ads/*`): Meta Ads Dashboard, Create Campaign, Meta Ads AI Assistant, Meta Events Manager, Meta Ad Accounts.
    - Storefront Cluster (`admin/storefront/*`): Promotional Combo Offers (`admin/storefront/offers`).
- **Storefront HTTP Routes**:
  - `GET|HEAD offers/{slug}` (name `storefront.offers.show`) -> `App\Http\Controllers\Storefront\OfferController@show`
  - `POST offers/{slug}/checkout` (name `storefront.offers.checkout`) -> `App\Http\Controllers\Storefront\OfferCheckoutController@store`
  - `GET|HEAD offers/{slug}/thank-you/{order}` (name `storefront.offers.thank-you`) -> `App\Http\Controllers\Storefront\OfferCheckoutController@thankYou`

## Data
- `generated_images`: Generated image assets. Key columns: `id`, `company_id`, `user_id`, `tool`, `context`, `original_prompt`, `prompt`, `provider_profile_id`, `provider_label`, `api_format`, `model`, `reference_image_path`, `aspect_ratio`, `variations_requested`, `output_paths` (JSON), `status`, `review_status`, `reviewed_by`, `reviewed_at`, `review_note`, `operation`, `created_at`, `updated_at`.
- `generated_videos`: Generated video clips. Key columns: `id`, `company_id`, `user_id`, `tool`, `operation`, `original_prompt`, `prompt`, `source_image_path`, `source_generated_image_id`, `provider_profile_id`, `provider_label`, `api_format`, `model`, `aspect_ratio`, `duration_preset`, `external_job_id`, `video_path`, `thumbnail_path`, `review_status`, `reviewed_by`, `status`, `drive_status`, `drive_file_id`, `drive_url`, `created_at`, `updated_at`.
- `meta_ad_accounts`: Connected Meta ad accounts. Key columns: `id`, `company_id`, `name`, `credentials` (JSON), `currency`, `exchange_rate_to_company_currency`, `is_active`, `is_default`, `token_expires_at`, `last_synced_at`, `created_at`, `updated_at`.
- `meta_ad_campaigns`: Ad campaigns. Key columns: `id`, `company_id`, `meta_ad_account_id`, `meta_id`, `name`, `objective`, `status`, `effective_status`, `daily_budget`, `lifetime_budget`, `buying_type`, `spend`, `impressions`, `clicks`, `reach`, `created_at`, `updated_at`.
- `meta_ad_sets`: Ad targeting sets. Key columns: `id`, `company_id`, `meta_ad_campaign_id`, `meta_id`, `name`, `status`, `effective_status`, `daily_budget`, `targeting` (JSON), `spend`, `impressions`, `clicks`, `reach`, `created_at`, `updated_at`.
- `meta_ads`: Live and draft ads. Key columns: `id`, `company_id`, `meta_ad_set_id`, `meta_id`, `name`, `status`, `effective_status`, `meta_creative_id`, `headline`, `primary_text`, `description_text`, `call_to_action`, `destination_url`, `image_hash`, `spend`, `impressions`, `clicks`, `created_at`, `updated_at`.
- `meta_ad_proposals`: AI campaign drafts. Key columns: `id`, `company_id`, `meta_ad_account_id`, `product_id`, `is_recommended`, `suggested_budget`, `headline`, `primary_text`, `description_text`, `call_to_action`, `targeting` (JSON), `reasoning_text`, `ai_provider`, `ai_model`, `status`, `meta_ad_campaign_id`, `created_at`, `updated_at`.
- `offers`: Combo offers. Key columns: `id`, `company_id`, `type`, `title`, `slug`, `status`, `short_description`, `price_mode`, `manual_price`, `discount_type`, `discount_value`, `blocks` (JSON), `is_ai_generated`, `meta_title`, `meta_description`, `cover_image`, `online_payment_required`, `video_path`, `collection_kind`, `starts_at`, `ends_at`, `created_at`, `updated_at`.
- `offer_items`: Combo line items. Key columns: `id`, `company_id`, `offer_id`, `product_id`, `product_variant_id`, `quantity`, `sort_order`, `deal_price`, `created_at`, `updated_at`.

## Rules that matter
- **Multi-Provider Resolution & Encrypted Secrets**: Provider credentials (OpenAI, Google Gemini/Imagen, Stability AI, OpenRouter) are managed via `ImageProviderSettingsService` and stored encrypted in `app_settings`. Generation drivers resolve dynamically through `ImageProviderResolver` and `VideoProviderResolver`. Enforced by `app/Services/ImageGeneration/ImageProviderResolver.php` and `app/Services/VideoGeneration/VideoProviderResolver.php`.
- **Mandatory Content Governance Gate**: All generated images and videos start in `governance_status = 'pending'`. Media cannot be published to storefront product carousels or attached to live ads until staff with the `ai_tools.image_generation.review` permission explicitly reviews and approves the asset via `ImageGovernanceService`. Enforced by `app/Services/ImageGeneration/ImageGovernanceService.php`.
- **Asynchronous Video Synthesis & Drive Archival**: Video generation is asynchronous; submission stores a `task_id` and queues `GenerateVideoJob` to poll external APIs until rendering completes. Heavy video files can be offloaded to Google Drive via `SaveVideoToDriveJob` and `VideoDriveExporter` to keep local disk usage low. Enforced by `app/Jobs/GenerateVideoJob.php` and `app/Services/VideoGeneration/VideoDriveExporter.php`.
- **Prompt Enhancement Rules**: Basic prompts submitted by users are automatically expanded with professional lighting, composition, and e-commerce keywords loaded from `config/prompt_guides.php` before being sent to generation APIs. Enforced by `app/Services/PromptEnhancement/PromptEnhancementService.php` and `config/prompt_guides.php`.
- **Approval Gate for Meta Ad Campaigns**: Automated ad proposals generated by `MetaAdsAiAssistantService` cannot directly alter external Meta advertising accounts; they remain in `meta_ad_proposals` in `draft` state until an authorized manager clicks **Approve & Publish**, which dispatches `MetaAdsCreationService`. Enforced by `app/Services/MetaAdsCreationService.php`.
- **Offer Landing Page Generation Invariant**: When an AI landing page is generated for an `Offer`, all referenced products must belong to the active company, and promotional pricing must satisfy minimum margin thresholds calculated by `OfferPricingService`. Enforced by `app/Services/OfferLandingPageAiGenerator.php` and `app/Services/OfferPricingService.php`.

## Links to other modules
- **core-platform.md**: Multi-tenant isolation is enforced on generated media and ad accounts via `BelongsToCompany` and `CompanyScope`.
- **catalog-inventory.md**: Approved images and videos attach directly to catalog records in `Product` via `GeneratedImageAttacher`.
- **sales-orders-couriers.md**: Standalone combo landing pages take orders via `OfferCheckoutController`, flowing directly into `Order`.
- **crm-messaging.md**: Chat agents send product cards and promotional images generated by AI tools to customers.
- **storefront-blade.md**: The Blade storefront renders standalone combo landing pages (`/offers/{slug}`) with AI-generated sections.

## Related docs
- `11_AI_TOOL_MENU_IMAGE_GENERATION_PLAN.md` — Original architecture plan for the AI Tool Menu and image generation.
- `12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md` — Video generation provider architecture and Google Drive export rules.
- `14_AI_TOOL_MENU_CONTENT_CREATION_PLAN.md` — Ad creative generation and prompt enhancement architecture.
- `07_OFFERS_COMBO_LANDING_PAGE_PLAN.md` — Architecture for combo promotional offers and AI-generated landing pages.
