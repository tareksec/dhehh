<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Jobs\DispatchWebsiteWebhookJob;
use App\Livewire\MetaEventLogTable;
use App\Livewire\WebsiteApiKeysTable;
use App\Models\Company;
use App\Models\Order;
use App\Models\SmsLog;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\Crm\AiSettingsService;
use App\Services\PayStationClient;
use App\Services\SmsLogService;
use App\Services\StorefrontMetaTrackingService;
use App\Services\StorefrontNotificationService;
use App\Services\WooCommerceImportService;
use App\Services\WooCommerceOrderSyncService;
use App\Services\ZiniPayClient;
use App\Support\BulkSmsBdPolicy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Livewire as SchemaLivewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Single, complete editable settings page for every integration this app
 * connects to: AI Integration (one config per AI-powered tool), WooCommerce
 * (credentials, order-webhook test/sync, and the full product import),
 * Payment Gateway (ZiniPay/PayStation credentials and base URLs), SMS Gateway
 * (URL template, API key, sender ID — stored in the SMS keys of
 * `notification_credentials`, merged so the mail/WhatsApp keys edited on
 * Storefront Settings are kept), and the
 * entire Meta Pixel & Conversions API configuration — connection, consent,
 * browser/status events, purchase timing, and the Event Log & Retries table
 * (embedded via App\Livewire\MetaEventLogTable, the same
 * Filament\Schemas\Components\Livewire-embedding pattern
 * App\Livewire\OrderTrashTable already uses elsewhere). The old, partial
 * copies of this config that used to live on Storefront Settings (WooCommerce
 * Import, Online Payments) and the standalone Meta CAPI page are retired —
 * this page is now the one place each integration's config lives. Courier
 * Providers and Meta Ads are NOT merged here — both are full multi-record
 * CRUD areas (many providers/ad accounts, dashboards, campaigns), so they
 * stay on their own pages; IntegrationStatusWidget below still surfaces them
 * as status-card links so this remains the one place staff check first.
 * The AI tools' provider/key pages (Image Providers, Video Providers, Prompt
 * Enhancer, Smart Scan) are likewise linked as cards (aiToolProviderLinks()).
 *
 * Two backing stores get combined into one Livewire form:
 * - AI fields live in `companies.settings->ai_tools->{tool}`, one entry per
 *   AiSettingsService::TOOLS key (via AiSettingsService).
 * - WooCommerce/Payment Gateway/Meta/Website API fields live on a
 *   StorefrontSetting row (`updateOrCreate` with a partial attribute array
 *   only ever touches these columns, never the theme/checkout/SEO settings
 *   living on that same row). A company can have a row per site (Blade
 *   storefront, Next.js site); the "Site" select picks which one is edited.
 *   WooCommerce belongs to the company's primary row (the Next.js site once it
 *   has one, else the Blade storefront), which is what the WooCommerce code reads.
 */
class Integrations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $title = 'Integrations';

    protected string $view = 'filament.pages.integrations';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public ?int $companyId = null;

    /** The StorefrontSetting row (site) whose payment/Meta/webhook fields are being edited. */
    #[Locked]
    public ?int $settingId = null;

    public function mount(AiSettingsService $aiSettings): void
    {
        if (app(CompanyContext::class)->isAllCompanies()) {
            return;
        }

        $company = $this->selectedCompany();
        $this->companyId = (int) $company->getKey();

        $setting = $this->activeSetting();
        $this->settingId = $setting?->getKey();

        // Every key is filled explicitly (never left for a field's own
        // ->default() to supply) — a brand-new company has no StorefrontSetting
        // row yet, and Filament's Schema::fill() does not fall back to a
        // component's default() for a key simply absent from the given
        // array, so a required Select like online_payment_gateway would
        // fail validation on first save with no visible reason otherwise.
        $this->form->fill([
            ...$this->aiToolFillState($aiSettings, $company),
            'site_setting_id' => $setting?->getKey(),
            'woocommerce_base_url' => $setting?->woocommerce_base_url,
            'woocommerce_credentials' => $setting?->woocommerce_credentials ?? [],
            'website_api_credentials' => $setting?->website_api_credentials ?? [],
            'online_payment_enabled' => $setting?->online_payment_enabled ?? false,
            'online_payment_gateway' => $setting?->online_payment_gateway ?? 'zinipay',
            'payment_credentials' => $setting?->payment_credentials ?? [],
            'notification_credentials' => Arr::only((array) $setting?->notification_credentials, StorefrontNotificationService::SMS_CREDENTIAL_KEYS),
            ...array_merge($this->metaFieldDefaults(), $setting?->only($this->metaFields()) ?? []),
        ]);
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        $context = app(CompanyContext::class);

        if (! $user?->canManageSettings()) {
            return false;
        }

        if ($context->isAllCompanies()) {
            return $user->isSuperAdmin();
        }

        $company = $context->company();

        return (bool) ($company && $user->canAccessCompany((int) $company->getKey()));
    }

    /**
     * Link cards for the AI tools' own provider/key pages (Image Providers,
     * Video Providers, Prompt Enhancer, Smart Scan). Each is a multi-field
     * page of its own, so it is linked from here rather than folded into
     * this form; a card shows only to users who can open that page.
     *
     * @return list<array{label: string, description: string, icon: string, url: string}>
     */
    public function aiToolProviderLinks(): array
    {
        $pages = [
            [ImageProviderSettings::class, __('Image providers and API keys for the Image Generation tool.')],
            [VideoProviderSettings::class, __('Video providers and API keys for the Video Generation tool.')],
            [PromptEnhancerSettings::class, __('Model, key and house style behind the ✨ Enhance prompt button.')],
            [ExpenseScanSettings::class, __('Vision model and key that read expense photos and pasted text into draft expenses.')],
        ];

        return collect($pages)
            ->filter(fn (array $page): bool => $page[0]::canAccess())
            ->map(fn (array $page): array => [
                'label' => $page[0]::getNavigationLabel(),
                'description' => $page[1],
                'icon' => $page[0]::getNavigationIcon(),
                'url' => $page[0]::getUrl(),
            ])
            ->values()
            ->all();
    }

    public function hasSelectedCompany(): bool
    {
        return $this->companyId !== null;
    }

    /** AI settings stay super-admin-only — this merged page must not loosen that. */
    /** @return Collection<int, StorefrontSetting> The company's sites, Blade first. */
    protected function companySettings(): Collection
    {
        return $this->companyId
            ? StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->companyId)->orderBy('site_type')->orderBy('id')->get()
            : collect();
    }

    /**
     * The row being edited: the chosen site when it belongs to this company,
     * otherwise the company's primary row (the Next.js site once it has one,
     * else the Blade storefront), otherwise whatever the company has.
     */
    protected function activeSetting(): ?StorefrontSetting
    {
        $rows = $this->companySettings();
        $primaryId = StorefrontSetting::primaryFor($this->companyId)?->getKey();

        return $rows->firstWhere('id', $this->settingId)
            ?? ($primaryId ? $rows->firstWhere('id', $primaryId) : null)
            ?? $rows->first();
    }

    /** @return array<int, string> */
    protected function siteOptions(): array
    {
        return $this->companySettings()
            ->mapWithKeys(fn (StorefrontSetting $row): array => [$row->getKey() => $row->siteTypeLabel().' · '.($row->siteDomain() ?: '—')])
            ->all();
    }

    /** WooCommerce lives on the company's primary row: the Next.js site once it has one, else the Blade storefront. */
    protected function editingPrimarySite(): bool
    {
        $active = $this->activeSetting();

        return $active === null || $active->isPrimary();
    }

    public function canManageAi(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    /**
     * Fill state for every AI tool's `ai_{tool}_*`-prefixed fields, keyed
     * off AiSettingsService::TOOLS so mount() never falls out of sync with
     * the tab schema below. The API key is never round-tripped to the
     * browser — only a `has_api_key` flag drives the field's placeholder.
     */
    protected function aiToolFillState(AiSettingsService $aiSettings, Company $company): array
    {
        $fill = [];

        foreach (AiSettingsService::TOOLS as $tool => $label) {
            $ai = $aiSettings->all($company, $tool);
            $prefix = "ai_{$tool}_";

            $fill["{$prefix}enabled"] = $ai['enabled'];
            $fill["{$prefix}api_format"] = $ai['api_format'];
            $fill["{$prefix}provider"] = $ai['provider'];
            $fill["{$prefix}base_url"] = $ai['base_url'];
            $fill["{$prefix}model"] = $ai['model'];
            $fill["{$prefix}confidence_threshold"] = $ai['confidence_threshold'];
            $fill["{$prefix}max_consecutive_ai_replies"] = $ai['max_consecutive_ai_replies'];
            $fill["{$prefix}brand_voice"] = $ai['brand_voice'];
            $fill["{$prefix}sales_guidelines"] = $ai['sales_guidelines'];
            foreach ([
                'vision_enabled', 'image_model_enabled', 'image_api_format', 'image_provider', 'image_base_url', 'image_model',
                'voice_enabled', 'voice_provider', 'voice_base_url', 'voice_model',
                'review_mode', 'daily_run_limit', 'max_run_tokens', 'daily_budget_usd', 'input_cost_per_million', 'output_cost_per_million', 'sales_follow_ups_enabled', 'follow_up_schedule_hours', 'follow_up_weekly_count', 'follow_up_quiet_start_hour', 'follow_up_quiet_end_hour', 'follow_up_message', 'follow_up_template', 'follow_up_template_language',
                'proactive_mode_enabled', 'objection_handling_enabled', 'cross_sell_enabled', 'personalization_enabled',
                'takeover_minutes', 'reply_delay_seconds', 'handover_notice_enabled', 'rich_replies_enabled', 'address_style',
                'response_target_minutes', 'ai_live', 'typing_indicator_enabled', 'product_cards_enabled',
            ] as $key) {
                $fill[$prefix.$key] = $ai[$key];
            }
            $fill["{$prefix}api_key"] = '';
            $fill["{$prefix}has_api_key"] = filled($ai['api_key']);
            $fill["{$prefix}image_api_key"] = '';
            $fill["{$prefix}image_has_api_key"] = filled($ai['image_api_key']);
            $fill["{$prefix}voice_api_key"] = '';
            $fill["{$prefix}voice_has_api_key"] = filled($ai['voice_api_key']);
        }

        return $fill;
    }

    /**
     * The shared "provider connection" field set for one AI tool, reused for
     * Auto Messaging / Ad Assistant / Landing Page Builder so the same ~10
     * Filament components aren't copy-pasted three times. $prefix is the
     * Livewire state-path prefix (e.g. "ai_messaging_") so the three tools'
     * fields never collide in the form state. $messagingExtras adds the
     * auto-reply-specific fields (confidence threshold, reply cap, brand
     * voice) — only meaningful for the Auto Messaging tool.
     */
    /**
     * Popular OpenAI-compatible providers, each with its own base URL and a
     * short pick-list of well-known models — purely a "quick setup"
     * convenience layer on top of the free-text provider/base_url/model
     * fields below, which stay fully editable for anything not listed here
     * (a brand-new provider, a model this list hasn't caught up with, a
     * self-hosted endpoint, ...). Never a closed set the admin is limited
     * to. Counts vary by provider on purpose — e.g. Perplexity genuinely
     * ships five Sonar models while xAI's dot-versioned lineup only has
     * three well-established entries; padding to a round number would just
     * be inventing names that don't exist.
     *
     * Model names checked against each provider's own docs/changelog as of
     * September 2026 (web search) — still a snapshot, not a live catalog:
     * providers ship new models often, so re-verify against the provider's
     * own docs if a listed name ever starts erroring, and the free-text
     * Model field below is always the fallback for anything newer than
     * this list.
     *
     * @return array<string, array{label: string, base_url: string, models: array<int, string>}>
     */
    protected function llmProviderPresets(): array
    {
        return [
            'deepseek' => [
                'label' => 'DeepSeek',
                'base_url' => 'https://api.deepseek.com/chat/completions',
                // deepseek-chat/deepseek-reasoner (the old names) are being
                // retired in favor of these — see api-docs.deepseek.com/updates.
                'models' => ['deepseek-v4-pro', 'deepseek-v4-flash', 'deepseek-v4-flash-vision-exp'],
            ],
            'groq' => [
                'label' => 'Groq',
                'base_url' => 'https://api.groq.com/openai/v1/chat/completions',
                // Groq deprecated its own llama-3.3/3.1 chat routes — these
                // are its current recommended general-purpose + reasoning models.
                'models' => ['openai/gpt-oss-120b', 'openai/gpt-oss-20b', 'meta-llama/llama-4-scout-17b-16e-instruct', 'qwen-qwq-32b', 'deepseek-r1-distill-llama-70b'],
            ],
            'openrouter' => [
                'label' => 'OpenRouter',
                'base_url' => 'https://openrouter.ai/api/v1/chat/completions',
                'models' => ['deepseek/deepseek-v4-pro', 'anthropic/claude-opus-5', 'google/gemini-3-flash-preview', 'x-ai/grok-4.6', 'openai/gpt-5-mini'],
            ],
            'mistral' => [
                'label' => 'Mistral',
                // Mistral's "-latest" ids are permanent aliases that Mistral
                // itself repoints to the newest release, so this list stays
                // current on its own without needing updates here.
                'base_url' => 'https://api.mistral.ai/v1/chat/completions',
                'models' => ['mistral-large-latest', 'mistral-small-latest', 'codestral-latest', 'magistral-medium-latest', 'ministral-8b-latest'],
            ],
            'xai' => [
                'label' => 'xAI (Grok)',
                'base_url' => 'https://api.x.ai/v1/chat/completions',
                'models' => ['grok-4.6', 'grok-4.5', 'grok-4-0709'],
            ],
            'togetherai' => [
                'label' => 'Together AI',
                'base_url' => 'https://api.together.xyz/v1/chat/completions',
                'models' => ['deepseek-ai/DeepSeek-V3.1', 'deepseek-ai/DeepSeek-V4-Pro', 'deepseek-ai/DeepSeek-R1', 'meta-llama/Llama-3.3-70B-Instruct-Turbo'],
            ],
            'fireworks' => [
                'label' => 'Fireworks AI',
                'base_url' => 'https://api.fireworks.ai/inference/v1/chat/completions',
                'models' => ['accounts/fireworks/models/deepseek-v4-pro', 'accounts/fireworks/models/deepseek-v4-flash', 'accounts/fireworks/models/glm-5p2'],
            ],
            'perplexity' => [
                'label' => 'Perplexity',
                'base_url' => 'https://api.perplexity.ai/chat/completions',
                'models' => ['sonar', 'sonar-pro', 'sonar-reasoning', 'sonar-reasoning-pro', 'sonar-deep-research'],
            ],
            'openai' => [
                'label' => 'OpenAI',
                'base_url' => '',
                'models' => ['gpt-5.5', 'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano', 'o3'],
            ],
            'ollama' => [
                'label' => 'Self-hosted (Ollama / vLLM)',
                'base_url' => 'http://localhost:11434/v1/chat/completions',
                'models' => ['llama3.3', 'qwen2.5', 'mistral', 'deepseek-r1', 'phi4'],
            ],
        ];
    }

    /** Anthropic's own current lineup — the model picker's fallback list when API format is Anthropic. */
    protected function anthropicModels(): array
    {
        return [
            'claude-opus-5' => 'Claude Opus 5',
            'claude-sonnet-5' => 'Claude Sonnet 5',
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
            'claude-fable-5-1' => 'Claude Fable 5.1',
        ];
    }

    /**
     * Model options for the picker: whichever provider preset is currently
     * selected, or Anthropic's lineup when the format is Anthropic and no
     * preset is picked — always with the Model field's current value (and
     * this picker's own current value) folded in. The self-merge matters:
     * Filament validates a Select's submitted state against its own live
     * options(), and this picker's options legitimately change whenever
     * provider_preset/api_format change (e.g. after a plain TextInput edit
     * elsewhere in the form) — without folding its own current value back
     * in, a perfectly fine prior selection would fail validation on save
     * purely because the option list moved out from under it, even though
     * this field is cosmetic (dehydrated(false), never persisted).
     */
    protected function llmModelOptions(Get $get, string $prefix): array
    {
        $presets = $this->llmProviderPresets();
        $presetKey = $get("{$prefix}provider_preset");

        $options = isset($presets[$presetKey])
            ? array_combine($presets[$presetKey]['models'], $presets[$presetKey]['models'])
            : ($get("{$prefix}api_format") === 'anthropic' ? $this->anthropicModels() : []);

        foreach ([$get("{$prefix}model"), $get("{$prefix}model_picker")] as $value) {
            $value = trim((string) $value);

            if ($value !== '' && ! array_key_exists($value, $options)) {
                $options = [$value => $value] + $options;
            }
        }

        return $options;
    }

    protected function aiToolFields(string $prefix, string $enableLabel, bool $messagingExtras): array
    {
        $fields = [
            Toggle::make("{$prefix}enabled")
                ->label($enableLabel)
                ->columnSpanFull(),
            ...$this->providerConnectionFields($prefix),
        ];

        if ($messagingExtras) {
            $fields[] = MarkdownEditor::make("{$prefix}sales_guidelines")
                ->label('সেলস এজেন্টের কথাবার্তার নির্দেশনা')
                ->helperText('লেখা, শিরোনাম, তালিকা ও উদাহরণ এডিট করে Save changes চাপুন। সেভ করা নির্দেশনা এই কোম্পানির পরবর্তী AI উত্তরে ব্যবহৃত হবে। উদাহরণের দাম ও প্রতিশ্রুতি প্রকৃত তথ্য নয়; FAQ-এর প্রস্তুত উত্তর আলাদাভাবে এডিট করতে হবে। ছবি শনাক্তকরণ বা নতুন অর্ডার-অ্যাকশন শুধু নির্দেশনা লিখে চালু হয় না।')
                ->toolbarButtons(['bold', 'italic', 'heading', 'bulletList', 'orderedList', 'blockquote', 'table', 'undo', 'redo'])
                ->maxLength(16000)
                ->hintAction(Action::make('restoreSalesGuidelines')
                    ->label('ডিফল্ট লেখা ফিরিয়ে আনুন')
                    ->action(fn (Set $set) => $set("{$prefix}sales_guidelines", app(AiSettingsService::class)->defaultSalesGuidelines())))
                ->columnSpanFull();
            $fields[] = Toggle::make("{$prefix}ai_live")->label(__('AI answers real customers (live)'))
                ->helperText(__('Off is practice mode: the AI answers only chats marked as test chats in the Inbox. Turn this on when you are happy with the test chat. You can also go live from the Setup checklist.'));
            $fields[] = Toggle::make("{$prefix}vision_enabled")
                ->label('Read customer images (vision)')
                ->live()
                ->afterStateUpdated(function (bool $state, Get $get, Set $set) use ($prefix): void {
                    if ($state && (int) $get($prefix.'max_run_tokens') < 30000) {
                        $set($prefix.'max_run_tokens', 30000);
                    }
                })
                ->helperText('Requires a vision-capable model. Enabling raises the per-run token budget to at least 30,000 for image input; review the budget before saving. The latest customer image is sent privately to your AI provider.');
            $fields[] = Toggle::make("{$prefix}image_model_enabled")
                ->label('Use a separate model for images')
                ->live()
                ->visible(fn (Get $get): bool => (bool) $get("{$prefix}vision_enabled"))
                ->helperText('Off: the photo is sent straight to the model above, which must itself be vision-capable. On: a dedicated model/provider below reads the photo first and hands the agent a text description — useful when your main model has no vision support, or a different provider does a better or cheaper job just on photos.');
            $imageFieldsActive = fn (Get $get): bool => (bool) $get("{$prefix}vision_enabled") && (bool) $get("{$prefix}image_model_enabled");
            foreach ($this->providerConnectionFields("{$prefix}image_", $imageFieldsActive) as $field) {
                $fields[] = $field->visible($imageFieldsActive);
            }
            $fields[] = Toggle::make("{$prefix}voice_enabled")
                ->label('Transcribe voice messages')
                ->live()
                ->helperText("Requires a Whisper-compatible transcription model (OpenAI, Groq, or a self-hosted Whisper server). The customer's voice note is sent privately to this provider; the transcript is saved onto the message and answered like a normal text message.");
            $fields[] = TextInput::make("{$prefix}voice_provider")
                ->label('Provider name (label only)')
                ->placeholder('e.g. OpenAI, Groq')
                ->helperText("Just for your own reference — doesn't affect the request.")
                ->maxLength(100)
                ->visible(fn (Get $get): bool => (bool) $get("{$prefix}voice_enabled"));
            $fields[] = TextInput::make("{$prefix}voice_base_url")
                ->label('Base URL (optional)')
                ->url()
                ->maxLength(500)
                ->placeholder('Leave blank for https://api.openai.com/v1/audio/transcriptions')
                ->helperText('e.g. Groq: https://api.groq.com/openai/v1/audio/transcriptions')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => (bool) $get("{$prefix}voice_enabled"));
            $fields[] = TextInput::make("{$prefix}voice_model")
                ->label('Model')
                ->placeholder('whisper-1')
                ->maxLength(100)
                ->visible(fn (Get $get): bool => (bool) $get("{$prefix}voice_enabled"));
            $fields[] = TextInput::make("{$prefix}voice_api_key")
                ->label('API Key')
                ->password()
                ->revealable()
                ->maxLength(500)
                ->placeholder(fn (Get $get): string => $get("{$prefix}voice_has_api_key") ? '••••••••' : 'sk-...')
                ->helperText(fn (Get $get): string => $get("{$prefix}voice_has_api_key")
                    ? 'Already saved and encrypted — leave blank to keep it.'
                    : 'Stored encrypted per company.')
                ->visible(fn (Get $get): bool => (bool) $get("{$prefix}voice_enabled"));
            $fields[] = Toggle::make("{$prefix}review_mode")->label('Draft replies for staff review')->helperText('Suggestions appear in CRM → Sales Automation. No automatic reply is sent in this mode.');
            $fields[] = Toggle::make("{$prefix}proactive_mode_enabled")->label('Proactive follow-up questions')->helperText('After fully answering, the agent may also ask one natural next-step question (size/colour, quantity, delivery area) to help move the sale forward. Off by default: it answers only what was asked.');
            $fields[] = Toggle::make("{$prefix}objection_handling_enabled")->label('Handle mild price objections')->helperText('A mild "can you reduce it a bit?" message reaches the agent, which may restate value or ask a clarifying question — grounded in real catalog facts, never an invented discount. Off by default: any discount/bargaining language always hands off to a human.');
            $fields[] = Toggle::make("{$prefix}cross_sell_enabled")->label('Suggest related products (cross-sell)')->helperText('Lets the agent offer one real same-category product alongside its answer. Off by default: the agent only discusses what the customer asked about.');
            $fields[] = TextInput::make("{$prefix}takeover_minutes")->label(__('Team time before the chat returns to the AI (minutes)'))
                ->numeric()->minValue(30)->maxValue(1440)->required()
                ->helperText(__('Used when someone takes over a chat, answers it, or the AI hands it over. When the time is up the chat goes back to the AI on its own. 360 = 6 hours.'));
            $fields[] = TextInput::make("{$prefix}response_target_minutes")->label(__('Reply target for handed-over chats (minutes)'))
                ->numeric()->minValue(0)->maxValue(1440)->required()
                ->helperText(__('A chat the AI hands to the team should get a first reply from a person within this time. Late chats are marked on the Needs attention page. 0 = no target.'));
            $fields[] = TextInput::make("{$prefix}reply_delay_seconds")->label(__('Wait before answering (seconds)'))
                ->numeric()->minValue(0)->maxValue(15)->required()
                ->helperText(__('The AI waits this long after a message, so two quick messages get one combined answer. 0 answers every message on its own.'));
            $fields[] = Toggle::make("{$prefix}handover_notice_enabled")->label(__('Tell the customer when a person takes over'))
                ->helperText(__('When the AI hands a chat to the team it sends one short message saying a colleague is looking into it. It never promises a time.'));
            $fields[] = Toggle::make("{$prefix}rich_replies_enabled")->label(__('Neatly arranged replies'))
                ->helperText(__('Product facts go on their own lines with small labels: price, sizes, stock, delivery charge. Off: compact one-line replies.'));
            $fields[] = Select::make("{$prefix}address_style")->label(__('How the AI addresses the customer in Bangla'))
                ->options(['none' => __('No title, only "apni"'), 'sir_maam' => __('Sir / Madam'), 'bhaiya_apu' => __('Bhaiya / Apu')])
                ->required()->native(false)
                ->helperText(__('The AI picks the title from what the customer wrote or their name. If it cannot tell, it uses no title.'));
            $fields[] = Toggle::make("{$prefix}typing_indicator_enabled")->label(__('Show "Seen" and "typing..." while the AI answers'))
                ->helperText(__('The customer sees the message marked as seen and a typing sign until the answer arrives (Messenger and WhatsApp).'));
            $fields[] = Toggle::make("{$prefix}product_cards_enabled")->label(__('Send product cards on Messenger'))
                ->helperText(__('After an answer that names products, Messenger customers also get a swipeable row of cards with the photo, the price and a button. Products without a photo get no card. Off: text only.'));
            $fields[] = Toggle::make("{$prefix}personalization_enabled")->label('Personalize by purchase history')->helperText('Lets the agent greet a returning customer and reference their past orders. Off by default: no purchase-history signal is shown to the agent.');
            foreach (['daily_run_limit' => 'Daily run limit', 'max_run_tokens' => 'Token budget per run', 'daily_budget_usd' => 'Daily estimated budget (USD)', 'input_cost_per_million' => 'Input price per million tokens (USD)', 'output_cost_per_million' => 'Output price per million tokens (USD)'] as $key => $label) {
                $fields[] = TextInput::make($prefix.$key)->label($label)->numeric()->minValue(0)->required();
            }
            $fields[] = Toggle::make("{$prefix}sales_follow_ups_enabled")->label('Enable sales follow-ups')->helperText(__('Reminders for a customer who was sent a checkout link or a quotation and has not ordered. They stop on a purchase, an opt-out, or when a person takes the chat.'));
            $fields[] = TextInput::make("{$prefix}follow_up_schedule_hours")->label(__('Reminder times (hours after the last message from the customer)'))->required()
                ->helperText(__('Separate the hours with commas, for example 1, 6, 24, 48. A new message from the customer starts the count again.'));
            $fields[] = TextInput::make("{$prefix}follow_up_weekly_count")->label(__('Weekly reminders after that'))
                ->numeric()->minValue(0)->maxValue(12)->required()
                ->helperText(__('One reminder a week, this many times. 0 = none.'));
            $fields[] = TextInput::make("{$prefix}follow_up_quiet_start_hour")->label(__('No reminders from (hour, 0-23)'))->numeric()->minValue(0)->maxValue(23)->required();
            $fields[] = TextInput::make("{$prefix}follow_up_quiet_end_hour")->label(__('No reminders until (hour, 0-23)'))->numeric()->minValue(0)->maxValue(23)->required()
                ->helperText(__('Company time. A reminder due in these hours waits until the end hour.'));
            $fields[] = Textarea::make("{$prefix}follow_up_message")->label(__('Messenger and Instagram reminder text'))->rows(3)->maxLength(900)
                ->helperText(__('These channels allow a normal message only within 24 hours of the last message from the customer. Write {link} where the order link should go. Left empty, those reminders go to your team on the Needs attention page instead.'));
            $fields[] = TextInput::make("{$prefix}follow_up_template")->label('Approved WhatsApp follow-up template')->helperText('Template must accept one body parameter: customer name.');
            $fields[] = TextInput::make("{$prefix}follow_up_template_language")->label('Template language')->maxLength(20);
            $fields[] = TextInput::make("{$prefix}confidence_threshold")
                ->label('Confidence threshold (0–1)')
                ->numeric()
                ->step(0.05)
                ->minValue(0)
                ->maxValue(1)
                ->required()
                ->helperText('Replies below this confidence are held for a human instead of being sent.');
            $fields[] = TextInput::make("{$prefix}max_consecutive_ai_replies")
                ->label('Max consecutive AI replies')
                ->numeric()
                ->minValue(1)
                ->maxValue(20)
                ->required();
            $fields[] = Textarea::make("{$prefix}brand_voice")
                ->label('Brand voice (optional)')
                ->rows(3)
                ->maxLength(2000)
                ->placeholder("e.g. friendly, uses simple Bengali, addresses customers as 'আপনি'")
                ->columnSpanFull();
        }

        return $fields;
    }

    /**
     * The "provider connection" field set shared by every AI credential
     * block on this page: the main tool models (Auto Messaging / Ad
     * Assistant / Landing Page Builder) and the two Auto-Messaging-only
     * sub-models below (image reading, voice transcription — voice builds
     * its own smaller field set instead, since transcription is a different
     * wire shape these quick-setup chat presets don't apply to). $prefix is
     * the Livewire state-path prefix (e.g. "ai_messaging_" or
     * "ai_messaging_image_") so no two credential blocks' fields collide.
     * $requiredWhen defaults to always-required (the main tool blocks, whose
     * fields are always on screen); the image sub-model block passes its own
     * visibility condition here too, so a hidden, switched-off sub-model
     * never blocks the form from saving (Filament does not skip validation
     * on a merely-invisible field — required() must be conditioned to match,
     * the same pattern already used throughout this codebase, e.g.
     * CloudStorageSettings::required(fn (Get $get) => $get('enabled'))).
     */
    protected function providerConnectionFields(string $prefix, bool|\Closure $requiredWhen = true): array
    {
        $presets = $this->llmProviderPresets();
        $presetOptions = collect($presets)->map(fn (array $preset): string => $preset['label'])->all();

        return [
            Select::make("{$prefix}provider_preset")
                ->label('Quick setup: pick a popular provider')
                ->options($presetOptions)
                ->searchable()
                ->live()
                ->dehydrated(false)
                ->helperText('Fills in the provider name, base URL, and API format for you, and offers that provider\'s models below — everything stays editable, and picking nothing here is fine too.')
                ->afterStateHydrated(function (Select $component, Get $get) use ($presets, $prefix): void {
                    $currentProvider = trim((string) $get("{$prefix}provider"));
                    $match = collect($presets)->search(fn (array $preset): bool => $preset['label'] === $currentProvider);
                    $component->state($match !== false ? $match : null);
                })
                ->afterStateUpdated(function (Set $set, ?string $state) use ($presets, $prefix): void {
                    if (! $state || ! isset($presets[$state])) {
                        return;
                    }

                    $preset = $presets[$state];
                    $set("{$prefix}provider", $preset['label']);
                    $set("{$prefix}base_url", $preset['base_url']);
                    $set("{$prefix}api_format", 'openai');
                    $set("{$prefix}model", $preset['models'][0] ?? '');
                })
                ->columnSpanFull(),
            Select::make("{$prefix}api_format")
                ->label('API format')
                ->options([
                    'anthropic' => 'Anthropic (Claude Messages API)',
                    'openai' => 'OpenAI-compatible (Chat Completions)',
                ])
                ->helperText('Almost every non-Anthropic provider (OpenAI, DeepSeek, Groq, Mistral, OpenRouter, xAI, a self-hosted Ollama/vLLM, ...) speaks the "OpenAI-compatible" format — pick that and set the base URL below to add any of them.')
                ->required($requiredWhen)
                ->live()
                ->native(false),
            TextInput::make("{$prefix}provider")
                ->label('Provider name (label only)')
                ->placeholder('e.g. DeepSeek, Groq, OpenRouter')
                ->helperText("Just for your own reference — doesn't affect the request. Auto-filled by the quick-setup picker above.")
                ->required($requiredWhen)
                ->maxLength(100),
            TextInput::make("{$prefix}base_url")
                ->label('Base URL (optional)')
                ->url()
                ->maxLength(500)
                ->placeholder("Leave blank for the API format's own default endpoint")
                ->helperText('e.g. DeepSeek: https://api.deepseek.com/chat/completions')
                ->columnSpanFull(),
            Select::make("{$prefix}model_picker")
                ->label('Popular models for this provider')
                ->options(fn (Get $get): array => $this->llmModelOptions($get, $prefix))
                ->searchable()
                ->live()
                ->dehydrated(false)
                ->helperText('A short, curated list — providers ship new models often, so if a search here comes up empty, just type any model name straight into the Model field below instead.')
                ->afterStateHydrated(function (Select $component, Get $get) use ($prefix): void {
                    $component->state(trim((string) $get("{$prefix}model")) ?: null);
                })
                ->afterStateUpdated(fn (Set $set, ?string $state) => filled($state) ? $set("{$prefix}model", $state) : null)
                ->columnSpanFull(),
            TextInput::make("{$prefix}model")
                ->label('Model')
                ->placeholder('claude-haiku-4-5-20251001')
                ->required($requiredWhen)
                ->maxLength(100),
            TextInput::make("{$prefix}api_key")
                ->label('API Key')
                ->password()
                ->revealable()
                ->maxLength(500)
                ->placeholder(fn (Get $get): string => $get("{$prefix}has_api_key") ? '••••••••' : 'sk-...')
                ->helperText(fn (Get $get): string => $get("{$prefix}has_api_key")
                    ? 'Already saved and encrypted — leave blank to keep it.'
                    : 'Stored encrypted per company.'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveChanges')
                ->label('Save changes')
                ->icon(Heroicon::OutlinedCheck)
                ->submit('save')
                ->formId('integrations-form')
                ->keyBindings(['mod+s'])
                ->visible(fn (): bool => $this->hasSelectedCompany()),
        ];
    }

    /**
     * The WooCommerce tab's three action buttons (test webhook, sync one
     * order now, and the full product import), placed inside the tab body
     * itself rather than the page header. Each is its own
     * "{actionName}Action()"-named method (not a shared array-returning
     * helper) because Filament's action-testing helpers
     * (callAction()/assertActionVisible()) resolve a page-level action by
     * name via exactly that method-name convention — see
     * InteractsWithActions::resolveAction() — regardless of where inside
     * the schema the Action itself is rendered.
     */
    protected function testWoocommerceWebhookAction(): Action
    {
        return Action::make('testWoocommerceWebhook')
            ->label('Send test webhook')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->action('testWoocommerceWebhook')
            ->visible(fn (): bool => $this->hasSelectedCompany());
    }

    /**
     * Sends a real, signed 'ping' event to this company's own saved
     * webhook_url using the secret actually saved in the database — same
     * "test against what's really persisted, not the unsaved form" idea as
     * testWoocommerceWebhook() above, just in the outbound direction.
     */
    protected function testWebsiteWebhookAction(): Action
    {
        return Action::make('testWebsiteWebhook')
            ->label('Send test webhook')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->action('testWebsiteWebhook')
            ->visible(fn (): bool => $this->hasSelectedCompany());
    }

    public function testWebsiteWebhook(): void
    {
        $company = $this->selectedCompany();
        $setting = $this->activeSetting();
        $credentials = $setting?->website_api_credentials ?? [];

        $url = (string) ($credentials['webhook_url'] ?? '');
        $secret = (string) ($credentials['webhook_secret'] ?? '');

        if ($url === '' || $secret === '') {
            Notification::make()
                ->title('Webhook URL or secret not saved yet')
                ->body('Fill in both fields below and save changes first.')
                ->warning()
                ->send();

            return;
        }

        // Dispatched directly (not via WebsiteWebhookDispatcher::dispatch()),
        // which deliberately skips delivery while "Send webhooks" is off —
        // a connectivity test needs to work before the integration goes live.
        DispatchWebsiteWebhookJob::dispatch($url, $secret, 'ping', []);

        Notification::make()
            ->title('Test webhook queued')
            ->body('Check your website\'s webhook endpoint logs for a "ping" event.')
            ->success()
            ->send();
    }

    /**
     * Sends a real SMS through the gateway saved for the selected site and shows the gateway's own answer (a
     * wrong sender ID, no balance, an IP that is not whitelisted ...). It answers "does the gateway work?" without
     * placing an order. Uses what is saved, not the unsaved form, like the webhook tests above.
     */
    protected function testSmsAction(): Action
    {
        return Action::make('testSms')
            ->label(__('Send test SMS'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->schema([
                TextInput::make('phone')
                    ->label(__('Phone number'))
                    ->helperText(__('Your own number, in the form 01XXXXXXXXX.'))
                    ->tel()
                    ->required()
                    ->maxLength(40),
            ])
            ->action(fn (array $data): mixed => $this->testSms($data))
            ->visible(fn (): bool => $this->hasSelectedCompany());
    }

    /** @param array{phone?: ?string} $data */
    public function testSms(array $data): void
    {
        $company = $this->selectedCompany();
        $setting = $this->activeSetting();
        $notifications = app(StorefrontNotificationService::class);
        $phone = trim((string) ($data['phone'] ?? ''));

        if ($setting === null || ! $notifications->smsConfigured($setting)) {
            Notification::make()
                ->title(__('The SMS gateway is not saved yet'))
                ->body(__('Fill in the gateway URL, API key and sender ID, press Save changes, then send the test again.'))
                ->warning()
                ->send();

            return;
        }

        // A masking sender ID (letters) is accepted only for Bangla text, so the test is written in Bangla for it.
        $language = BulkSmsBdPolicy::isMaskingSender(data_get($setting->notification_credentials, 'sms_sender_id')) ? 'bn' : app()->getLocale();
        $message = BulkSmsBdPolicy::clean(__('SMS test from :company. Your SMS gateway works.', ['company' => $company->name], $language));
        $result = $notifications->sendSmsDetailed($setting, $phone, $message);

        $logs = app(SmsLogService::class);
        $logs->finish(
            $logs->queued((int) $company->getKey(), SmsLog::KIND_TEST, null, null, $phone),
            $result['ok'] ? SmsLog::STATUS_SENT : SmsLog::STATUS_FAILED,
            $result['reason'],
            ['message' => $message, 'gateway_code' => $result['code'], 'detail' => $result['meaning'] ?? $result['detail']],
        );

        if ($result['ok']) {
            Notification::make()
                ->title(__('The gateway accepted the test SMS'))
                ->body(__('It should reach :phone within a minute. If it does not, ask BulkSMSBD about the sender ID or the number.', ['phone' => $phone]))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('The gateway did not send the test SMS'))
            ->body(collect([
                $result['reason'] !== null ? (SmsLogService::reasonLabels()[$result['reason']] ?? null) : null,
                collect([$result['code'], $result['meaning'] ?? $result['detail']])->filter()->implode(' - '),
                StorefrontNotificationService::smsGatewayHint($result['code']),
            ])->filter()->implode(' '))
            ->danger()
            ->persistent()
            ->send();
    }

    protected function syncWooOrderAction(): Action
    {
        return Action::make('syncWooOrder')
            ->label('Sync an order now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->schema([
                TextInput::make('woo_order_id')
                    ->label('WooCommerce order ID')
                    ->helperText('The number shown on the order in WooCommerce (e.g. 38044) — not the ERP order number.')
                    ->numeric()
                    ->required(),
            ])
            // A plain string ->action('syncWooOrder') would look identical
            // to testWoocommerceWebhookAction()'s above, but isn't: Filament
            // only opens this action's modal (to collect the schema's data
            // first) when the action is a Closure. A string action is wired
            // as a raw wire:click that invokes the method with zero
            // arguments, straight past the modal — a hard TypeError against
            // this method's required $data.
            ->action(fn (array $data): mixed => $this->syncWooOrder($data))
            ->visible(fn (): bool => $this->hasSelectedCompany());
    }

    /**
     * Pulls published products from the WooCommerce site into this
     * company's catalog (matched by SKU/slug; nothing is deleted) — the
     * same WooCommerceImportService::importProducts() call
     * StorefrontSettingResource::syncWooCommerceAction() used to make from
     * its own now-removed "WooCommerce Import" section, adapted to resolve
     * the company from page state instead of a bound Resource $record.
     */
    protected function syncWooCommerceImportAction(): Action
    {
        return Action::make('syncWooCommerceImport')
            ->label('Sync WooCommerce')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (): bool => $this->hasSelectedCompany() && $this->hasWooCommerceCredentials())
            ->requiresConfirmation()
            ->modalDescription('Pulls published products from the WooCommerce site into this company\'s catalog. Products are matched by SKU/slug and updated; nothing is deleted.')
            ->schema([
                Toggle::make('download_images')
                    ->label('Download product images')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                $company = $this->selectedCompany();

                try {
                    $result = app(WooCommerceImportService::class)->importProducts(
                        $company,
                        downloadImages: (bool) ($data['download_images'] ?? true),
                    );

                    Notification::make()
                        ->title('WooCommerce sync complete')
                        ->body("Created: {$result['created']}, updated: {$result['updated']}, skipped: {$result['skipped']}.")
                        ->success()
                        ->send();
                } catch (\RuntimeException $exception) {
                    Notification::make()
                        ->title('WooCommerce sync failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /** Checked against the persisted row, matching testWoocommerceWebhook()/syncWooOrder()'s own use of the saved secret over unsaved form state. */
    protected function hasWooCommerceCredentials(): bool
    {
        $setting = StorefrontSetting::primaryFor($this->companyId);

        return $setting !== null
            && filled($setting->woocommerce_base_url)
            && filled(data_get($setting->woocommerce_credentials, 'consumer_key'))
            && filled(data_get($setting->woocommerce_credentials, 'consumer_secret'));
    }

    /**
     * Every Meta Pixel & CAPI column on StorefrontSetting, with the default
     * each field falls back to when a brand-new company has no
     * StorefrontSetting row yet — mirrors the "fill every key explicitly"
     * requirement noted on mount() above, now covering the fields absorbed
     * from the retired MetaCapiSettings page.
     */
    protected function metaFieldDefaults(): array
    {
        return [
            'meta_tracking_enabled' => false,
            'meta_consent_required' => false,
            'meta_consent_message' => null,
            'meta_browser_tracking_enabled' => true,
            'meta_advanced_matching_enabled' => false,
            'meta_browser_events' => StorefrontMetaTrackingService::DEFAULT_BROWSER_EVENTS,
            'meta_custom_events_enabled' => false,
            'meta_custom_events' => [],
            'meta_capi_enabled' => false,
            'meta_purchase_timing' => 'immediate',
            'meta_purchase_success_ratio_threshold' => 70,
            'meta_status_events_enabled' => false,
            'meta_status_events' => StorefrontMetaTrackingService::DEFAULT_STATUS_EVENTS,
            'meta_pixel_id' => null,
            'meta_domain_verification_tags' => [],
            'meta_tracking_credentials' => [],
        ];
    }

    /** @return array<int, string> */
    protected function metaFields(): array
    {
        return array_keys($this->metaFieldDefaults());
    }

    /**
     * Sends a real, correctly-signed request to this company's own
     * WooCommerce webhook URL using the secret actually saved in the
     * database (not whatever's currently typed but unsaved in the form),
     * so a signature mismatch between here and WooCommerce's own Secret
     * field is instantly obvious without needing WooCommerce's own delivery
     * log or a manual curl request. Exercises the exact same route,
     * middleware, and CSRF-exemption path a real WooCommerce delivery does.
     */
    public function testWoocommerceWebhook(): void
    {
        $company = $this->selectedCompany();
        $setting = StorefrontSetting::primaryFor((int) $company->getKey());
        $secret = (string) data_get($setting?->woocommerce_credentials, 'webhook_secret');

        if ($secret === '') {
            Notification::make()
                ->title('No webhook secret saved yet')
                ->body('Generate and save one below first.')
                ->warning()
                ->send();

            return;
        }

        $body = '{}';
        $signature = base64_encode(hash_hmac('sha256', $body, $secret, true));

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-WC-Webhook-Topic' => 'order.updated',
                    'X-WC-Webhook-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post(route('woocommerce.webhook', $company));
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Could not reach the webhook URL')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($response->successful()) {
            Notification::make()
                ->title('Webhook reachable, signature verified')
                ->body('A correctly-signed test request using the saved secret was accepted (HTTP '.$response->status().'). WooCommerce should be able to deliver real orders here — if it still shows a delivery error, re-check that its Secret field exactly matches the one saved below (re-copy both sides after Generate, don\'t retype).')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Webhook test failed: HTTP '.$response->status())
            ->body($response->status() === 403
                ? 'The server rejected this request\'s signature even though it was computed from the secret saved below — the saved value itself may be stale (try Generate, Save, then test again).'
                : $response->body())
            ->danger()
            ->send();
    }

    /**
     * Pulls one order straight from WooCommerce's own REST API (using the
     * consumer key/secret saved below) and runs it through the exact same
     * WooCommerceOrderSyncService::handleOrderEvent() a real webhook
     * delivery would — in-process, no HTTP hop through the webhook route
     * itself. Two purposes in one action: manually backfilling an order a
     * webhook never delivered (e.g. one sent before a secret was saved),
     * and — since any failure is shown here verbatim instead of only in a
     * server log or WooCommerce's own delivery log (which hides response
     * bodies unless WP_DEBUG is on) — diagnosing *why* real deliveries are
     * failing, without needing server/log access at all.
     */
    public function syncWooOrder(array $data): void
    {
        $company = $this->selectedCompany();
        $wooOrderId = (int) $data['woo_order_id'];

        $setting = StorefrontSetting::primaryFor((int) $company->getKey());
        $baseUrl = rtrim((string) $setting?->woocommerce_base_url, '/');
        $key = (string) data_get($setting?->woocommerce_credentials, 'consumer_key');
        $secret = (string) data_get($setting?->woocommerce_credentials, 'consumer_secret');

        if ($baseUrl === '' || $key === '' || $secret === '') {
            Notification::make()
                ->title('WooCommerce site URL or API key/secret is not saved yet')
                ->body('Fill in and save those below first.')
                ->warning()
                ->send();

            return;
        }

        try {
            $response = Http::timeout(15)
                ->withBasicAuth($key, $secret)
                ->get("{$baseUrl}/wp-json/wc/v3/orders/{$wooOrderId}");
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Could not reach the WooCommerce site')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($response->failed()) {
            Notification::make()
                ->title('WooCommerce rejected the request: HTTP '.$response->status())
                ->body($response->body())
                ->danger()
                ->send();

            return;
        }

        try {
            app(WooCommerceOrderSyncService::class)->handleOrderEvent($company, 'order.updated', (array) $response->json());
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Sync failed: '.$exception->getMessage())
                ->body($exception::class)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $order = Order::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('external_reference', "woo-{$wooOrderId}")
            ->first();

        Notification::make()
            ->title('Order synced')
            ->body($order ? "WooCommerce order #{$wooOrderId} is now {$order->order_number} in ZamZam ERP." : "WooCommerce order #{$wooOrderId} was processed.")
            ->success()
            ->send();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('site_setting_id')
                    ->label(__('Site'))
                    ->options(fn (): array => $this->siteOptions())
                    ->visible(fn (): bool => count($this->siteOptions()) > 1)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(function (mixed $state): void {
                        // Reload payment, Meta and webhook fields from the chosen site's row.
                        $this->settingId = (int) $state;
                        $this->mount(app(AiSettingsService::class));
                    })
                    ->helperText(__('Payment gateway, Meta pixel and website webhook settings belong to one site. Pick the site you are editing; WooCommerce and the AI tools are shared by the company.'))
                    ->columnSpanFull(),
                Tabs::make('integrations')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('AI Integration')
                            ->icon(Heroicon::OutlinedSparkles)
                            ->visible(fn (): bool => $this->canManageAi())
                            ->schema([
                                Placeholder::make('ai_integration_note')
                                    ->hiddenLabel()
                                    ->content('Each AI-powered tool below can be pointed at its own provider/model — pick a fast cheap model for chat replies, a stronger one for ad strategy, a creative-writing one for landing pages, or the same provider everywhere.')
                                    ->columnSpanFull(),
                                Tabs::make('ai_tools')
                                    ->columnSpanFull()
                                    ->tabs([
                                        Tab::make('Auto Messaging')
                                            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                                            ->schema($this->aiToolFields('ai_messaging_', 'Enable AI auto-reply for this company', true))
                                            ->columns(2),
                                        Tab::make('Ad Assistant')
                                            ->icon(Heroicon::OutlinedMegaphone)
                                            ->schema($this->aiToolFields('ai_ad_assistant_', 'Enable AI for Meta Ads recommendations', false))
                                            ->columns(2),
                                        Tab::make('Landing Page Builder')
                                            ->icon(Heroicon::OutlinedRectangleStack)
                                            ->schema($this->aiToolFields('ai_landing_page_', 'Enable AI for offer landing page generation', false))
                                            ->columns(2),
                                    ]),
                            ]),

                        Tab::make('WooCommerce')
                            ->icon(Heroicon::OutlinedShoppingBag)
                            ->visible(fn (): bool => $this->editingPrimarySite())
                            ->schema([
                                TextInput::make('woocommerce_base_url')
                                    ->label('WooCommerce site URL')
                                    ->url()
                                    ->maxLength(255)
                                    ->placeholder('https://zamzamgadgetbd.com')
                                    ->helperText('Root URL of the WooCommerce site. Do not include /wp-json.')
                                    ->columnSpanFull(),
                                TextInput::make('woocommerce_credentials.consumer_key')
                                    ->label('Consumer key')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(255),
                                TextInput::make('woocommerce_credentials.consumer_secret')
                                    ->label('Consumer secret')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(255),
                                Placeholder::make('woocommerce_note')
                                    ->hiddenLabel()
                                    ->content('Save credentials here, then use "Sync WooCommerce" below to pull products.')
                                    ->columnSpanFull(),
                                TextInput::make('woocommerce_credentials.webhook_secret')
                                    ->label('Order webhook secret')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(255)
                                    ->suffixAction(
                                        Action::make('generateWoocommerceWebhookSecret')
                                            ->icon(Heroicon::ArrowPath)
                                            ->action(fn (Set $set) => $set('woocommerce_credentials.webhook_secret', Str::random(40))),
                                    )
                                    ->helperText('Must match exactly what you paste as the Secret when creating the webhook in WooCommerce.')
                                    ->columnSpanFull(),
                                Placeholder::make('woocommerce_webhook_url')
                                    ->label('Webhook delivery URL')
                                    ->content(fn (): string => $this->companyId ? route('woocommerce.webhook', $this->companyId) : 'Save the company first.')
                                    ->columnSpanFull(),
                                Placeholder::make('woocommerce_webhook_note')
                                    ->hiddenLabel()
                                    ->content('Order sync (WooCommerce → ERP) uses a webhook, not the import button below: in WordPress go to WooCommerce → Settings → Advanced → Webhooks → Add webhook. Set Topic to "Order updated" (it covers created/updated/deleted), Delivery URL to the URL above, and Secret to the same secret set above — the two must match exactly.')
                                    ->columnSpanFull(),
                                SchemaActions::make([
                                    $this->testWoocommerceWebhookAction(),
                                    $this->syncWooOrderAction(),
                                    $this->syncWooCommerceImportAction(),
                                ])
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Tab::make('Website API')
                            ->icon(Heroicon::OutlinedGlobeAlt)
                            ->schema([
                                Placeholder::make('website_api_note')
                                    ->hiddenLabel()
                                    ->content('Lets an external company website sync products/stock, submit orders and inquiries, and read order status. See docs/api/website-integration.md for the full contract.')
                                    ->columnSpanFull(),
                                SchemaLivewire::make(WebsiteApiKeysTable::class)
                                    ->key('websiteApiKeysTable')
                                    ->columnSpanFull(),
                                TextInput::make('website_api_credentials.webhook_url')
                                    ->label('Webhook delivery URL')
                                    ->url()
                                    ->maxLength(255)
                                    ->helperText('Where ZamZam ERP sends order status and stock/price change notifications.')
                                    ->columnSpanFull(),
                                TextInput::make('website_api_credentials.webhook_secret')
                                    ->label('Webhook signing secret')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(255)
                                    ->suffixAction(
                                        Action::make('generateWebsiteWebhookSecret')
                                            ->icon(Heroicon::ArrowPath)
                                            ->action(fn (Set $set) => $set('website_api_credentials.webhook_secret', Str::random(40))),
                                    )
                                    ->helperText('Give this to your website developer to verify the X-ZamZam-Signature header.')
                                    ->columnSpanFull(),
                                Toggle::make('website_api_credentials.is_enabled')
                                    ->label('Send webhooks')
                                    ->helperText('Off by default — turn on once the webhook URL and secret above are saved and your website is ready to receive them.')
                                    ->columnSpanFull(),
                                SchemaActions::make([$this->testWebsiteWebhookAction()])
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Payment Gateway')
                            ->icon(Heroicon::OutlinedCreditCard)
                            ->schema([
                                Toggle::make('online_payment_enabled')
                                    ->label('Enable online payments')
                                    ->helperText('Turn on only after the selected gateway\'s credentials below are set.')
                                    ->columnSpanFull(),
                                Select::make('online_payment_gateway')
                                    ->label('Active gateway')
                                    ->options(['zinipay' => 'ZiniPay', 'paystation' => 'PayStation'])
                                    ->default('zinipay')
                                    ->required()
                                    ->native(false)
                                    ->live(),
                                TextInput::make('payment_credentials.zinipay_api_key')
                                    ->label('ZiniPay API key')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(255)
                                    ->visible(fn (Get $get): bool => $get('online_payment_gateway') === 'zinipay'),
                                TextInput::make('payment_credentials.zinipay_base_url')
                                    ->label('ZiniPay base URL')
                                    ->url()
                                    ->maxLength(255)
                                    ->placeholder(ZiniPayClient::DEFAULT_BASE_URL)
                                    ->helperText('Leave empty for the default. Change only if ZiniPay gives you a different API host.')
                                    ->visible(fn (Get $get): bool => $get('online_payment_gateway') === 'zinipay'),
                                TextInput::make('payment_credentials.paystation_merchant_id')
                                    ->label('PayStation Merchant ID')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(255)
                                    ->helperText('This company\'s own PayStation MID — do not reuse another company\'s or website\'s MID.')
                                    ->visible(fn (Get $get): bool => $get('online_payment_gateway') === 'paystation'),
                                TextInput::make('payment_credentials.paystation_password')
                                    ->label('PayStation Password / API key')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(255)
                                    ->visible(fn (Get $get): bool => $get('online_payment_gateway') === 'paystation'),
                                TextInput::make('payment_credentials.paystation_base_url')
                                    ->label('PayStation base URL')
                                    ->url()
                                    ->maxLength(255)
                                    ->placeholder(PayStationClient::DEFAULT_BASE_URL)
                                    ->helperText('Leave empty for the default. Change only if PayStation gives you a different API host.')
                                    ->visible(fn (Get $get): bool => $get('online_payment_gateway') === 'paystation'),
                                Placeholder::make('payment_note')
                                    ->hiddenLabel()
                                    ->content('Each company must use its own gateway merchant account — never reuse another company\'s or website\'s credentials (violates most BD payment gateway terms and can trigger account suspension). Manual bKash/Nagad numbers for Offer-page checkouts are on Storefront Settings → Checkout & Delivery.')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Tab::make(__('SMS Gateway'))
                            ->icon(Heroicon::OutlinedDevicePhoneMobile)
                            ->schema([
                                Placeholder::make('sms_gateway_note')
                                    ->hiddenLabel()
                                    ->content(__('One SMS gateway per site. It sends login OTP codes, password recovery codes, abandoned-cart reminders, and (from the company\'s main site) CRM and lead follow-up SMS.'))
                                    ->columnSpanFull(),
                                TextInput::make('notification_credentials.sms_api_url')
                                    ->label(__('SMS gateway URL template'))
                                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : trim($state))
                                    ->maxLength(500)
                                    ->placeholder('http://bulksmsbd.net/api/smsapi?api_key={api_key}&type=text&number={phone}&senderid={sender_id}&message={message}')
                                    ->helperText(__('Use the {api_key}, {sender_id}, {phone} and {message} placeholders. Leave empty to turn SMS off for this site.'))
                                    ->columnSpanFull(),
                                // new-password keeps the browser from filling the login password into this box (the gateway
                                // then answers "User Id not found"); a space copied with the key is dropped on save.
                                TextInput::make('notification_credentials.sms_api_key')
                                    ->label(__('SMS API key'))
                                    ->password()
                                    ->revealable()
                                    ->autocomplete('new-password')
                                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : trim($state))
                                    ->maxLength(255)
                                    ->helperText(__('Stored encrypted.')),
                                TextInput::make('notification_credentials.sms_sender_id')
                                    ->label(__('SMS sender ID'))
                                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : trim($state))
                                    ->maxLength(100),
                                Placeholder::make('sms_gateway_wording_note')
                                    ->hiddenLabel()
                                    ->content(__('This URL only delivers messages. The wording of each SMS is written on Settings → SMS Templates, and your branding comes from the sender ID and the wording you write there.'))
                                    ->columnSpanFull(),
                                SchemaActions::make([$this->testSmsAction()])
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Tab::make('Meta Pixel & CAPI')
                            ->icon(Heroicon::OutlinedSignal)
                            ->schema([
                                Toggle::make('meta_tracking_enabled')
                                    ->label('Enable Meta tracking')
                                    ->columnSpanFull(),

                                // Nested tabs, same UX as the AI Integration
                                // tab's per-tool Tabs — each section becomes
                                // its own sub-tab instead of a stacked,
                                // collapsible Section.
                                Tabs::make('meta_capi_sections')
                                    ->columnSpanFull()
                                    ->tabs([
                                        Tab::make('Connection & Pixels')
                                            ->icon(Heroicon::OutlinedLink)
                                            ->schema([
                                                Placeholder::make('meta_connection_note')
                                                    ->hiddenLabel()
                                                    ->content('Connect the primary Pixel/Dataset, optional additional destinations, domain verification, and CAPI credentials.')
                                                    ->columnSpanFull(),
                                                TextInput::make('meta_pixel_id')
                                                    ->label('Primary Pixel / Dataset ID')
                                                    ->maxLength(32)
                                                    ->regex('/^\d{5,32}$/'),
                                                Toggle::make('meta_capi_enabled')
                                                    ->label('Server-side events')
                                                    ->live()
                                                    ->helperText('Provider failure never blocks checkout or order operations.'),
                                                TextInput::make('meta_tracking_credentials.access_token')
                                                    ->label('Conversions API access token')
                                                    ->password()
                                                    ->revealable()
                                                    ->maxLength(1000)
                                                    ->helperText('Stored encrypted and kept separate from WhatsApp/Messenger channel tokens.')
                                                    ->visible(fn (Get $get): bool => (bool) $get('meta_capi_enabled')),
                                                TextInput::make('meta_tracking_credentials.test_event_code')
                                                    ->label('Test event code')
                                                    ->maxLength(100)
                                                    ->helperText('Optional. Clear the Events Manager test code before production traffic.')
                                                    ->visible(fn (Get $get): bool => (bool) $get('meta_capi_enabled')),
                                                Repeater::make('meta_tracking_credentials.additional_pixels')
                                                    ->label('Additional Pixels / Datasets')
                                                    ->schema([
                                                        TextInput::make('pixel_id')
                                                            ->label('Pixel / Dataset ID')
                                                            ->required()
                                                            ->regex('/^\d{5,32}$/')
                                                            ->maxLength(32),
                                                        TextInput::make('access_token')
                                                            ->label('CAPI access token')
                                                            ->password()
                                                            ->revealable()
                                                            ->maxLength(1000),
                                                        TextInput::make('test_event_code')
                                                            ->label('Test event code')
                                                            ->maxLength(100),
                                                    ])
                                                    ->columns(3)
                                                    ->addActionLabel('Add Pixel / Dataset')
                                                    ->helperText('The complete list is encrypted with the primary CAPI credentials. A token is optional for browser-only delivery.')
                                                    ->columnSpanFull(),
                                                Repeater::make('meta_domain_verification_tags')
                                                    ->label('Domain verification values')
                                                    ->schema([
                                                        TextInput::make('content')
                                                            ->label('facebook-domain-verification content')
                                                            ->required()
                                                            ->regex('/^[A-Za-z0-9_-]{8,255}$/')
                                                            ->maxLength(255),
                                                    ])
                                                    ->addActionLabel('Add verification value')
                                                    ->helperText('Enter only the content value from Meta, not the full HTML meta tag.')
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(2),

                                        Tab::make('Consent & Advanced Matching')
                                            ->icon(Heroicon::OutlinedShieldCheck)
                                            ->schema([
                                                Placeholder::make('meta_consent_note')
                                                    ->hiddenLabel()
                                                    ->content('Control the storefront consent gate and privacy-minimized authenticated-customer matching.')
                                                    ->columnSpanFull(),
                                                Toggle::make('meta_consent_required')
                                                    ->label('Require customer consent')
                                                    ->live()
                                                    ->helperText('Off by default: Pixel and server events fire for every visitor immediately. Turn on only if local regulation requires an accept/decline choice before measurement — while on, Pixel and server events stay off until each customer accepts.'),
                                                Toggle::make('meta_advanced_matching_enabled')
                                                    ->label('Authenticated-customer advanced matching')
                                                    ->helperText('Initializes each browser Pixel with SHA-256 hashed account identifiers; raw identifiers are never embedded.')
                                                    ->visible(fn (Get $get): bool => (bool) $get('meta_browser_tracking_enabled')),
                                                Textarea::make('meta_consent_message')
                                                    ->label('Consent message')
                                                    ->rows(3)
                                                    ->maxLength(500)
                                                    ->placeholder('We use Meta measurement cookies to understand storefront activity and improve advertising.')
                                                    ->columnSpanFull()
                                                    ->visible(fn (Get $get): bool => (bool) $get('meta_consent_required')),
                                            ])
                                            ->columns(2),

                                        Tab::make('Browser Events')
                                            ->icon(Heroicon::OutlinedCursorArrowRays)
                                            ->schema([
                                                Placeholder::make('meta_browser_events_note')
                                                    ->hiddenLabel()
                                                    ->content('Choose standard storefront funnel events and optional selector-based behavioural events.')
                                                    ->columnSpanFull(),
                                                Toggle::make('meta_browser_tracking_enabled')
                                                    ->label('Browser Pixel events')
                                                    ->live()
                                                    ->helperText('Tracks only the selected storefront events after consent, when consent is required.'),
                                                CheckboxList::make('meta_browser_events')
                                                    ->label('Browser events to send')
                                                    ->options(StorefrontMetaTrackingService::BROWSER_EVENTS)
                                                    ->columns(2)
                                                    ->columnSpanFull()
                                                    ->visible(fn (Get $get): bool => (bool) $get('meta_browser_tracking_enabled')),
                                                Toggle::make('meta_custom_events_enabled')
                                                    ->label('Custom behavioural events')
                                                    ->live()
                                                    ->helperText('Use stable CSS selectors that exist on the storefront.')
                                                    ->visible(fn (Get $get): bool => (bool) $get('meta_browser_tracking_enabled')),
                                                Repeater::make('meta_custom_events')
                                                    ->label('Link, click, visibility, and time events')
                                                    ->schema([
                                                        Select::make('type')
                                                            ->options(StorefrontMetaTrackingService::CUSTOM_EVENT_TYPES)
                                                            ->required()
                                                            ->live(),
                                                        TextInput::make('event_name')
                                                            ->label('Meta custom event name')
                                                            ->required()
                                                            ->regex('/^[A-Za-z][A-Za-z0-9_]{0,49}$/')
                                                            ->maxLength(50),
                                                        TextInput::make('selector')
                                                            ->label('CSS selector')
                                                            ->required(fn (Get $get): bool => in_array($get('type'), ['link', 'click', 'scroll'], true))
                                                            ->maxLength(255)
                                                            ->placeholder('#whatsapp-button')
                                                            ->visible(fn (Get $get): bool => in_array($get('type'), ['link', 'click', 'scroll'], true)),
                                                        TextInput::make('seconds')
                                                            ->label('Seconds on page')
                                                            ->numeric()
                                                            ->integer()
                                                            ->minValue(1)
                                                            ->maxValue(3600)
                                                            ->default(10)
                                                            ->visible(fn (Get $get): bool => $get('type') === 'time'),
                                                    ])
                                                    ->columns(2)
                                                    ->addActionLabel('Add custom event')
                                                    ->reorderable()
                                                    ->columnSpanFull()
                                                    ->visible(fn (Get $get): bool => (bool) $get('meta_browser_tracking_enabled')
                                                        && (bool) $get('meta_custom_events_enabled')),
                                            ]),

                                        Tab::make('Purchase Delivery')
                                            ->icon(Heroicon::OutlinedShoppingCart)
                                            ->schema([
                                                Placeholder::make('meta_purchase_note')
                                                    ->hiddenLabel()
                                                    ->content('Choose when the deduplicated Purchase event is eligible for server delivery.')
                                                    ->columnSpanFull(),
                                                Select::make('meta_purchase_timing')
                                                    ->label('Purchase event timing')
                                                    ->options(StorefrontMetaTrackingService::PURCHASE_TIMINGS)
                                                    ->required()
                                                    ->live()
                                                    ->helperText('Delayed Purchase retains encrypted attribution only until successful server delivery.'),
                                                TextInput::make('meta_purchase_success_ratio_threshold')
                                                    ->label('Trusted courier success ratio')
                                                    ->numeric()
                                                    ->integer()
                                                    ->minValue(0)
                                                    ->maxValue(100)
                                                    ->suffix('%')
                                                    ->visible(fn (Get $get): bool => $get('meta_purchase_timing') === 'risk_aware'),
                                            ])
                                            ->columns(2),

                                        Tab::make('Order Status Events')
                                            ->icon(Heroicon::OutlinedArrowsRightLeft)
                                            ->schema([
                                                Placeholder::make('meta_status_events_note')
                                                    ->hiddenLabel()
                                                    ->content('Send selected committed order lifecycle changes as privacy-minimized custom server events.')
                                                    ->columnSpanFull(),
                                                Toggle::make('meta_status_events_enabled')
                                                    ->label('Order status events')
                                                    ->live()
                                                    ->helperText('Events use hashed customer identifiers and never inherit the admin browser session.'),
                                                CheckboxList::make('meta_status_events')
                                                    ->label('Lifecycle events to send')
                                                    ->options(StorefrontMetaTrackingService::STATUS_EVENTS)
                                                    ->columns(2)
                                                    ->columnSpanFull()
                                                    ->visible(fn (Get $get): bool => (bool) $get('meta_status_events_enabled')),
                                            ]),

                                        Tab::make('Event Log & Retries')
                                            ->icon(Heroicon::OutlinedQueueList)
                                            // Gated here, not just inside
                                            // MetaEventLogTable::mount()'s
                                            // own abort_unless — a settings
                                            // manager without sales.view
                                            // must never even have the
                                            // widget attempt to mount, or
                                            // its 403 corrupts this whole
                                            // page's render (embedding a
                                            // Livewire component is not the
                                            // same as a plain hidden field).
                                            ->visible(fn (): bool => Auth::user()?->hasPermission('sales.view') ?? false)
                                            ->schema([
                                                Placeholder::make('meta_event_log_note')
                                                    ->hiddenLabel()
                                                    ->content('Inspect privacy-minimized per-Pixel delivery attempts and queue a retry for failed or pending events.')
                                                    ->columnSpanFull(),
                                                SchemaLivewire::make(MetaEventLogTable::class)
                                                    ->key('metaEventLogTable'),
                                            ]),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public function save(AiSettingsService $aiSettings): void
    {
        $company = $this->selectedCompany();
        $state = $this->form->getState();

        if ($this->canManageAi()) {
            foreach (AiSettingsService::TOOLS as $tool => $label) {
                $prefix = "ai_{$tool}_";

                $aiSettings->save($company, $tool, [
                    'enabled' => $state["{$prefix}enabled"] ?? false,
                    'api_format' => $state["{$prefix}api_format"] ?? null,
                    'provider' => $state["{$prefix}provider"] ?? null,
                    'base_url' => $state["{$prefix}base_url"] ?? null,
                    'model' => $state["{$prefix}model"] ?? null,
                    'confidence_threshold' => $state["{$prefix}confidence_threshold"] ?? null,
                    'max_consecutive_ai_replies' => $state["{$prefix}max_consecutive_ai_replies"] ?? null,
                    'brand_voice' => $state["{$prefix}brand_voice"] ?? null,
                    'sales_guidelines' => $state["{$prefix}sales_guidelines"] ?? null,
                    ...collect([
                        'vision_enabled', 'image_model_enabled', 'image_api_format', 'image_provider', 'image_base_url', 'image_model',
                        'voice_enabled', 'voice_provider', 'voice_base_url', 'voice_model',
                        'review_mode', 'daily_run_limit', 'max_run_tokens', 'daily_budget_usd', 'input_cost_per_million', 'output_cost_per_million', 'sales_follow_ups_enabled', 'follow_up_schedule_hours', 'follow_up_weekly_count', 'follow_up_quiet_start_hour', 'follow_up_quiet_end_hour', 'follow_up_message', 'follow_up_template', 'follow_up_template_language',
                        'proactive_mode_enabled', 'objection_handling_enabled', 'cross_sell_enabled', 'personalization_enabled',
                        'takeover_minutes', 'reply_delay_seconds', 'handover_notice_enabled', 'rich_replies_enabled', 'address_style',
                        'response_target_minutes', 'ai_live', 'typing_indicator_enabled', 'product_cards_enabled',
                    ])->mapWithKeys(fn ($key) => [$key => $state[$prefix.$key] ?? null])->all(),
                    'api_key' => $state["{$prefix}api_key"] ?? null,
                    'image_api_key' => $state["{$prefix}image_api_key"] ?? null,
                    'voice_api_key' => $state["{$prefix}voice_api_key"] ?? null,
                ]);
            }
        }

        $active = $this->activeSetting();

        // notification_credentials also holds mail/WhatsApp keys edited on Storefront Settings; only touch the SMS ones.
        $state['notification_credentials'] = array_merge(
            (array) $active?->notification_credentials,
            Arr::only((array) ($state['notification_credentials'] ?? []), StorefrontNotificationService::SMS_CREDENTIAL_KEYS),
        );

        StorefrontSetting::withoutGlobalScopes()->updateOrCreate(
            $active
                ? ['id' => $active->getKey(), 'company_id' => $company->getKey()]
                : ['company_id' => $company->getKey(), 'site_type' => StorefrontSetting::SITE_BLADE],
            Arr::only($state, [
                'notification_credentials',
                'woocommerce_base_url',
                'woocommerce_credentials',
                'website_api_credentials',
                'online_payment_enabled',
                'online_payment_gateway',
                'payment_credentials',
                ...$this->metaFields(),
            ]),
        );

        // Re-fill from the fresh, now-persisted state — never keeps the
        // plaintext API keys in the browser past a save.
        $this->mount($aiSettings);

        Notification::make()
            ->title('Integration settings saved')
            ->success()
            ->send();
    }

    protected function selectedCompany(): Company
    {
        $contextCompany = app(CompanyContext::class)->company();

        abort_unless($contextCompany !== null, 404, 'Select a company before opening Integrations.');

        if ($this->companyId !== null) {
            abort_unless(
                (int) $contextCompany->getKey() === $this->companyId,
                409,
                'The selected company changed. Reload Integrations before saving.',
            );
        }

        $user = Auth::user();
        abort_unless($user?->canManageSettings() && $user->canAccessCompany((int) $contextCompany->getKey()), 403);

        return $contextCompany;
    }
}
