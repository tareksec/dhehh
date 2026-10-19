<?php

namespace App\Services\Crm;

use App\Models\AiInstructionVersion;
use App\Models\Company;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Per-company, per-tool AI settings, stored in the existing `companies.settings`
 * json column under `ai_tools.{tool}`. Each of the tools below can be pointed at
 * its own provider/model/API key — a fast cheap model for chat replies, a
 * stronger reasoning model for ad strategy, a creative-writing model for
 * landing-page copy, etc. — instead of sharing one global config. The API key
 * is encrypted at rest — admin-configurable, never hardcoded (CLAUDE.md rule).
 *
 * `provider` is a free-text label only (any name the admin wants — "OpenAI",
 * "DeepSeek", "Groq", "OpenRouter", "My local Ollama", ...). What actually
 * decides how the HTTP request is shaped is `api_format`: 'anthropic' (the
 * Messages API) or 'openai' (the Chat Completions shape, which nearly every
 * non-Anthropic LLM provider today speaks — OpenAI itself, DeepSeek, Groq,
 * Mistral, OpenRouter, xAI, self-hosted Ollama/vLLM, etc.). `base_url` lets
 * the admin point that request at any endpoint; left blank it defaults to
 * that format's own official endpoint. This is how any new provider gets
 * added without a code change: pick "OpenAI-compatible", paste that
 * provider's own base URL, model name, and API key.
 *
 * Migration from the pre-per-tool single global config (`settings->ai`): when
 * a given tool has never been saved under `ai_tools.{tool}`, all() falls back
 * (in-memory only, nothing written back) to that legacy blob, so every tool
 * reads the same config that used to be shared until an admin explicitly
 * saves that tool through the new per-tool UI — at which point only that
 * tool's own entry is used going forward.
 */
class AiSettingsService
{
    public const TOOL_MESSAGING = 'messaging';

    public const TOOL_AD_ASSISTANT = 'ad_assistant';

    public const TOOL_LANDING_PAGE = 'landing_page';

    /** @var array<string, string> */
    public const TOOLS = [
        self::TOOL_MESSAGING => 'Auto Messaging (Inbox AI Reply)',
        self::TOOL_AD_ASSISTANT => 'Ad Assistant (Meta Ads)',
        self::TOOL_LANDING_PAGE => 'Landing Page Builder (Offer Pages)',
    ];

    public const DEFAULTS = [
        'enabled' => false,
        'provider' => 'Anthropic (Claude)', // free-text label, shown in the UI/audit trail only
        'api_format' => 'anthropic', // anthropic | openai — the actual request/response wire format
        'base_url' => '', // optional endpoint override; blank = api_format's own default endpoint
        'model' => 'claude-haiku-4-5-20251001',
        'confidence_threshold' => 0.75,
        'max_consecutive_ai_replies' => 3,
        'brand_voice' => '',
        'review_mode' => false,
        'vision_enabled' => false,
        // A separate model just for reading customer photos (messaging tool
        // only). Off by default: vision_enabled alone keeps sending the raw
        // image inline to the main chat model above, which must itself be
        // vision-capable. Turning this on instead describes the photo with
        // its own model/provider first, and only that plain-text description
        // (never the image bytes) reaches the main tool-calling model.
        'image_model_enabled' => false,
        'image_api_format' => 'anthropic',
        'image_provider' => 'Anthropic (Claude)',
        'image_base_url' => '',
        'image_model' => 'claude-haiku-4-5-20251001',
        // Voice-note transcription (messaging tool only). Only the
        // OpenAI-compatible /audio/transcriptions (Whisper) shape is
        // supported — the one wire format nearly every STT provider
        // (OpenAI, Groq, a self-hosted whisper.cpp server, ...) speaks.
        'voice_enabled' => false,
        'voice_provider' => 'OpenAI (Whisper)',
        'voice_base_url' => '',
        'voice_model' => 'whisper-1',
        'daily_run_limit' => 300,
        'max_run_tokens' => 20000,
        'daily_budget_usd' => 0,
        'input_cost_per_million' => 0,
        'output_cost_per_million' => 0,
        'sales_follow_ups_enabled' => false,
        // Reminders after the customer's last message, in hours, then one a
        // week for follow_up_weekly_count weeks (owner answer O4, 2026-10-19).
        'follow_up_schedule_hours' => '1, 6, 24, 48',
        'follow_up_weekly_count' => 4,
        // No reminder goes out from start hour until end hour (company time).
        'follow_up_quiet_start_hour' => 22,
        'follow_up_quiet_end_hour' => 8,
        // Text for Messenger and Instagram reminders (they have no templates).
        // {link} becomes the order link. Empty: the team does those reminders.
        'follow_up_message' => '',
        'follow_up_template' => '',
        'follow_up_template_language' => 'bn',
        // Off by default: the agent answers only what was asked, with no
        // extra follow-up question. On: after a fully-answered question it
        // may also pick one next-step clarification to move the sale along.
        'proactive_mode_enabled' => false,
        // Off by default: discount/bargaining language always hard-escalates
        // (see AiReplyService::OBJECTION_KEYWORDS). On: a mild price
        // objection is handed to the agent, which may restate value or ask a
        // clarifying question, grounded only in real catalog facts/FAQs —
        // it can never invent or apply a discount.
        'objection_handling_enabled' => false,
        // Off by default: lookup_related_products is not even offered to
        // the model as a tool. On: the agent may suggest one same-category
        // related product, grounded in a real catalog lookup.
        'cross_sell_enabled' => false,
        // Off by default: SalesQualificationService::context() keeps its
        // existing shape. On: it also surfaces a returning-customer signal
        // and past order line items so the agent can personalize tone
        // ("welcome back") without inventing purchase history.
        'personalization_enabled' => false,
        // AI inbox control plane (16_AI_INBOX_CONTROL_PLANE_PLAN.md). How
        // long a chat stays with the team after someone takes it over or the
        // AI hands it over, before it returns to the AI on its own. 6 hours
        // is AiPraktor's default for a new page; the owner changes it here.
        'takeover_minutes' => 360,
        // Seconds to wait after a customer message before the AI answers, so
        // two quick messages get one combined reply (0-15).
        'reply_delay_seconds' => 3,
        // Off by default: on handover the customer is not told anything. On:
        // one short "a colleague is looking into this" message is sent.
        'handover_notice_enabled' => false,
        // Off by default: compact one-line facts. On: product facts on their
        // own lines with small labels (price, sizes, stock, delivery).
        'rich_replies_enabled' => false,
        // none | sir_maam | bhaiya_apu — how the AI addresses the customer
        // in Bangla replies.
        'address_style' => 'none',
        // Minutes a chat the AI handed over may wait for a person's first
        // reply before the Needs attention page marks it late. 30 is the
        // owner's answer (O2, 2026-10-19), around the clock; 0 = no target
        // (nothing is marked late).
        'response_target_minutes' => 30,
        // What the business is, for the agent's background only (Agent Desk).
        'business_about' => '',
    ];

    public const ADDRESS_STYLES = ['none', 'sir_maam', 'bhaiya_apu'];

    public function all(Company $company, string $tool): array
    {
        $stored = (array) data_get($company->settings, "ai_tools.{$tool}", []);

        if ($stored === []) {
            $stored = $this->legacyGlobalSettings($company) ?? [];
        }

        $settings = array_merge(self::DEFAULTS, $stored);
        $settings['sales_guidelines'] = $stored['sales_guidelines'] ?? ($tool === self::TOOL_MESSAGING ? $this->defaultSalesGuidelines() : '');
        $settings['api_key'] = $this->decrypt($stored['api_key'] ?? null);
        $settings['image_api_key'] = $this->decrypt($stored['image_api_key'] ?? null);
        $settings['voice_api_key'] = $this->decrypt($stored['voice_api_key'] ?? null);

        return $settings;
    }

    public function save(Company $company, string $tool, array $data): void
    {
        $settings = (array) $company->settings;
        $existing = (array) data_get($settings, "ai_tools.{$tool}", []);
        $instructionsBefore = $tool === self::TOOL_MESSAGING ? $this->instructionBlocks($company) : null;

        $settings['ai_tools'][$tool] = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'provider' => trim((string) ($data['provider'] ?? '')) ?: self::DEFAULTS['provider'],
            'api_format' => in_array($data['api_format'] ?? '', ['anthropic', 'openai'], true)
                ? $data['api_format']
                : self::DEFAULTS['api_format'],
            'base_url' => trim((string) ($data['base_url'] ?? '')),
            'model' => trim((string) ($data['model'] ?? self::DEFAULTS['model'])) ?: self::DEFAULTS['model'],
            'confidence_threshold' => min(max((float) ($data['confidence_threshold'] ?? 0.75), 0), 1),
            'max_consecutive_ai_replies' => max((int) ($data['max_consecutive_ai_replies'] ?? 3), 1),
            'brand_voice' => trim((string) ($data['brand_voice'] ?? '')),
            'sales_guidelines' => mb_substr(trim((string) ($data['sales_guidelines'] ?? $existing['sales_guidelines'] ?? ($tool === self::TOOL_MESSAGING ? $this->defaultSalesGuidelines() : ''))), 0, 16000),
            'review_mode' => (bool) ($data['review_mode'] ?? $existing['review_mode'] ?? false),
            'vision_enabled' => (bool) ($data['vision_enabled'] ?? $existing['vision_enabled'] ?? false),
            'image_model_enabled' => (bool) ($data['image_model_enabled'] ?? $existing['image_model_enabled'] ?? false),
            'image_api_format' => in_array($data['image_api_format'] ?? '', ['anthropic', 'openai'], true)
                ? $data['image_api_format']
                : self::DEFAULTS['image_api_format'],
            'image_provider' => trim((string) ($data['image_provider'] ?? '')) ?: self::DEFAULTS['image_provider'],
            'image_base_url' => trim((string) ($data['image_base_url'] ?? '')),
            'image_model' => trim((string) ($data['image_model'] ?? self::DEFAULTS['image_model'])) ?: self::DEFAULTS['image_model'],
            'image_api_key' => filled($data['image_api_key'] ?? null)
                ? Crypt::encryptString(trim((string) $data['image_api_key']))
                : ($existing['image_api_key'] ?? null),
            'voice_enabled' => (bool) ($data['voice_enabled'] ?? $existing['voice_enabled'] ?? false),
            'voice_provider' => trim((string) ($data['voice_provider'] ?? '')) ?: self::DEFAULTS['voice_provider'],
            'voice_base_url' => trim((string) ($data['voice_base_url'] ?? '')),
            'voice_model' => trim((string) ($data['voice_model'] ?? self::DEFAULTS['voice_model'])) ?: self::DEFAULTS['voice_model'],
            'voice_api_key' => filled($data['voice_api_key'] ?? null)
                ? Crypt::encryptString(trim((string) $data['voice_api_key']))
                : ($existing['voice_api_key'] ?? null),
            'daily_run_limit' => max(1, min(10000, (int) ($data['daily_run_limit'] ?? $existing['daily_run_limit'] ?? 300))),
            'max_run_tokens' => max(3000, min(50000, (int) ($data['max_run_tokens'] ?? $existing['max_run_tokens'] ?? 20000))),
            'daily_budget_usd' => max(0, (float) ($data['daily_budget_usd'] ?? $existing['daily_budget_usd'] ?? 0)),
            'input_cost_per_million' => max(0, (float) ($data['input_cost_per_million'] ?? $existing['input_cost_per_million'] ?? 0)),
            'output_cost_per_million' => max(0, (float) ($data['output_cost_per_million'] ?? $existing['output_cost_per_million'] ?? 0)),
            'sales_follow_ups_enabled' => (bool) ($data['sales_follow_ups_enabled'] ?? $existing['sales_follow_ups_enabled'] ?? false),
            'follow_up_schedule_hours' => implode(', ', self::followUpHours((string) ($data['follow_up_schedule_hours'] ?? $existing['follow_up_schedule_hours'] ?? self::DEFAULTS['follow_up_schedule_hours']))),
            'follow_up_weekly_count' => max(0, min(12, (int) ($data['follow_up_weekly_count'] ?? $existing['follow_up_weekly_count'] ?? 4))),
            'follow_up_quiet_start_hour' => max(0, min(23, (int) ($data['follow_up_quiet_start_hour'] ?? $existing['follow_up_quiet_start_hour'] ?? 22))),
            'follow_up_quiet_end_hour' => max(0, min(23, (int) ($data['follow_up_quiet_end_hour'] ?? $existing['follow_up_quiet_end_hour'] ?? 8))),
            'follow_up_message' => trim(mb_substr((string) ($data['follow_up_message'] ?? $existing['follow_up_message'] ?? ''), 0, 900)),
            'follow_up_template' => trim((string) ($data['follow_up_template'] ?? $existing['follow_up_template'] ?? '')),
            'follow_up_template_language' => trim((string) ($data['follow_up_template_language'] ?? $existing['follow_up_template_language'] ?? 'bn')),
            'proactive_mode_enabled' => (bool) ($data['proactive_mode_enabled'] ?? $existing['proactive_mode_enabled'] ?? false),
            'objection_handling_enabled' => (bool) ($data['objection_handling_enabled'] ?? $existing['objection_handling_enabled'] ?? false),
            'cross_sell_enabled' => (bool) ($data['cross_sell_enabled'] ?? $existing['cross_sell_enabled'] ?? false),
            'personalization_enabled' => (bool) ($data['personalization_enabled'] ?? $existing['personalization_enabled'] ?? false),
            'takeover_minutes' => max(30, min(1440, (int) ($data['takeover_minutes'] ?? $existing['takeover_minutes'] ?? self::DEFAULTS['takeover_minutes']))),
            'reply_delay_seconds' => max(0, min(15, (int) ($data['reply_delay_seconds'] ?? $existing['reply_delay_seconds'] ?? self::DEFAULTS['reply_delay_seconds']))),
            'handover_notice_enabled' => (bool) ($data['handover_notice_enabled'] ?? $existing['handover_notice_enabled'] ?? false),
            'rich_replies_enabled' => (bool) ($data['rich_replies_enabled'] ?? $existing['rich_replies_enabled'] ?? false),
            'address_style' => in_array($data['address_style'] ?? $existing['address_style'] ?? 'none', self::ADDRESS_STYLES, true)
                ? ($data['address_style'] ?? $existing['address_style'] ?? 'none')
                : 'none',
            'response_target_minutes' => max(0, min(1440, (int) ($data['response_target_minutes'] ?? $existing['response_target_minutes'] ?? self::DEFAULTS['response_target_minutes']))),
            'business_about' => mb_substr(trim((string) ($data['business_about'] ?? $existing['business_about'] ?? '')), 0, 4000),
            'api_key' => filled($data['api_key'] ?? null)
                ? Crypt::encryptString(trim((string) $data['api_key']))
                : ($existing['api_key'] ?? null), // keep existing when left blank
        ];

        $company->forceFill(['settings' => $settings])->save();

        if ($instructionsBefore !== null) {
            app(AiInstructionService::class)->recordSettingsChange($company, $instructionsBefore, $this->instructionBlocks($company), auth()->user());
        }
    }

    /** The owner's written guidance as stored in the settings: about, voice, guidelines. */
    public function instructionBlocks(Company $company): array
    {
        $all = $this->all($company, self::TOOL_MESSAGING);
        $blocks = [];
        foreach (AiInstructionVersion::BLOCKS as $block => $key) {
            $blocks[$block] = (string) ($all[$key] ?? '');
        }

        return AiInstructionVersion::normalize($blocks);
    }

    /** Write the guidance blocks straight into the settings (publishing from the Agent Desk). */
    public function setInstructions(Company $company, array $blocks): void
    {
        $blocks = AiInstructionVersion::normalize($blocks);
        $settings = (array) $company->settings;
        foreach (AiInstructionVersion::BLOCKS as $block => $key) {
            $settings['ai_tools'][self::TOOL_MESSAGING][$key] = mb_substr($blocks[$block], 0, match ($block) {
                'about' => 4000,
                'voice' => 2000,
                default => 16000,
            });
        }
        $company->forceFill(['settings' => $settings])->save();
    }

    public function enabled(Company $company, string $tool): bool
    {
        $settings = $this->all($company, $tool);

        return $settings['enabled'] && filled($settings['api_key']);
    }

    public function defaultSalesGuidelines(): string
    {
        return trim(file_get_contents(resource_path('ai/sales-agent-guidelines.bn.md')));
    }

    /**
     * The old single shared `settings->ai` blob, still passed through the
     * pre-existing provider-enum migration below so a company that never
     * touched AI settings since before *that* upgrade keeps working too.
     * Returns null when nothing was ever configured at all.
     */
    protected function legacyGlobalSettings(Company $company): ?array
    {
        $stored = (array) data_get($company->settings, 'ai', []);

        if ($stored === []) {
            return null;
        }

        return $this->migrateLegacySettings($stored);
    }

    /**
     * Back-compat for settings saved before the provider list became
     * flexible, when `provider` was a closed 'anthropic'|'openai'|'deepseek'
     * enum that also doubled as the wire format. Maps those forward to the
     * new provider label + api_format + base_url shape so companies
     * configured before this upgrade keep working unchanged.
     */
    protected function migrateLegacySettings(array $stored): array
    {
        if (! isset($stored['provider']) || isset($stored['api_format'])) {
            return $stored;
        }

        return array_merge($stored, match ($stored['provider']) {
            'openai' => ['provider' => 'OpenAI', 'api_format' => 'openai', 'base_url' => ''],
            'deepseek' => ['provider' => 'DeepSeek', 'api_format' => 'openai', 'base_url' => 'https://api.deepseek.com/chat/completions'],
            default => ['provider' => 'Anthropic (Claude)', 'api_format' => 'anthropic', 'base_url' => ''],
        });
    }

    protected function decrypt(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * "1, 6, 24, 48" as whole hours, rising, 1 to 720. Falls back to the
     * default when nothing usable is left, so a typo cannot switch the
     * reminders off by accident.
     *
     * @return list<int>
     */
    public static function followUpHours(string $value): array
    {
        $hours = collect(preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $hour): int => (int) $hour)
            ->filter(fn (int $hour): bool => $hour >= 1 && $hour <= 720)
            ->unique()->sort()->values()->all();

        return $hours !== [] ? $hours : array_map('intval', explode(',', self::DEFAULTS['follow_up_schedule_hours']));
    }
}
